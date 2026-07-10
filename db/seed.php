<?php
/**
 * Seeds real Globe master data (suppliers, projects, catalogue, one demo alias)
 * and an admin user. Idempotent: safe to re-run.
 * CLI:  php db/seed.php
 * Web:  /db/seed.php?token=<app_key>
 */
define('GLOBE_APP', 1);
require __DIR__ . '/../src/bootstrap.php';

use App\Db;
use App\Repo\CatalogueRepo;

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    if (!hash_equals(cfg('app.app_key', ''), $_GET['token'] ?? '')) { http_response_code(403); exit('Forbidden'); }
    header('Content-Type: text/plain');
}

function upsertSupplier(array $d): int {
    $existing = Db::one("SELECT id FROM suppliers WHERE name = ?", [$d['name']]);
    if ($existing) return (int)$existing['id'];
    return Db::insert('suppliers', $d);
}
function upsertProject(array $d): int {
    $existing = Db::one("SELECT id FROM projects WHERE project_code = ?", [$d['project_code']]);
    if ($existing) return (int)$existing['id'];
    $d['project_code_norm'] = \App\Support\Normalizer::projectCode($d['project_code']);
    return Db::insert('projects', $d);
}

// --- Suppliers (from the real invoices) -----------------------------
$sca = upsertSupplier(['name' => 'Source Code Asia Sdn Bhd', 'short_code' => 'SCA', 'po_number_hint' => 'e.g. 1/157601R']);
$ufi = upsertSupplier(['name' => 'Unique Fire Industry Sdn Bhd', 'short_code' => 'UFI', 'po_number_hint' => 'numeric cust PO e.g. 130378; 120-day terms']);
$sch = upsertSupplier(['name' => 'Seng Choon Hardware (M) Sdn Bhd', 'short_code' => 'SCH', 'myinvois_tin' => 'C3881630100', 'po_number_hint' => 'numeric e.g. 130536; MyInvois e-invoice']);
echo "Suppliers: SCA={$sca} UFI={$ufi} SCH={$sch}\n";

// --- Projects (from the real docs) ----------------------------------
$pV50 = upsertProject(['project_code' => 'V50', 'name' => 'V50 — UOA Bangsar South', 'site_address' => 'Jalan Kerinchi, Bangsar South, 59200 Kuala Lumpur']);
$pAya = upsertProject(['project_code' => '24B-135494', 'name' => 'Ayanna — BKSP Kinrara Puchong', 'site_address' => 'Bandar Kinrara, Puchong']);
echo "Projects: V50={$pV50} Ayanna={$pAya}\n";

// --- Catalogue (real parts across the sample documents) -------------
$catalogue = [
    // Source Code Asia — Notifier addressable fire alarm
    ['FSP-951',       'Notifier Addressable Smoke Detector', 'Notifier', 'FSP-951', 'nos'],
    ['B501-W',        'Base Without Flange, White',          'Notifier', 'B501/W',  'nos'],
    ['F-MCP-GLASS',   'Flashscan Addressable Manual Callpoint (UL, India)', 'Notifier', 'F/MCP/GLASS', 'nos'],
    ['FMM-101',       'Addressable Monitor Module',          'Notifier', 'FMM-101', 'nos'],
    ['FCM-1',         'Addressable Control Module',          'Notifier', 'FCM-1',   'nos'],
    ['ISO-X',         'Isolator Module',                     'Notifier', 'ISO-X',   'nos'],
    ['LOC-SBB-B4R',   'Local Fire Alarm Cabinet',            'Notifier', 'LOC-SBB-B4R', 'nos'],
    // Unique Fire Industry — CO2 suppression
    ['CYL-N',         '45KG CO2 Cylinder c/w Gas & Normal Valve', 'Unique', null, 'nos'],
    ['HHF-VP-38',     '3/8" Vent Plug',                      'Unique', 'HHF-VP-3/8', 'nos'],
    ['HH-CH-13',      '1/4" x 11" Connecting Hose',          'Unique', 'HH-CH-13', 'nos'],
    ['HH-CH-20',      '1/4" x 20" Connecting Hose',          'Unique', 'HH-CH-20', 'nos'],
    ['HH-DH-17',      '1/2" x 17" Discharge Hose',           'Unique', 'HH-DH-17', 'nos'],
    ['PC-T',          'Pilot Cylinder c/w Bracket (T)',      'Unique', 'PC-T',   'nos'],
    ['MPB',           'Manual Pull Box c/w Accessories',     'Unique', 'MPB',    'nos'],
    ['MPB-CABLE',     '1.5mm Galvanised Steel Cable (150m/roll)', 'Unique', 'MPB-CABLE', 'ft'],
    ['DN-UH25',       '25mm Discharge Nozzle (UH-25)',       'Unique', 'DN-UH25', 'nos'],
    ['DN-UH20',       '20mm Discharge Nozzle (UH-20)',       'Unique', 'DN-UH20', 'nos'],
    ['DHN-20',        '20mm Discharge Horn Nozzle',          'Unique', 'DHN-20', 'nos'],
    ['DHN-25',        '25mm Discharge Horn Nozzle',          'Unique', 'DHN-25', 'nos'],
    // Ayanna MR48 items
    ['VIC-COUP-6',    '6" Mech Coupling Victaulic',          'Victaulic', 'Style 005', 'nos'],
    ['UBOLT-2',       '2" U-Bolt c/w Nut',                   null, null, 'nos'],
    ['PG-200',        'Pressure Gauge c/w cert (200psi)',    null, null, 'nos'],
    ['GI-SHEET-8',    '8" GI Sheet (200mm length)',          null, null, 'nos'],
];
$n = 0;
foreach ($catalogue as [$code, $name, $brand, $model, $uom]) {
    if (Db::one("SELECT id FROM catalogue_items WHERE item_code = ?", [$code])) continue;
    CatalogueRepo::save(['item_code' => $code, 'name' => $name, 'brand' => $brand, 'model' => $model, 'uom' => $uom]);
    $n++;
}
echo "Catalogue: {$n} new items (total " . CatalogueRepo::count() . ")\n";

// --- Category + reference unit price (from the real invoices) --------
// [item_code => [category, unit_price|null]]
$meta = [
    'FSP-951'=>['Fire Alarm',127.32], 'B501-W'=>['Fire Alarm',18.42], 'F-MCP-GLASS'=>['Fire Alarm',91.79],
    'FMM-101'=>['Fire Alarm',91.79], 'FCM-1'=>['Fire Alarm',150.68], 'ISO-X'=>['Fire Alarm',95.41],
    'LOC-SBB-B4R'=>['Fire Alarm',null],
    'CYL-N'=>['CO2 Suppression',650.00], 'HHF-VP-38'=>['CO2 Suppression',null], 'HH-CH-13'=>['CO2 Suppression',null],
    'HH-CH-20'=>['CO2 Suppression',null], 'HH-DH-17'=>['CO2 Suppression',null], 'PC-T'=>['CO2 Suppression',465.00],
    'MPB'=>['CO2 Suppression',null], 'MPB-CABLE'=>['CO2 Suppression',null], 'DN-UH25'=>['CO2 Suppression',31.00],
    'DN-UH20'=>['CO2 Suppression',17.00], 'DHN-20'=>['CO2 Suppression',130.00], 'DHN-25'=>['CO2 Suppression',null],
    'VIC-COUP-6'=>['Piping & Fittings',79.00], 'UBOLT-2'=>['Piping & Fittings',null],
    'PG-200'=>['Instrumentation',null], 'GI-SHEET-8'=>['Piping & Fittings',null],
];
$u = 0;
foreach ($meta as $code => [$cat, $price]) {
    $it = Db::one("SELECT * FROM catalogue_items WHERE item_code = ?", [$code]);
    if (!$it) continue;
    CatalogueRepo::save(array_merge($it, ['category' => $cat, 'unit_price' => $price]), (int)$it['id']);
    $u++;
}
echo "Catalogue meta updated: {$u} items (category + price)\n";

// --- Demo supplier alias (proves fuzzy line matching) ---------------
$coupling = Db::one("SELECT id FROM catalogue_items WHERE item_code = 'VIC-COUP-6'");
if ($coupling && !Db::one("SELECT id FROM item_supplier_aliases WHERE supplier_id = ? AND supplier_part_code = '022'", [$sch])) {
    Db::insert('item_supplier_aliases', [
        'catalogue_item_id'  => (int)$coupling['id'],
        'supplier_id'        => $sch,
        'supplier_part_code' => '022',
        'supplier_desc'      => '6.5"OD VICTAULIC FIRELOCK COUPLING STYLE 005',
        'desc_norm'          => \App\Support\Normalizer::desc('6.5"OD VICTAULIC FIRELOCK COUPLING STYLE 005'),
        'supplier_uom'       => 'nos',
        'times_confirmed'    => 1,
    ]);
    echo "Alias: Seng Choon '6.5\"OD Victaulic Firelock Coupling' -> VIC-COUP-6\n";
}

// --- Admin user -----------------------------------------------------
$adminEmail = 'simon@fusioneta.com';
if (!Db::one("SELECT id FROM users WHERE email = ?", [$adminEmail])) {
    $pw = bin2hex(random_bytes(5)); // 10-char temp password, shown once
    Db::insert('users', [
        'name' => 'Simon', 'email' => $adminEmail,
        'password_hash' => password_hash($pw, PASSWORD_BCRYPT), 'role' => 'admin',
    ]);
    echo "\n*** ADMIN CREATED ***\n  email: {$adminEmail}\n  temporary password: {$pw}\n  (change it after first login)\n";
} else {
    echo "Admin {$adminEmail} already exists.\n";
}
echo "Seed complete.\n";
