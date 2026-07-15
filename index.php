<?php
/** Front controller — UI routes. Machine endpoints live in api/. */
define('GLOBE_APP', 1);
require __DIR__ . '/src/bootstrap.php';

use App\Auth;
use App\Csrf;
use App\Response;
use App\Router;
use App\Settings;
use App\Service\Xero\XeroOAuth;
use App\Service\Xero\XeroSync;
use App\Repo\WaSenderRepo;
use App\Service\Wazzup\WazzupClient;
use App\Service\Wazzup\WazzupIntake;
use App\Repo\CatalogueRepo;
use App\Repo\SupplierRepo;
use App\Repo\ProjectRepo;
use App\Repo\AliasRepo;
use App\Repo\RequisitionRepo;
use App\Repo\PurchaseOrderRepo;
use App\Repo\DeliveryOrderRepo;
use App\Service\MatchingService;
use App\Service\GeminiOcrService;
use App\Storage;

/** Parse a loose date string (d/m/Y, Y-m-d, d-m-Y) to Y-m-d, or null. */
function parse_date_loose(string $s): ?string {
    $s = trim($s);
    if ($s === '') return null;
    foreach (['d/m/Y', 'Y-m-d', 'd-m-Y', 'd.m.Y', 'j/n/Y'] as $f) {
        $dt = DateTime::createFromFormat($f, $s);
        if ($dt && $dt->format($f) === $s) return $dt->format('Y-m-d');
    }
    $ts = strtotime($s);
    return $ts ? date('Y-m-d', $ts) : null;
}

Auth::start();
$r = new Router();

// --- Auth -----------------------------------------------------------
$r->get('/login', function () {
    if (Auth::check()) Response::redirect('/');
    Response::partial('auth/login', ['error' => null]);
});
$r->post('/login', function () {
    Csrf::check();
    $ok = Auth::attempt($_POST['email'] ?? '', $_POST['password'] ?? '');
    if ($ok) Response::redirect('/');
    Response::partial('auth/login', ['error' => 'Invalid email or password.']);
});
$r->get('/logout', function () { Auth::logout(); Response::redirect('/login'); });

// --- Dashboard ------------------------------------------------------
$r->get('/', function () {
    Auth::require();
    Response::view('dashboard', [
        'stats' => [
            'catalogue'    => CatalogueRepo::count(),
            'suppliers'    => count(SupplierRepo::all()),
            'projects'     => count(ProjectRepo::all()),
            'requisitions' => RequisitionRepo::count(),
            'pending'      => RequisitionRepo::pendingCount(),
            'pos'          => PurchaseOrderRepo::count(),
        ],
        'pendingReqs' => Auth::isAdmin() ? RequisitionRepo::pending(5) : [],
        'recentReqs'  => RequisitionRepo::recent(6),
    ], 'Dashboard');
});

// --- Approvals (superadmin inbox: incoming requisition requests) -----
$r->get('/approvals', function () {
    Auth::requireRole('admin');
    Response::view('approvals/index', ['pending' => RequisitionRepo::pending(100)], 'Approvals');
});

// --- Catalogue ------------------------------------------------------
$r->get('/catalogue', function () {
    Auth::require();
    $q = trim($_GET['q'] ?? '');
    Response::view('catalogue/index', [
        'items'         => CatalogueRepo::search($q),
        'q'             => $q,
        'xeroConnected' => XeroOAuth::isConnected(),
    ], 'Catalogue');
});
// Pull the product list down from Xero Items (superadmin).
$r->post('/catalogue/xero-sync', function () {
    Auth::requireRole('admin');
    Csrf::check();
    try {
        $r = XeroSync::pullItems();
        Response::redirect('/catalogue?msg=xsync&c=' . (int)$r['created'] . '&u=' . (int)$r['updated']);
    } catch (\Throwable $e) {
        Response::redirect('/catalogue?msg=xerr&e=' . rawurlencode($e->getMessage()));
    }
});
$r->get('/catalogue/search.json', function () {
    Auth::require();
    $items = CatalogueRepo::search(trim($_GET['q'] ?? ''), 60);
    Response::json(['items' => array_map(fn($c) => [
        'id'         => (int)$c['id'],
        'item_code'  => $c['item_code'],
        'name'       => $c['name'],
        'brand'      => $c['brand'],
        'model'      => $c['model'],
        'category'   => $c['category'] ?? null,
        'uom'        => $c['uom'] ?: 'ea',
        'unit_price' => $c['unit_price'] !== null ? (float)$c['unit_price'] : null,
    ], $items)]);
});
$r->get('/catalogue/new', function () {
    Auth::requireRole('staff', 'purchaser', 'admin');
    Response::view('catalogue/form', ['item' => null], 'New item');
});
$r->get('/catalogue/{id}/edit', function ($p) {
    Auth::requireRole('staff', 'purchaser', 'admin');
    $item = CatalogueRepo::find((int)$p['id']);
    if (!$item) Response::notFound();
    Response::view('catalogue/form', ['item' => $item], 'Edit item');
});
$r->post('/catalogue/save', function () {
    Auth::requireRole('staff', 'purchaser', 'admin');
    Csrf::check();
    $id = ($_POST['id'] ?? '') !== '' ? (int)$_POST['id'] : null;
    $savedId = CatalogueRepo::save($_POST, $id);
    // Mirror the product up to Xero's Products & Services (non-blocking).
    if (Settings::bool('xero.enabled') && XeroOAuth::isConnected()) XeroSync::pushItemById($savedId);
    Response::redirect('/catalogue');
});
// Inline quick-add from the requisition builder — creates a catalogue item and
// returns it as JSON so it can be dropped straight into the cart. Staff & up.
$r->post('/catalogue/quick-add.json', function () {
    Auth::requireRole('staff', 'purchaser', 'admin');
    Csrf::check();
    $name = trim($_POST['name'] ?? '');
    if ($name === '') Response::json(['error' => 'Product name is required.'], 422);
    $code = trim($_POST['item_code'] ?? '');
    if ($code === '') $code = CatalogueRepo::suggestCode($name);
    if (CatalogueRepo::codeExists($code)) Response::json(['error' => 'Item code "' . $code . '" already exists.'], 422);
    try {
        $id = CatalogueRepo::save(['item_code' => $code] + $_POST, null);
    } catch (\Throwable $ex) {
        Response::json(['error' => 'Could not save: ' . $ex->getMessage()], 422);
    }
    // Mirror the new product up to Xero (non-blocking — a Xero hiccup must not
    // stop the requisition builder from getting its item back).
    if (Settings::bool('xero.enabled') && XeroOAuth::isConnected()) XeroSync::pushItemById($id);
    $c = CatalogueRepo::find($id);
    Response::json(['item' => [
        'id'         => (int)$c['id'],
        'item_code'  => $c['item_code'],
        'name'       => $c['name'],
        'brand'      => $c['brand'],
        'model'      => $c['model'],
        'category'   => $c['category'] ?? null,
        'uom'        => $c['uom'] ?: 'ea',
        'unit_price' => $c['unit_price'] !== null ? (float)$c['unit_price'] : null,
    ]]);
});
// Delete a catalogue item — superadmin only. Permanent if never used, else archived.
$r->post('/catalogue/{id}/delete', function ($p) {
    Auth::requireRole('admin');
    Csrf::check();
    $outcome = CatalogueRepo::delete((int)$p['id']);
    Response::redirect('/catalogue?msg=' . $outcome);
});

// --- Suppliers ------------------------------------------------------
$r->get('/suppliers', function () {
    Auth::require();
    Response::view('suppliers/index', [
        'suppliers'     => SupplierRepo::all(),
        'xeroConnected' => XeroOAuth::isConnected(),
        'notice'        => $_GET['ok'] ?? null,
        'error'         => $_GET['err'] ?? null,
    ], 'Suppliers');
});
// Pull the supplier list down from Xero Contacts (superadmin).
$r->post('/suppliers/xero-sync', function () {
    Auth::requireRole('admin');
    Csrf::check();
    try {
        $r = XeroSync::pullContacts();
        Response::redirect('/suppliers?ok=' . rawurlencode("Synced from Xero: {$r['created']} new, {$r['updated']} updated ({$r['total']} contacts)."));
    } catch (\Throwable $e) {
        Response::redirect('/suppliers?err=' . rawurlencode('Xero sync failed: ' . $e->getMessage()));
    }
});
$r->get('/suppliers/new', function () {
    Auth::requireRole('staff', 'purchaser', 'admin');
    Response::view('suppliers/form', ['supplier' => null], 'New supplier');
});
$r->get('/suppliers/{id}/edit', function ($p) {
    Auth::requireRole('staff', 'purchaser', 'admin');
    $s = SupplierRepo::find((int)$p['id']);
    if (!$s) Response::notFound();
    Response::view('suppliers/form', ['supplier' => $s], 'Edit supplier');
});
$r->post('/suppliers/save', function () {
    Auth::requireRole('staff', 'purchaser', 'admin');
    Csrf::check();
    $id = ($_POST['id'] ?? '') !== '' ? (int)$_POST['id'] : null;
    SupplierRepo::save($_POST, $id);
    Response::redirect('/suppliers');
});

// --- Projects -------------------------------------------------------
$r->get('/projects', function () {
    Auth::require();
    Response::view('projects/index', [
        'projects'      => ProjectRepo::all(),
        'xeroConnected' => XeroOAuth::isConnected(),
        'notice'        => $_GET['ok'] ?? null,
        'error'         => $_GET['err'] ?? null,
    ], 'Projects');
});
// Pull projects from the Xero "Project" tracking category options (superadmin).
$r->post('/projects/xero-sync', function () {
    Auth::requireRole('admin');
    Csrf::check();
    try {
        $r = XeroSync::pullProjects();
        Response::redirect('/projects?ok=' . rawurlencode("Synced from Xero \"{$r['category']}\" tracking: {$r['created']} new, {$r['updated']} updated."));
    } catch (\Throwable $e) {
        Response::redirect('/projects?err=' . rawurlencode('Xero sync failed: ' . $e->getMessage()));
    }
});
$r->get('/projects/new', function () {
    Auth::requireRole('staff', 'purchaser', 'admin');
    Response::view('projects/form', ['project' => null], 'New project');
});
$r->get('/projects/{id}/edit', function ($p) {
    Auth::requireRole('staff', 'purchaser', 'admin');
    $proj = ProjectRepo::find((int)$p['id']);
    if (!$proj) Response::notFound();
    Response::view('projects/form', ['project' => $proj], 'Edit project');
});
$r->post('/projects/save', function () {
    Auth::requireRole('staff', 'purchaser', 'admin');
    Csrf::check();
    $id = ($_POST['id'] ?? '') !== '' ? (int)$_POST['id'] : null;
    ProjectRepo::save($_POST, $id);
    Response::redirect('/projects');
});

// --- Aliases (read-only list for now) -------------------------------
$r->get('/aliases', function () {
    Auth::require();
    Response::view('aliases/index', ['aliases' => AliasRepo::all()], 'Supplier aliases');
});

// --- Requisitions ---------------------------------------------------
$r->get('/requisitions', function () {
    Auth::require();
    Response::view('requisitions/index', ['requisitions' => RequisitionRepo::all()], 'Requisitions');
});
$r->get('/requisitions/new', function () {
    Auth::requireRole('requester', 'staff', 'purchaser', 'admin');
    Response::view('requisitions/form', ['projects' => ProjectRepo::all(), 'catalogue' => CatalogueRepo::all()], 'New requisition');
});
$r->post('/requisitions/save', function () {
    Auth::requireRole('requester', 'staff', 'purchaser', 'admin');
    Csrf::check();
    $id = RequisitionRepo::create($_POST, $_POST['lines'] ?? []);
    Response::redirect('/requisitions/' . $id);
});
$r->get('/requisitions/{id}', function ($p) {
    Auth::require();
    $req = RequisitionRepo::find((int)$p['id']);
    if (!$req) Response::notFound();
    Response::view('requisitions/show', [
        'req'       => $req,
        'lines'     => RequisitionRepo::lines((int)$p['id']),
        'suppliers' => SupplierRepo::all(),
        'error'     => $_GET['err'] ?? null,
    ], 'MR ' . $req['mr_number']);
});
$r->post('/requisitions/{id}/approve', function ($p) {
    Auth::requireRole('admin'); // superadmin approval gate
    Csrf::check();
    RequisitionRepo::approve((int)$p['id']);
    if (($_POST['return'] ?? '') === 'approvals') Response::redirect('/approvals');
    Response::redirect('/requisitions/' . (int)$p['id']);
});
$r->post('/requisitions/{id}/reject', function ($p) {
    Auth::requireRole('admin');
    Csrf::check();
    RequisitionRepo::reject((int)$p['id']);
    if (($_POST['return'] ?? '') === 'approvals') Response::redirect('/approvals');
    Response::redirect('/requisitions/' . (int)$p['id']);
});
$r->post('/requisitions/{id}/create-po', function ($p) {
    Auth::requireRole('staff', 'purchaser', 'admin');
    Csrf::check();
    $reqId = (int)$p['id'];
    $poNumber = trim($_POST['po_number'] ?? '');
    $supplierId = (int)($_POST['supplier_id'] ?? 0);
    $selected = [];
    foreach (($_POST['poline'] ?? []) as $rlId => $row) {
        if (empty($row['include'])) continue;
        $selected[] = ['requisition_line_id' => (int)$rlId, 'qty' => $row['qty'] ?? 0, 'unit_price' => $row['unit_price'] ?? null];
    }
    $err = null;
    if ($poNumber === '' || $supplierId === 0) $err = 'Supplier and PO number are required.';
    elseif (PurchaseOrderRepo::poNumberExists($poNumber)) $err = 'PO number "' . $poNumber . '" already exists.';
    elseif (!$selected) $err = 'Select at least one line to order.';
    if ($err) Response::redirect('/requisitions/' . $reqId . '?err=' . rawurlencode($err));
    try {
        $poId = PurchaseOrderRepo::createFromRequisition($reqId, $supplierId, $poNumber, $_POST['order_date'] ?? null, $selected);
    } catch (\Throwable $ex) {
        Response::redirect('/requisitions/' . $reqId . '?err=' . rawurlencode($ex->getMessage()));
    }
    Response::redirect('/purchase-orders/' . $poId);
});
$r->get('/requisitions/{id}/edit', function ($p) {
    Auth::requireRole('requester', 'staff', 'purchaser', 'admin');
    $req = RequisitionRepo::find((int)$p['id']);
    if (!$req) Response::notFound();
    if ($req['status'] !== 'draft') Response::redirect('/requisitions/' . (int)$p['id'] . '?err=' . rawurlencode('Only draft requisitions can be edited.'));
    Response::view('requisitions/form', [
        'projects' => ProjectRepo::all(),
        'catalogue' => CatalogueRepo::all(),
        'req'      => $req,
        'lines'    => RequisitionRepo::lines((int)$p['id']),
    ], 'Edit MR ' . $req['mr_number']);
});
$r->post('/requisitions/{id}/update', function ($p) {
    Auth::requireRole('requester', 'staff', 'purchaser', 'admin');
    Csrf::check();
    $id = (int)$p['id'];
    $req = RequisitionRepo::find($id);
    if (!$req) Response::notFound();
    if ($req['status'] !== 'draft') Response::redirect('/requisitions/' . $id . '?err=' . rawurlencode('Only draft requisitions can be edited.'));
    RequisitionRepo::update($id, $_POST, $_POST['lines'] ?? []);
    Response::redirect('/requisitions/' . $id);
});
$r->post('/requisitions/{id}/delete', function ($p) {
    Auth::requireRole('admin');
    Csrf::check();
    try { RequisitionRepo::delete((int)$p['id']); }
    catch (\Throwable $ex) { Response::redirect('/requisitions/' . (int)$p['id'] . '?err=' . rawurlencode($ex->getMessage())); }
    Response::redirect('/requisitions');
});

// --- Purchase Orders ------------------------------------------------
$r->get('/purchase-orders', function () {
    Auth::require();
    Response::view('purchase_orders/index', ['pos' => PurchaseOrderRepo::all()], 'Purchase Orders');
});
$r->get('/purchase-orders/{id}', function ($p) {
    Auth::require();
    $po = PurchaseOrderRepo::find((int)$p['id']);
    if (!$po) Response::notFound();
    Response::view('purchase_orders/show', ['po' => $po, 'lines' => PurchaseOrderRepo::lines((int)$p['id']), 'error' => $_GET['err'] ?? null], 'PO ' . $po['po_number']);
});
// Manual Xero push / retry (superadmin) — for POs raised while Xero was down or disconnected.
$r->post('/purchase-orders/{id}/xero-sync', function ($p) {
    Auth::requireRole('admin');
    Csrf::check();
    $res = PurchaseOrderRepo::syncToXero((int)$p['id']);
    $q = !empty($res['xero_po_id']) ? 'ok' : (!empty($res['stubbed']) ? 'stub' : 'err');
    Response::redirect('/purchase-orders/' . (int)$p['id'] . '?xero=' . $q);
});
$r->post('/purchase-orders/{id}/edit', function ($p) {
    Auth::requireRole('admin');
    Csrf::check();
    try { PurchaseOrderRepo::editHeader((int)$p['id'], $_POST['po_number'] ?? '', ($_POST['order_date'] ?? '') ?: null); }
    catch (\Throwable $ex) { Response::redirect('/purchase-orders/' . (int)$p['id'] . '?err=' . rawurlencode($ex->getMessage())); }
    Response::redirect('/purchase-orders/' . (int)$p['id']);
});
$r->post('/purchase-orders/{id}/delete', function ($p) {
    Auth::requireRole('admin');
    Csrf::check();
    try { PurchaseOrderRepo::delete((int)$p['id']); }
    catch (\Throwable $ex) { Response::redirect('/purchase-orders/' . (int)$p['id'] . '?err=' . rawurlencode($ex->getMessage())); }
    Response::redirect('/purchase-orders');
});

// --- Delivery Orders (capture + 3-way match) ------------------------
$r->get('/delivery-orders', function () {
    Auth::require();
    Response::view('delivery_orders/index', ['dos' => DeliveryOrderRepo::all()], 'Delivery Orders');
});
$r->get('/delivery-orders/new', function () {
    Auth::requireRole('staff', 'purchaser', 'ap', 'admin');
    Response::view('delivery_orders/new', [
        'suppliers' => SupplierRepo::all(),
        'openPos'   => PurchaseOrderRepo::openForSelect(),
        'error'     => $_GET['err'] ?? null,
    ], 'Capture delivery order');
});
$r->post('/delivery-orders/save', function () {
    Auth::requireRole('staff', 'purchaser', 'ap', 'admin');
    Csrf::check();
    if (empty($_FILES['image']['tmp_name']) || !is_uploaded_file($_FILES['image']['tmp_name'])) {
        Response::redirect('/delivery-orders/new?err=' . rawurlencode('Please attach the signed DO image.'));
    }
    $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'pdf'], true)) {
        Response::redirect('/delivery-orders/new?err=' . rawurlencode('Image must be jpg, png, webp or pdf.'));
    }
    $path = Storage::saveImage(file_get_contents($_FILES['image']['tmp_name']), $ext);

    // Optional Gemini OCR: read the DO image into structured fields + lines.
    $ocr = null;
    $useOcr = !empty($_POST['use_ocr']);
    $keyOk = cfg('gemini.api_key') && cfg('gemini.api_key') !== 'x';
    if ($useOcr && $keyOk) {
        try { $ocr = GeminiOcrService::extract(Storage::absPath($path)); }
        catch (\Throwable $e) { error_log('OCR failed: ' . $e->getMessage()); }
    }

    $header = $_POST;
    $header['image_path'] = $path;
    $lines = [];

    if ($ocr) {
        $d = $ocr['data'];
        $pref = fn($cur, $ocrVal) => (trim((string)($cur ?? '')) !== '') ? $cur : ($ocrVal ?? '');
        $header['do_number']         = $pref($header['do_number'] ?? '', $d['do_number'] ?? '');
        $header['po_reference_raw']  = $pref($header['po_reference_raw'] ?? '', $d['po_reference'] ?? '');
        $header['project_code_raw']  = $pref($header['project_code_raw'] ?? '', $d['project_code'] ?? '');
        $header['handwritten_notes'] = $pref($header['handwritten_notes'] ?? '', $d['handwritten_notes'] ?? '');
        if (trim((string)($header['delivery_date'] ?? '')) === '' && !empty($d['delivery_date'])) {
            $header['delivery_date'] = parse_date_loose((string)$d['delivery_date']);
        }
        if (($header['signature_present'] ?? '') === '' && array_key_exists('signature_present', $d)) {
            $header['signature_present'] = !empty($d['signature_present']) ? 1 : 0;
        }
        if (empty($header['supplier_id']) && !empty($d['supplier_name'])) {
            if ($sup = SupplierRepo::matchByName((string)$d['supplier_name'])) $header['supplier_id'] = $sup['id'];
        }
        foreach (($d['line_items'] ?? []) as $li) {
            $lines[] = [
                'ocr_description'   => $li['description'] ?? '',
                'ocr_supplier_code' => $li['supplier_code'] ?? '',
                'ocr_qty'           => $li['qty'] ?? '',
                'ocr_uom'           => $li['uom'] ?? '',
            ];
        }
    } else {
        foreach (($_POST['line'] ?? []) as $row) {
            if (trim($row['ocr_description'] ?? '') !== '') $lines[] = $row;
        }
    }

    $doId = DeliveryOrderRepo::create($header, $lines);
    if ($ocr) {
        DeliveryOrderRepo::setHeader($doId, [
            'ocr_model'      => $ocr['model'],
            'ocr_confidence' => $ocr['confidence'],
            'ocr_raw_json'   => json_encode($ocr['data'], JSON_UNESCAPED_UNICODE),
        ]);
        GeminiOcrService::logRuns($doId, $ocr['runs']);
    }
    MatchingService::suggest($doId);
    Response::redirect('/delivery-orders/' . $doId);
});
$r->get('/delivery-orders/{id}/image', function ($p) {
    Auth::require();
    $do = DeliveryOrderRepo::find((int)$p['id']);
    if (!$do) Response::notFound();
    Storage::stream($do['image_path']);
});
$r->get('/delivery-orders/{id}', function ($p) {
    Auth::require();
    $do = DeliveryOrderRepo::find((int)$p['id']);
    if (!$do) Response::notFound();
    $poLines = !empty($do['purchase_order_id']) ? PurchaseOrderRepo::openLines((int)$do['purchase_order_id']) : [];
    Response::view('delivery_orders/review', [
        'do'      => $do,
        'lines'   => DeliveryOrderRepo::lines((int)$p['id']),
        'poLines' => $poLines,
        'openPos' => PurchaseOrderRepo::openForSelect(),
    ], 'DO ' . ($do['do_number'] ?: $p['id']));
});
$r->post('/delivery-orders/{id}/relink', function ($p) {
    Auth::requireRole('staff', 'purchaser', 'ap', 'admin');
    Csrf::check();
    $poId = (int)($_POST['purchase_order_id'] ?? 0);
    DeliveryOrderRepo::setHeader((int)$p['id'], ['purchase_order_id' => $poId ?: null, 'project_id' => null, 'supplier_id' => null]);
    MatchingService::suggest((int)$p['id']);
    Response::redirect('/delivery-orders/' . (int)$p['id']);
});
$r->post('/delivery-orders/{id}/confirm', function ($p) {
    Auth::requireRole('staff', 'purchaser', 'ap', 'admin');
    Csrf::check();
    MatchingService::commit((int)$p['id'], $_POST['line'] ?? []);
    Response::redirect('/delivery-orders/' . (int)$p['id']);
});
$r->post('/delivery-orders/{id}/edit', function ($p) {
    Auth::requireRole('staff', 'purchaser', 'ap', 'admin');
    Csrf::check();
    DeliveryOrderRepo::editHeader((int)$p['id'], $_POST);
    Response::redirect('/delivery-orders/' . (int)$p['id']);
});
$r->post('/delivery-orders/{id}/delete', function ($p) {
    Auth::requireRole('admin');
    Csrf::check();
    try { DeliveryOrderRepo::delete((int)$p['id']); }
    catch (\Throwable $ex) { Response::redirect('/delivery-orders/' . (int)$p['id'] . '?err=' . rawurlencode($ex->getMessage())); }
    Response::redirect('/delivery-orders');
});

// --- Settings (superadmin: Xero connection & config) ----------------
$r->get('/settings', function () {
    Auth::requireRole('admin');
    Response::view('settings/index', [
        'token'   => XeroOAuth::token(),
        'connected' => XeroOAuth::isConnected(),
        'configured' => XeroOAuth::isConfigured(),
        'enabled' => Settings::bool('xero.enabled'),
        'autosync_last' => Settings::raw('xero.autosync_last'),
        'client_id' => XeroOAuth::clientId(),
        'has_secret' => XeroOAuth::clientSecret() !== '',
        'redirect_uri' => XeroOAuth::redirectUri(),
        'scopes'  => XeroOAuth::scopes(),
        'supplier_group' => (string)Settings::raw('xero.supplier_group', 'Suppliers'),
        // Wazzup WhatsApp hotline
        'wz_configured' => WazzupClient::isConfigured(),
        'wz_enabled'    => WazzupClient::enabled(),
        'wz_api_key'    => WazzupClient::apiKey(),
        'wz_channel'    => WazzupClient::channelId(),
        'wz_number'     => WazzupClient::number(),
        'wz_webhook'    => WazzupIntake::webhookUrl(),
        'senders'       => WaSenderRepo::all(),
        'notice'  => $_GET['ok'] ?? null,
        'error'   => $_GET['err'] ?? null,
    ], 'Settings');
});
$r->post('/settings/save', function () {
    Auth::requireRole('admin');
    Csrf::check();
    Settings::set('xero.client_id', trim($_POST['client_id'] ?? ''));
    // Only overwrite the secret when a new one is typed (blank leaves it as-is).
    if (trim($_POST['client_secret'] ?? '') !== '') Settings::set('xero.client_secret', trim($_POST['client_secret']));
    Settings::set('xero.redirect_uri', trim($_POST['redirect_uri'] ?? ''));
    Settings::set('xero.scopes', trim($_POST['scopes'] ?? '') ?: XeroOAuth::DEFAULT_SCOPES);
    Settings::set('xero.supplier_group', trim($_POST['supplier_group'] ?? ''));
    Settings::set('xero.enabled', isset($_POST['enabled']) ? '1' : '0');
    Response::redirect('/settings?ok=saved');
});
$r->get('/settings/xero/connect', function () {
    Auth::requireRole('admin');
    if (!XeroOAuth::isConfigured()) Response::redirect('/settings?err=' . rawurlencode('Enter your Xero Client ID and Secret first, then Save.'));
    $state = bin2hex(random_bytes(16));
    $_SESSION['xero_oauth_state'] = $state;
    Response::redirect(XeroOAuth::authorizeUrl($state));
});
$r->get('/settings/xero/callback', function () {
    Auth::requireRole('admin');
    if (!empty($_GET['error'])) Response::redirect('/settings?err=' . rawurlencode('Xero: ' . $_GET['error']));
    $state = $_GET['state'] ?? '';
    if (!$state || !hash_equals($_SESSION['xero_oauth_state'] ?? '', (string)$state)) {
        Response::redirect('/settings?err=' . rawurlencode('Security check failed (state mismatch). Please try connecting again.'));
    }
    unset($_SESSION['xero_oauth_state']);
    try {
        $info = XeroOAuth::completeConnection((string)($_GET['code'] ?? ''));
        Settings::set('xero.enabled', '1'); // connecting implies enabling
        Response::redirect('/settings?ok=' . rawurlencode('Connected to ' . ($info['tenant_name'] ?: 'Xero')));
    } catch (\Throwable $e) {
        Response::redirect('/settings?err=' . rawurlencode($e->getMessage()));
    }
});
$r->post('/settings/xero/disconnect', function () {
    Auth::requireRole('admin');
    Csrf::check();
    XeroOAuth::disconnect();
    Response::redirect('/settings?ok=' . rawurlencode('Disconnected from Xero.'));
});

// --- Wazzup WhatsApp hotline (superadmin) ---------------------------
$r->post('/settings/wazzup/save', function () {
    Auth::requireRole('admin');
    Csrf::check();
    if (trim($_POST['api_key'] ?? '') !== '') Settings::set('wazzup.api_key', trim($_POST['api_key'])); // blank keeps existing
    Settings::set('wazzup.channel_id', trim($_POST['channel_id'] ?? ''));
    Settings::set('wazzup.number', preg_replace('/\D+/', '', $_POST['number'] ?? ''));
    Settings::set('wazzup.enabled', isset($_POST['enabled']) ? '1' : '0');
    Response::redirect('/settings?ok=saved#wazzup');
});
$r->post('/settings/wazzup/register-webhook', function () {
    Auth::requireRole('admin');
    Csrf::check();
    $res = WazzupClient::registerWebhook(WazzupIntake::webhookUrl());
    if (!empty($res['ok'])) Response::redirect('/settings?ok=' . rawurlencode('Webhook registered with Wazzup.') . '#wazzup');
    Response::redirect('/settings?err=' . rawurlencode('Webhook registration failed: ' . ($res['error'] ?? 'unknown')) . '#wazzup');
});
$r->post('/settings/senders/add', function () {
    Auth::requireRole('admin');
    Csrf::check();
    $phone = trim($_POST['phone'] ?? '');
    if (WaSenderRepo::normalize($phone) === '') Response::redirect('/settings?err=' . rawurlencode('Enter a valid phone number.') . '#wazzup');
    WaSenderRepo::add($phone, $_POST['name'] ?? '');
    Response::redirect('/settings?ok=' . rawurlencode('Number added.') . '#wazzup');
});
$r->post('/settings/senders/{id}/delete', function ($p) {
    Auth::requireRole('admin');
    Csrf::check();
    WaSenderRepo::delete((int)$p['id']);
    Response::redirect('/settings?ok=' . rawurlencode('Number removed.') . '#wazzup');
});

$r->dispatch();
