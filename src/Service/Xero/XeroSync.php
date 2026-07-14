<?php
declare(strict_types=1);

namespace App\Service\Xero;

use App\Db;
use App\Settings;
use App\Support\Normalizer;
use App\Repo\AuditRepo;

/**
 * Pulls Starship's master data down from Xero — the source of truth. Suppliers
 * mirror Xero Contacts, Projects mirror the options of Xero's "Project" tracking
 * category, and Catalogue items mirror Xero Items. Also pushes a Starship
 * product up so it appears in Xero's Products & Services.
 *
 * Every method is self-contained and read-mostly: on any Xero failure it throws
 * a RuntimeException with a human message (callers turn that into a flash), and
 * never partially corrupts local rows — each upsert is its own statement.
 */
final class XeroSync
{
    private const API      = 'https://api.xero.com/api.xro/2.0/';
    private const PAGE_MAX = 50;   // safety bound on pagination loops

    // ---- Suppliers  ⇄  Xero Contacts -------------------------------

    /** @return array{created:int,updated:int,total:int} */
    public static function pullContacts(): array
    {
        // Collect from two channels, deduped by ContactID, then upsert once:
        //  1. IsSupplier==true — Xero's derived flag (true once billed/PO'd).
        //  2. Members of a named Xero contact group ("supplier group") — a manual
        //     override so a new supplier syncs BEFORE its first PO.
        $contacts = [];
        for ($page = 1; $page <= self::PAGE_MAX; $page++) {
            $json = self::get('Contacts', ['page' => $page, 'includeArchived' => 'false', 'where' => 'IsSupplier==true']);
            $rows = $json['Contacts'] ?? [];
            if (!$rows) break;
            foreach ($rows as $c) {
                if (($c['ContactStatus'] ?? 'ACTIVE') === 'ARCHIVED') continue;
                if (($c['IsSupplier'] ?? null) !== true) continue;   // backstop
                if (!empty($c['ContactID'])) $contacts[$c['ContactID']] = $c;
            }
            if (count($rows) < 100) break;   // last page
        }

        foreach (self::groupSupplierContacts(array_keys($contacts)) as $c) {
            if (!empty($c['ContactID'])) $contacts[$c['ContactID']] = $c;
        }

        $created = 0; $updated = 0;
        foreach ($contacts as $c) self::upsertContact($c, $created, $updated);
        $total = count($contacts);
        AuditRepo::log('supplier', 0, 'xero_pull_contacts', compact('created', 'updated', 'total'));
        return compact('created', 'updated', 'total');
    }

    /**
     * Full contact records for members of the configured "supplier group" that
     * aren't already covered by IsSupplier. Lets an operator force-include a new
     * supplier in Xero (add it to the group) so it appears in Starship right away.
     *
     * @param string[] $alreadyHave ContactIDs already collected via IsSupplier.
     * @return array<int,array> Xero contact objects (full detail).
     */
    private static function groupSupplierContacts(array $alreadyHave): array
    {
        // raw() (not get()) so an explicitly-cleared value disables the override;
        // an absent key defaults to a group named "Suppliers".
        $groupName = trim((string)Settings::raw('xero.supplier_group', 'Suppliers'));
        if ($groupName === '') return [];

        $memberIds = self::supplierGroupMemberIds($groupName);
        $missing = array_values(array_diff($memberIds, $alreadyHave));
        if (!$missing) return [];

        // /Contacts?IDs=a,b,c returns full records in one call; chunk to keep the URL sane.
        $out = [];
        foreach (array_chunk($missing, 50) as $chunk) {
            $json = self::get('Contacts', ['IDs' => implode(',', $chunk)]);
            foreach ($json['Contacts'] ?? [] as $c) {
                if (($c['ContactStatus'] ?? 'ACTIVE') === 'ARCHIVED') continue;
                $out[] = $c;
            }
        }
        return $out;
    }

    /** ContactIDs belonging to the named (case-insensitive) active contact group. */
    private static function supplierGroupMemberIds(string $groupName): array
    {
        $json = self::get('ContactGroups');
        $groupId = null;
        foreach ($json['ContactGroups'] ?? [] as $g) {
            if (($g['Status'] ?? 'ACTIVE') !== 'ACTIVE') continue;
            if (strcasecmp(trim((string)($g['Name'] ?? '')), $groupName) === 0) { $groupId = (string)$g['ContactGroupID']; break; }
        }
        if (!$groupId) return [];

        // The single-group GET is what returns the member Contacts array.
        $one = self::get('ContactGroups/' . $groupId);
        $members = $one['ContactGroups'][0]['Contacts'] ?? [];
        return array_values(array_filter(array_map(fn($c) => $c['ContactID'] ?? null, $members)));
    }

    private static function upsertContact(array $c, int &$created, int &$updated): void
    {
        $xid  = (string)($c['ContactID'] ?? '');
        $name = trim((string)($c['Name'] ?? ''));
        if ($xid === '' || $name === '') return;

        $existing = Db::one("SELECT * FROM suppliers WHERE xero_contact_id = ?", [$xid])
            ?: Db::one("SELECT * FROM suppliers WHERE LOWER(name) = LOWER(?)", [$name]);

        // Xero-owned fields (mirror). Starship-local fields (short_code, whatsapp,
        // po_number_hint, sst/tin) are never clobbered by a sync.
        $fields = [
            'name'            => $name,
            'email'           => trim((string)($c['EmailAddress'] ?? '')) ?: ($existing['email'] ?? null),
            'phone'           => self::pickPhone($c['Phones'] ?? []) ?: ($existing['phone'] ?? null),
            'xero_contact_id' => $xid,
            'xero_synced_at'  => date('Y-m-d H:i:s'),
        ];
        if ($existing) { Db::update('suppliers', (int)$existing['id'], $fields); $updated++; }
        else           { Db::insert('suppliers', $fields + ['is_active' => 1]); $created++; }
    }

    private static function pickPhone(array $phones): ?string
    {
        $byType = [];
        foreach ($phones as $p) {
            $num = trim((string)($p['PhoneNumber'] ?? ''));
            if ($num === '') continue;
            $full = trim(((string)($p['PhoneCountryCode'] ?? '')) . ' '
                . ((string)($p['PhoneAreaCode'] ?? '')) . ' ' . $num);
            $byType[$p['PhoneType'] ?? 'DEFAULT'] = preg_replace('/\s+/', ' ', $full);
        }
        return $byType['DEFAULT'] ?? $byType['MOBILE'] ?? $byType['DDI'] ?? null;
    }

    // ---- Projects  ⇄  Xero "Project" tracking category -------------

    /** @return array{created:int,updated:int,total:int,category:string} */
    public static function pullProjects(): array
    {
        $cat = self::projectTrackingCategory();
        if (!$cat) throw new \RuntimeException('No usable tracking category found in Xero. Create a "Project" tracking category first.');

        // Remember the chosen category so PO line items can carry its tracking.
        Settings::set('xero.project_tracking_category_id', (string)$cat['TrackingCategoryID']);
        Settings::set('xero.project_tracking_category', (string)($cat['Name'] ?? 'Project'));

        $created = 0; $updated = 0; $total = 0;
        foreach ($cat['Options'] ?? [] as $opt) {
            if (($opt['Status'] ?? 'ACTIVE') !== 'ACTIVE') continue;
            $total++;
            self::upsertProject($cat, $opt, $created, $updated);
        }
        AuditRepo::log('project', 0, 'xero_pull_projects', ['category' => $cat['Name'] ?? '', 'created' => $created, 'updated' => $updated, 'total' => $total]);
        return ['created' => $created, 'updated' => $updated, 'total' => $total, 'category' => (string)($cat['Name'] ?? 'Project')];
    }

    /** The tracking category that represents projects: configured id, else name ~ "project", else first non-MyInvois. */
    private static function projectTrackingCategory(): ?array
    {
        $json = self::get('TrackingCategories', ['includeArchived' => 'false']);
        $cats = $json['TrackingCategories'] ?? [];
        if (!$cats) return null;

        $wantId = trim((string)Settings::raw('xero.project_tracking_category_id', ''));
        if ($wantId !== '') {
            foreach ($cats as $c) if (($c['TrackingCategoryID'] ?? '') === $wantId) return $c;
        }
        foreach ($cats as $c) if (stripos((string)($c['Name'] ?? ''), 'project') !== false) return $c;
        foreach ($cats as $c) if (stripos((string)($c['Name'] ?? ''), 'myinvois') === false) return $c;
        return $cats[0];
    }

    private static function upsertProject(array $cat, array $opt, int &$created, int &$updated): void
    {
        $optId = (string)($opt['TrackingOptionID'] ?? '');
        $name  = trim((string)($opt['Name'] ?? ''));
        if ($optId === '' || $name === '') return;

        $existing = Db::one("SELECT * FROM projects WHERE xero_tracking_option_id = ?", [$optId])
            ?: Db::one("SELECT * FROM projects WHERE project_code_norm = ?", [Normalizer::projectCode($name)]);

        $fields = [
            'project_code'              => $existing['project_code'] ?? $name,
            'project_code_norm'         => Normalizer::projectCode($existing['project_code'] ?? $name),
            'name'                      => $name,
            'xero_tracking_option'      => $name,
            'xero_tracking_option_id'   => $optId,
            'xero_tracking_category_id' => (string)($cat['TrackingCategoryID'] ?? ''),
            'xero_synced_at'            => date('Y-m-d H:i:s'),
        ];
        if ($existing) { Db::update('projects', (int)$existing['id'], $fields); $updated++; }
        else           { Db::insert('projects', $fields + ['is_active' => 1]); $created++; }
    }

    // ---- Catalogue  ⇄  Xero Items ----------------------------------

    /** @return array{created:int,updated:int,total:int} */
    public static function pullItems(): array
    {
        $created = 0; $updated = 0; $total = 0;
        for ($page = 1; $page <= self::PAGE_MAX; $page++) {
            $json = self::get('Items', ['page' => $page]);
            $rows = $json['Items'] ?? [];
            if (!$rows) break;
            foreach ($rows as $it) { $total++; self::upsertItem($it, $created, $updated); }
            if (count($rows) < 100) break;
        }
        AuditRepo::log('catalogue_item', 0, 'xero_pull_items', compact('created', 'updated', 'total'));
        return compact('created', 'updated', 'total');
    }

    private static function upsertItem(array $it, int &$created, int &$updated): void
    {
        $xid  = (string)($it['ItemID'] ?? '');
        $code = trim((string)($it['Code'] ?? '')) ?: $xid;
        $name = trim((string)($it['Name'] ?? '')) ?: $code;
        if ($code === '') return;

        $price = $it['PurchaseDetails']['UnitPrice'] ?? $it['SalesDetails']['UnitPrice'] ?? null;

        $existing = ($xid !== '' ? Db::one("SELECT * FROM catalogue_items WHERE xero_item_id = ?", [$xid]) : null)
            ?: Db::one("SELECT * FROM catalogue_items WHERE xero_item_code = ?", [$code])
            ?: Db::one("SELECT * FROM catalogue_items WHERE item_code = ?", [$code]);

        $fields = [
            'item_code'      => $existing['item_code'] ?? $code,
            'name'           => $name,
            'description'    => trim((string)($it['PurchaseDescription'] ?? $it['Description'] ?? '')) ?: ($existing['description'] ?? null),
            'unit_price'     => $price !== null ? (float)$price : ($existing['unit_price'] ?? null),
            'xero_item_id'   => $xid ?: ($existing['xero_item_id'] ?? null),
            'xero_item_code' => $code,
            'xero_synced_at' => date('Y-m-d H:i:s'),
            'xero_last_error'=> null,
        ];
        // Keep the denormalized search index in step with the new name/description.
        $fields['search_blob'] = Normalizer::searchBlob(array_merge($existing ?? [], $fields));

        if ($existing) { Db::update('catalogue_items', (int)$existing['id'], $fields); $updated++; }
        else           { Db::insert('catalogue_items', $fields + ['is_active' => 1]); $created++; }
    }

    /**
     * Push one Starship product up to Xero's Products & Services so it can be
     * found there. Xero's POST /Items upserts on Code, so this both creates and
     * keeps an existing item in step. Never throws — returns an error string on
     * failure so saving the product locally is never blocked by Xero.
     *
     * @return array{xero_item_id:?string, xero_item_code:?string, error?:string}
     */
    public static function pushItemById(int $id): array
    {
        $item = Db::one("SELECT * FROM catalogue_items WHERE id = ?", [$id]);
        if (!$item) return ['xero_item_id' => null, 'xero_item_code' => null, 'error' => 'Item not found.'];

        try {
            // Xero Code: max 30 chars; Name: max 50.
            $code = substr((string)$item['item_code'], 0, 30);
            $payload = array_filter([
                'Code'                => $code,
                'Name'                => substr((string)$item['name'], 0, 50),
                'PurchaseDescription' => (string)($item['description'] ?: $item['name']),
                'IsPurchased'         => true,
                'PurchaseDetails'     => $item['unit_price'] !== null && $item['unit_price'] !== ''
                    ? ['UnitPrice' => (float)$item['unit_price']] : null,
            ], fn($v) => $v !== null && $v !== '');

            [$code2, $body] = self::request('POST', 'Items', json_encode(['Items' => [$payload]], JSON_UNESCAPED_UNICODE));
            $json = json_decode($body, true);
            $created = $json['Items'][0] ?? null;
            $xeroId  = $created['ItemID'] ?? null;

            if ($code2 < 200 || $code2 >= 300 || !$xeroId) {
                $err = self::extractError($json, $body);
                Db::update('catalogue_items', $id, ['xero_last_error' => $err]);
                AuditRepo::log('catalogue_item', $id, 'xero_item_failed', ['http' => $code2, 'error' => $err]);
                return ['xero_item_id' => null, 'xero_item_code' => null, 'error' => $err];
            }

            Db::update('catalogue_items', $id, [
                'xero_item_id'    => $xeroId,
                'xero_item_code'  => $created['Code'] ?? $code,
                'xero_synced_at'  => date('Y-m-d H:i:s'),
                'xero_last_error' => null,
            ]);
            AuditRepo::log('catalogue_item', $id, 'xero_item_pushed', ['xero_item_id' => $xeroId]);
            return ['xero_item_id' => $xeroId, 'xero_item_code' => $created['Code'] ?? $code];
        } catch (\Throwable $e) {
            Db::update('catalogue_items', $id, ['xero_last_error' => $e->getMessage()]);
            return ['xero_item_id' => null, 'xero_item_code' => null, 'error' => $e->getMessage()];
        }
    }

    // ---- HTTP plumbing ---------------------------------------------

    private static function get(string $path, array $query = []): array
    {
        $url = self::API . $path . ($query ? ('?' . http_build_query($query)) : '');
        [$code, $body] = self::request('GET', $url, null, true);
        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException('Xero ' . $path . ' failed (HTTP ' . $code . '): ' . self::extractError(json_decode($body, true), $body));
        }
        return json_decode($body, true) ?: [];
    }

    /**
     * @param bool $absolute true when $target is already a full URL (GET builds one).
     * @return array{0:int,1:string}
     */
    private static function request(string $method, string $target, ?string $body = null, bool $absolute = false): array
    {
        $auth = XeroOAuth::accessToken();
        if (!$auth) throw new \RuntimeException('Xero is not connected.');
        $url = $absolute ? $target : (self::API . $target);
        return XeroOAuth::http($method, $url, [
            'Authorization: Bearer ' . $auth['access_token'],
            'Xero-tenant-id: ' . $auth['tenant_id'],
            'Accept: application/json',
            'Content-Type: application/json',
        ], $body);
    }

    private static function extractError(?array $json, string $raw): string
    {
        if (is_array($json)) {
            $els = $json['Elements'][0]['ValidationErrors'] ?? $json['ValidationErrors'] ?? null;
            if ($els) return implode('; ', array_map(fn($e) => $e['Message'] ?? '', $els));
            if (!empty($json['Message'])) return (string)$json['Message'];
            if (!empty($json['detail'])) return (string)$json['detail'];
        }
        return trim(substr($raw, 0, 300)) ?: 'Unknown Xero error.';
    }
}
