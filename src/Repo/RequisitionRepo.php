<?php
declare(strict_types=1);

namespace App\Repo;

use App\Db;
use App\Auth;
use App\Storage;
use App\Perm;
use App\Support\Filter;

final class RequisitionRepo
{
    /** File types accepted as a quotation attachment. */
    public const ATTACH_EXTS = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
    public const ATTACH_MAX_BYTES = 15 * 1024 * 1024;

    /** True when this MR number is already used (optionally ignoring one row, for edits). */
    public static function mrNumberTaken(string $mrNumber, ?int $ignoreId = null): bool
    {
        $sql = "SELECT COUNT(*) FROM requisitions WHERE mr_number = ?";
        $p   = [trim($mrNumber)];
        if ($ignoreId !== null) { $sql .= " AND id <> ?"; $p[] = $ignoreId; }
        return (int)Db::scalar($sql, $p) > 0;
    }

    /**
     * The next free MR number, for prefilling the form.
     *
     * mr_number is free text (some are entered by hand from the paper form), so
     * only the purely numeric ones can be counted on. Non-numeric numbers are
     * ignored rather than guessed at.
     */
    public static function nextMrNumber(): string
    {
        $max = 0;
        foreach (Db::all("SELECT mr_number FROM requisitions") as $r) {
            $n = trim((string)$r['mr_number']);
            if (ctype_digit($n)) $max = max($max, (int)$n);
        }
        $next = $max + 1;
        while (self::mrNumberTaken((string)$next)) $next++;
        return (string)$next;
    }

    public static function find(int $id): ?array
    {
        return Db::one(
            "SELECT r.*, p.name AS project_name, p.project_code
             FROM requisitions r JOIN projects p ON p.id = r.project_id WHERE r.id = ?",
            [$id]
        );
    }

    /**
     * Requisitions, newest first, optionally filtered.
     * $f: q (MR no / requester / project), status, project_id, from, to (request_date).
     */
    public static function all(array $f = []): array
    {
        [$where, $args] = Filter::build([
            Filter::projectScope(Perm::projectIds(), 'r.project_id'),
            Filter::search($f['q'] ?? '', ['r.mr_number', 'r.requested_by', 'p.project_code', 'p.name']),
            Filter::equals('r.status', $f['status'] ?? ''),
            Filter::equals('r.project_id', $f['project_id'] ?? ''),
            Filter::dateFrom('r.request_date', $f['from'] ?? ''),
            Filter::dateTo('r.request_date', $f['to'] ?? ''),
        ]);
        return Db::all(
            "SELECT r.*, p.name AS project_name, p.project_code,
                    (SELECT COUNT(*) FROM requisition_lines l WHERE l.requisition_id = r.id) AS line_count,
                    (SELECT COUNT(*) FROM requisition_attachments a WHERE a.requisition_id = r.id) AS attachment_count
             FROM requisitions r JOIN projects p ON p.id = r.project_id
             {$where}
             ORDER BY r.created_at DESC",
            $args
        );
    }

    /**
     * "WHERE project_id IN (…)" for the current user, or '' when unscoped.
     * $and appends to an existing WHERE instead of starting one.
     */
    private static function scope(string $col = 'project_id', bool $and = false): array
    {
        $c = Filter::projectScope(Perm::projectIds(), $col);
        if ($c === null) return ['', []];
        return [($and ? ' AND ' : ' WHERE ') . $c[0], $c[1]];
    }

    /** Statuses actually present in what this user can see, for the filter dropdown. */
    public static function statuses(): array
    {
        [$w, $a] = self::scope();
        return array_column(Db::all("SELECT DISTINCT status FROM requisitions{$w} ORDER BY status", $a), 'status');
    }

    public static function count(): int
    {
        [$w, $a] = self::scope();
        return (int)Db::scalar("SELECT COUNT(*) FROM requisitions{$w}", $a);
    }

    /** Number of requisitions awaiting approval, within the user's projects. */
    public static function pendingCount(): int
    {
        [$w, $a] = self::scope('project_id', true);
        return (int)Db::scalar("SELECT COUNT(*) FROM requisitions WHERE status = 'draft'{$w}", $a);
    }

    /** Draft requisitions awaiting approval, newest first, with line count + creator. */
    public static function pending(int $limit = 100): array
    {
        [$w, $a] = self::scope('r.project_id', true);
        return Db::all(
            "SELECT r.*, p.name AS project_name, p.project_code, u.name AS created_by_name,
                    (SELECT COUNT(*) FROM requisition_lines l WHERE l.requisition_id = r.id) AS line_count,
                    (SELECT COUNT(*) FROM requisition_attachments a WHERE a.requisition_id = r.id) AS attachment_count
             FROM requisitions r
             JOIN projects p ON p.id = r.project_id
             LEFT JOIN users u ON u.id = r.created_by
             WHERE r.status = 'draft'{$w}
             ORDER BY r.created_at DESC LIMIT ?",
            array_merge($a, [$limit])
        );
    }

    /** Most recent requisitions of any status (for the dashboard feed). */
    public static function recent(int $limit = 6): array
    {
        [$w, $a] = self::scope('r.project_id');
        return Db::all(
            "SELECT r.*, p.name AS project_name, p.project_code,
                    (SELECT COUNT(*) FROM requisition_lines l WHERE l.requisition_id = r.id) AS line_count
             FROM requisitions r JOIN projects p ON p.id = r.project_id
             {$w}
             ORDER BY r.created_at DESC LIMIT ?",
            array_merge($a, [$limit])
        );
    }

    /** Lines with catalogue name + PO references (a line can span multiple POs). */
    public static function lines(int $reqId): array
    {
        $lines = Db::all(
            "SELECT l.*, c.name AS item_name, c.item_code, c.unit_price AS ref_price
             FROM requisition_lines l
             LEFT JOIN catalogue_items c ON c.id = l.catalogue_item_id
             WHERE l.requisition_id = ? ORDER BY l.line_no",
            [$reqId]
        );
        foreach ($lines as &$l) {
            $l['po_refs'] = Db::all(
                "SELECT DISTINCT po.po_number FROM po_lines pl
                 JOIN purchase_orders po ON po.id = pl.purchase_order_id
                 WHERE pl.requisition_line_id = ?",
                [$l['id']]
            );
            $l['remaining'] = (float)$l['qty'] - (float)$l['qty_ordered'];
        }
        return $lines;
    }

    /** Quotations (and any other files) attached to an MR, oldest first. */
    public static function attachments(int $reqId): array
    {
        return Db::all(
            "SELECT a.*, u.name AS uploaded_by_name
             FROM requisition_attachments a
             LEFT JOIN users u ON u.id = a.uploaded_by
             WHERE a.requisition_id = ? ORDER BY a.id",
            [$reqId]
        );
    }

    public static function findAttachment(int $id): ?array
    {
        return Db::one("SELECT * FROM requisition_attachments WHERE id = ?", [$id]);
    }

    /**
     * Store one or more uploaded quotation files against an MR.
     * $files is a $_FILES entry for a multi-file input (name/tmp_name/… are arrays).
     * Returns [saved count, error messages] — a bad file is skipped, not fatal.
     */
    public static function attachUploads(int $reqId, array $files): array
    {
        $names = (array)($files['name'] ?? []);
        $saved = 0; $errors = [];
        foreach ($names as $i => $name) {
            $err = (int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
            if ($err === UPLOAD_ERR_NO_FILE) continue;
            $label = trim((string)$name) !== '' ? $name : 'file';
            if ($err !== UPLOAD_ERR_OK) { $errors[] = "“{$label}” did not upload (code {$err})."; continue; }
            $tmp = (string)($files['tmp_name'][$i] ?? '');
            if ($tmp === '' || !is_uploaded_file($tmp)) { $errors[] = "“{$label}” did not upload."; continue; }
            $size = (int)($files['size'][$i] ?? 0);
            if ($size > self::ATTACH_MAX_BYTES) {
                $errors[] = "“{$label}” is larger than " . (int)(self::ATTACH_MAX_BYTES / 1024 / 1024) . " MB.";
                continue;
            }
            $ext = strtolower(pathinfo((string)$name, PATHINFO_EXTENSION));
            if (!in_array($ext, self::ATTACH_EXTS, true)) {
                $errors[] = "“{$label}” must be a " . implode(', ', self::ATTACH_EXTS) . ' file.';
                continue;
            }
            $bytes = file_get_contents($tmp);
            if ($bytes === false) { $errors[] = "“{$label}” could not be read."; continue; }
            $path = Storage::saveFile($bytes, $ext, 'quotations');
            Db::insert('requisition_attachments', [
                'requisition_id'    => $reqId,
                'kind'              => 'quotation',
                'file_path'         => $path,
                'original_filename' => self::cleanFilename((string)$name),
                'mime_type'         => (string)($files['type'][$i] ?? '') ?: null,
                'size_bytes'        => $size ?: null,
                'uploaded_by'       => Auth::id(),
            ]);
            $saved++;
        }
        if ($saved) AuditRepo::log('requisition', $reqId, 'attach_quotation');
        return [$saved, $errors];
    }

    public static function deleteAttachment(int $id): void
    {
        $a = self::findAttachment($id);
        if (!$a) return;
        Db::q("DELETE FROM requisition_attachments WHERE id = ?", [$id]);
        Storage::delete($a['file_path']);
        AuditRepo::log('requisition', (int)$a['requisition_id'], 'remove_quotation');
    }

    /** Keep the user's filename recognisable but harmless as a header / link label. */
    private static function cleanFilename(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1f"]/', '', $name) ?? $name;
        $name = trim($name) ?: 'attachment';
        return mb_substr($name, 0, 180);
    }

    /** Create MR header + lines. $lines: array of [catalogue_item_id?, raw_description, model_type?, qty, uom?, remarks?]. */
    public static function create(array $header, array $lines): int
    {
        return Db::tx(function () use ($header, $lines) {
            $reqId = Db::insert('requisitions', [
                'mr_number'        => trim($header['mr_number']),
                'project_id'       => (int)$header['project_id'],
                'supplier_id'      => !empty($header['supplier_id']) ? (int)$header['supplier_id'] : null,
                'requested_by'     => trim($header['requested_by'] ?? '') ?: null,
                'requester_mobile' => trim($header['requester_mobile'] ?? '') ?: null,
                'requester_email'  => trim($header['requester_email'] ?? '') ?: null,
                'request_date'     => ($header['request_date'] ?? '') ?: null,
                'delivery_date'    => trim($header['delivery_date'] ?? '') ?: null,
                'urgency'          => trim($header['urgency'] ?? '') ?: null,
                'notes'            => trim($header['notes'] ?? '') ?: null,
                'status'           => 'draft',
                'created_by'       => Auth::id(),
            ]);
            $no = 0;
            foreach ($lines as $l) {
                $desc = trim($l['raw_description'] ?? '');
                if ($desc === '' && empty($l['catalogue_item_id'])) continue;
                $no++;
                Db::insert('requisition_lines', [
                    'requisition_id'    => $reqId,
                    'line_no'           => $no,
                    'catalogue_item_id' => !empty($l['catalogue_item_id']) ? (int)$l['catalogue_item_id'] : null,
                    'raw_description'   => $desc !== '' ? $desc : ($l['item_name'] ?? 'Item'),
                    'model_type'        => trim($l['model_type'] ?? '') ?: null,
                    'qty'               => (float)$l['qty'],
                    'uom'               => trim($l['uom'] ?? '') ?: null,
                    'remarks'           => trim($l['remarks'] ?? '') ?: null,
                ]);
            }
            return $reqId;
        });
    }

    /** True when at least one PO line was raised from this requisition. */
    public static function hasPurchaseOrders(int $id): bool
    {
        return (bool)Db::scalar(
            "SELECT 1 FROM po_lines pl JOIN requisition_lines rl ON rl.id = pl.requisition_line_id
             WHERE rl.requisition_id = ? LIMIT 1",
            [$id]
        );
    }

    /** Replace a draft requisition's header + lines. Only valid while status = 'draft'. */
    public static function update(int $id, array $header, array $lines): void
    {
        Db::tx(function () use ($id, $header, $lines) {
            Db::update('requisitions', $id, [
                'mr_number'        => trim($header['mr_number']),
                'project_id'       => (int)$header['project_id'],
                'supplier_id'      => !empty($header['supplier_id']) ? (int)$header['supplier_id'] : null,
                'requested_by'     => trim($header['requested_by'] ?? '') ?: null,
                'requester_mobile' => trim($header['requester_mobile'] ?? '') ?: null,
                'requester_email'  => trim($header['requester_email'] ?? '') ?: null,
                'request_date'     => ($header['request_date'] ?? '') ?: null,
                'delivery_date'    => trim($header['delivery_date'] ?? '') ?: null,
                'urgency'          => trim($header['urgency'] ?? '') ?: null,
                'notes'            => trim($header['notes'] ?? '') ?: null,
            ]);
            Db::q("DELETE FROM requisition_lines WHERE requisition_id = ?", [$id]);
            $no = 0;
            foreach ($lines as $l) {
                $desc = trim($l['raw_description'] ?? '');
                if ($desc === '' && empty($l['catalogue_item_id'])) continue;
                $no++;
                Db::insert('requisition_lines', [
                    'requisition_id'    => $id,
                    'line_no'           => $no,
                    'catalogue_item_id' => !empty($l['catalogue_item_id']) ? (int)$l['catalogue_item_id'] : null,
                    'raw_description'   => $desc !== '' ? $desc : ($l['item_name'] ?? 'Item'),
                    'model_type'        => trim($l['model_type'] ?? '') ?: null,
                    'qty'               => (float)$l['qty'],
                    'uom'               => trim($l['uom'] ?? '') ?: null,
                    'remarks'           => trim($l['remarks'] ?? '') ?: null,
                ]);
            }
            AuditRepo::log('requisition', $id, 'edit');
        });
    }

    /** Delete a requisition + its lines. Blocked once any PO has been raised from it. */
    public static function delete(int $id): void
    {
        if (self::hasPurchaseOrders($id)) {
            throw new \RuntimeException('Cannot delete: purchase orders were already raised from this requisition.');
        }
        foreach (self::attachments($id) as $a) Storage::delete($a['file_path']);
        Db::q("DELETE FROM requisitions WHERE id = ?", [$id]); // lines + attachments cascade
        AuditRepo::log('requisition', $id, 'delete');
    }

    public static function approve(int $id): void
    {
        Db::q("UPDATE requisitions SET status = 'approved' WHERE id = ? AND status = 'draft'", [$id]);
        AuditRepo::log('requisition', $id, 'approve');
    }

    public static function reject(int $id): void
    {
        Db::q("UPDATE requisitions SET status = 'cancelled' WHERE id = ? AND status = 'draft'", [$id]);
        AuditRepo::log('requisition', $id, 'reject');
    }

    /** Recompute MR + line statuses from qty_ordered. Call after PO creation. */
    public static function recompute(int $reqId): void
    {
        $lines = Db::all("SELECT id, qty, qty_ordered, status FROM requisition_lines WHERE requisition_id = ?", [$reqId]);
        $allFull = true; $anyOrdered = false;
        foreach ($lines as $l) {
            if ($l['status'] === 'cancelled') continue;
            $status = 'open';
            if ((float)$l['qty_ordered'] >= (float)$l['qty'] && (float)$l['qty'] > 0) { $status = 'fully_ordered'; }
            elseif ((float)$l['qty_ordered'] > 0) { $status = 'partially_ordered'; }
            if ($status !== $l['status']) Db::update('requisition_lines', (int)$l['id'], ['status' => $status]);
            if ($status !== 'fully_ordered') $allFull = false;
            if ($status !== 'open') $anyOrdered = true;
        }
        $req = Db::one("SELECT status FROM requisitions WHERE id = ?", [$reqId]);
        if ($req && !in_array($req['status'], ['closed', 'cancelled'], true)) {
            $new = $allFull ? 'fully_ordered' : ($anyOrdered ? 'partially_ordered' : 'approved');
            if ($new !== $req['status']) Db::update('requisitions', $reqId, ['status' => $new]);
        }
    }
}
