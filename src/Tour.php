<?php
declare(strict_types=1);

namespace App;

use App\Support\Normalizer;

/**
 * Guided Tour / Training Mode.
 *
 * When a user starts the tour we flip the whole app onto a SEPARATE demo
 * database (storage/starship_demo.sqlite via Db::useDemo) seeded with sample
 * data, so every practice action — raising MRs, approving, building POs,
 * receiving a WhatsApp DO — is completely isolated from live data. Exiting the
 * tour drops them straight back onto the real database.
 *
 * Nothing here changes the production schema: the demo DB is built on the fly
 * from db/schema.sql + db/migrations, so deploying this feature is file-only.
 */
final class Tour
{
    // Recognisable demo records. The WhatsApp sample DO carries these so the
    // webhook can route that one message into the demo DB too.
    public const PROJECT_CODE = 'TR-DEMO';
    public const PROJECT_NAME = 'Training Site (Demo)';
    public const SUPPLIER     = 'Demo Hardware Supply';
    public const PO_NUMBER    = 'PO-DEMO-001';
    public const MR_NUMBER    = 'MR-DEMO-1';

    /** True while the current web session is running the guided tour. */
    public static function active(): bool
    {
        return !empty($_SESSION['tour_mode']);
    }

    /** Turn Training Mode on for this session and (re)build a fresh demo DB. */
    public static function start(int $uid, string $name, string $role): void
    {
        self::rebuildDemo($uid, $name, $role);
        $_SESSION['tour_mode'] = 1;
    }

    /** Leave Training Mode — subsequent requests use the real database again. */
    public static function exit(): void
    {
        unset($_SESSION['tour_mode']);
        Db::useDemo(false);
    }

    /** The live Starship WhatsApp line, read from the REAL settings even while
     *  the request is running against the demo DB. Empty string if unset. */
    public static function waNumber(): string
    {
        $wasDemo = Db::isDemo();
        if ($wasDemo) Db::useDemo(false);
        $n = preg_replace('/\D+/', '', (string)\App\Settings::raw('wazzup.number', ''));
        if ($wasDemo) Db::useDemo(true);
        return (string)$n;
    }

    /**
     * Add a learner's phone to the REAL WhatsApp approved-sender allowlist (that
     * table lives in the live DB, not the demo one) so their sample-DO photo gets
     * a real reply. Toggles off demo mode for the write, then back.
     */
    public static function approveSender(string $phone, string $name): void
    {
        $wasDemo = Db::isDemo();
        if ($wasDemo) Db::useDemo(false);
        \App\Repo\WaSenderRepo::add($phone, $name);
        if ($wasDemo) Db::useDemo(true);
    }

    /**
     * Drop the sample delivery straight into the demo DB — the "no phone needed"
     * path so the tour's receive/match step works even when the learner's number
     * isn't an approved WhatsApp sender. Idempotent: only one sample DO exists.
     * Runs inside the request's existing demo-DB context.
     */
    public static function addSampleDelivery(): int
    {
        $existing = Db::scalar("SELECT id FROM delivery_orders WHERE do_number = ?", ['DO-DEMO-7781']);
        if ($existing) return (int)$existing;

        $sup = \App\Repo\SupplierRepo::matchByName(self::SUPPLIER);
        $doId = \App\Repo\DeliveryOrderRepo::create([
            'do_number'         => 'DO-DEMO-7781',
            'po_reference_raw'  => self::PO_NUMBER,
            'project_code_raw'  => self::PROJECT_CODE,
            'delivery_date'     => date('Y-m-d'),
            'signature_present' => 1,
            'image_path'        => 'tour/tour-sample-do.jpg',
            'source_channel'    => 'tour_sample',
            'supplier_id'       => $sup['id'] ?? null,
        ], [
            ['ocr_description' => 'CO2 Fire Extinguisher 5kg', 'ocr_supplier_code' => '', 'ocr_qty' => 10, 'ocr_uom' => 'UNIT'],
            ['ocr_description' => 'Pressure Gauge 0-300 Bar',  'ocr_supplier_code' => '', 'ocr_qty' => 12, 'ocr_uom' => 'PCS'],
            ['ocr_description' => 'Galvanised U-Bolt M12',     'ocr_supplier_code' => '', 'ocr_qty' => 50, 'ocr_uom' => 'PCS'],
        ]);
        try { \App\Service\MatchingService::suggest($doId); } catch (\Throwable $e) { /* best-effort */ }
        return $doId;
    }

    /**
     * Does this OCR result look like the training sample DO? If so the WhatsApp
     * intake stores it in the demo DB instead of production.
     */
    public static function isDemoOcr(array $d): bool
    {
        $hay = strtoupper(($d['po_reference'] ?? '') . ' ' . ($d['project_code'] ?? '') . ' ' . ($d['do_number'] ?? ''));
        return str_contains($hay, 'DEMO') || str_contains($hay, 'TR-DEMO');
    }

    // -- demo database build ------------------------------------------------

    /** Wipe and rebuild the demo DB: schema + migrations + sample data. */
    public static function rebuildDemo(int $uid, string $name, string $role): void
    {
        // Drop any open handle, delete the old demo files, then build fresh.
        Db::useDemo(false);
        foreach (['', '-wal', '-shm'] as $s) @unlink(Db::demoPath() . $s);

        Db::useDemo(true);
        self::runSqlFile(APP_ROOT . '/db/schema.sql');
        foreach (glob(APP_ROOT . '/db/migrations/*.sql') ?: [] as $mig) {
            self::runSqlFile($mig);
        }
        self::seed($uid, $name, $role);
        Db::useDemo(false); // leave global state clean; the request layer re-enables per request
    }

    /** Run a .sql file statement-by-statement, ignoring "already exists" noise. */
    private static function runSqlFile(string $path): void
    {
        $pdo = Db::conn();
        $sql = preg_replace('/^\s*--.*$/m', '', (string)file_get_contents($path));
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            if ($stmt === '') continue;
            try { $pdo->exec($stmt); }
            catch (\Throwable $e) {
                $m = $e->getMessage();
                if (stripos($m, 'already exists') === false && stripos($m, 'duplicate column') === false) {
                    error_log('Tour demo build: ' . $m);
                }
            }
        }
    }

    /** Insert the sample project, supplier, catalogue, an approved MR and an
     *  issued PO the WhatsApp delivery will 3-way match against. */
    private static function seed(int $uid, string $name, string $role): void
    {
        // The tour user — satisfies created_by foreign keys inside the demo DB.
        Db::insert('users', [
            'id' => $uid, 'name' => $name ?: 'Trainee', 'email' => 'trainee+' . $uid . '@demo.local',
            'password_hash' => 'x', 'role' => $role ?: 'admin', 'is_active' => 1,
        ]);

        $supId = Db::insert('suppliers', [
            'name' => self::SUPPLIER, 'short_code' => 'DEMO', 'is_active' => 1,
        ]);
        $projId = Db::insert('projects', [
            'project_code' => self::PROJECT_CODE, 'project_code_norm' => Normalizer::projectCode(self::PROJECT_CODE),
            'name' => self::PROJECT_NAME, 'site_address' => 'Demo Site, Kuala Lumpur', 'is_active' => 1,
        ]);

        // Catalogue: the three delivered items + a couple more to make search fun.
        $items = [
            ['DEMO-CO2-5',  'CO2 Fire Extinguisher 5kg',   'UNIT', 180.00, 'Fire Protection'],
            ['DEMO-PG-300', 'Pressure Gauge 0-300 Bar',    'PCS',   45.00, 'Instrumentation'],
            ['DEMO-UB-12',  'Galvanised U-Bolt M12',       'PCS',    3.50, 'Fixings'],
            ['DEMO-CPL-2',  'Mechanical Coupling 2 inch',  'PCS',   22.00, 'Piping'],
            ['DEMO-VLV-25', 'Gate Valve 25mm Brass',       'PCS',   38.00, 'Piping'],
        ];
        $catId = [];
        foreach ($items as [$code, $nm, $uom, $price, $cat]) {
            $catId[$code] = Db::insert('catalogue_items', [
                'item_code' => $code, 'name' => $nm, 'uom' => $uom, 'category' => $cat,
                'unit_price' => $price, 'is_active' => 1,
                'search_blob' => Normalizer::searchBlob(['item_code' => $code, 'name' => $nm, 'category' => $cat]),
            ]);
        }

        // An approved requisition for context (shows in the MR list).
        $mrId = Db::insert('requisitions', [
            'mr_number' => self::MR_NUMBER, 'project_id' => $projId, 'requested_by' => 'Site Team',
            'request_date' => date('Y-m-d'), 'urgency' => 'ASAP/URGENT', 'status' => 'approved',
            'created_by' => $uid,
        ]);
        // The three lines that get ordered.
        $poItems = [
            ['DEMO-CO2-5',  'CO2 Fire Extinguisher 5kg', 10, 'UNIT', 180.00],
            ['DEMO-PG-300', 'Pressure Gauge 0-300 Bar',  12, 'PCS',   45.00],
            ['DEMO-UB-12',  'Galvanised U-Bolt M12',     50, 'PCS',    3.50],
        ];
        $ln = 1;
        foreach ($poItems as [$code, $desc, $qty, $uom, $price]) {
            Db::insert('requisition_lines', [
                'requisition_id' => $mrId, 'line_no' => $ln++, 'catalogue_item_id' => $catId[$code],
                'raw_description' => $desc, 'qty' => $qty, 'uom' => $uom, 'qty_ordered' => $qty, 'status' => 'ordered',
            ]);
        }

        // The issued PO the delivery will match. status MUST be 'issued' for
        // MatchingService::resolvePo to consider it.
        $total = 0.0; foreach ($poItems as [, , $qty, , $price]) $total += $qty * $price;
        $poId = Db::insert('purchase_orders', [
            'po_number' => self::PO_NUMBER, 'po_number_norm' => Normalizer::poNumber(self::PO_NUMBER),
            'requisition_id' => $mrId, 'supplier_id' => $supId, 'project_id' => $projId,
            'order_date' => date('Y-m-d'), 'currency' => 'MYR', 'total_amount' => $total,
            'status' => 'issued', 'created_by' => $uid,
        ]);
        $ln = 1;
        foreach ($poItems as [$code, $desc, $qty, $uom, $price]) {
            Db::insert('po_lines', [
                'purchase_order_id' => $poId, 'line_no' => $ln++, 'catalogue_item_id' => $catId[$code],
                'description' => $desc, 'qty_ordered' => $qty, 'uom' => $uom,
                'unit_price' => $price, 'line_total' => $qty * $price,
                'qty_received' => 0, 'line_status' => 'open',
            ]);
        }
    }
}
