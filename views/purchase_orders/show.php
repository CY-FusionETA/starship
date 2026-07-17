<?php /** @var array $po @var array $lines @var ?string $error */
use App\Auth;
use App\Perm;
use App\Csrf;
$base = rtrim(parse_url(cfg('app.base_url', ''), PHP_URL_PATH) ?? '', '/');
// Requesters track their order — quantities, balances, status — without the
// commercial terms: unit price, line totals, PO total, bills, Xero state.
$showMoney = Perm::can('view_money');
$sb = fn($s) => '<span class="badge ' . (['open'=>'muted','partially_received'=>'warn','fully_received'=>'ok','over_received'=>'warn','closed'=>'muted'][$s] ?? 'muted') . '">' . e(str_replace('_',' ',$s)) . '</span>';
$xflash = $_GET['xero'] ?? null;
$flashMsg = ['ok'=>'Purchase order pushed to Xero.', 'stub'=>'Xero is not connected — nothing was sent. Connect it in Settings.', 'err'=>'Xero push failed — see the error below.'][$xflash] ?? null; ?>
<?php if ($flashMsg): ?><div class="<?= $xflash==='ok'?'notice':'alert' ?>"><?= e($flashMsg) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert"><?= e($error) ?></div><?php endif; ?>
<div class="toolbar">
  <div>
    <h1 style="margin:0">PO <?= e($po['po_number']) ?></h1>
    <span class="muted small"><?= e($po['supplier_name']) ?> · <span class="badge brand"><?= e($po['project_code']) ?></span> <?= e($po['project_name']) ?>
      <?= $po['mr_number'] ? '· from MR ' . e($po['mr_number']) : '' ?> · <?= e($po['order_date'] ?: '') ?></span>
  </div>
  <div>
    <span class="badge <?= ['issued'=>'brand','partially_received'=>'warn','fully_received'=>'ok'][$po['status']] ?? 'muted' ?>"><?= e(str_replace('_',' ',$po['status'])) ?></span>
    <?php if (!$showMoney): /* no Xero state for requesters */ ?>
    <?php elseif (!empty($po['xero_po_id'])): ?>
      <span class="badge ok" title="Xero PO <?= e($po['xero_po_id']) ?>">✓ Xero synced</span>
    <?php elseif (!empty($po['xero_last_error'])): ?>
      <span class="badge danger">Xero: failed</span>
      <?php if (Auth::isAdmin()): ?>
        <form method="post" action="<?= e($base) ?>/purchase-orders/<?= (int)$po['id'] ?>/xero-sync" style="display:inline">
          <?= Csrf::field() ?><button class="btn sm">Retry Xero</button>
        </form>
      <?php endif; ?>
    <?php else: ?>
      <span class="badge muted">Xero: not synced</span>
      <?php if (Auth::isAdmin()): ?>
        <form method="post" action="<?= e($base) ?>/purchase-orders/<?= (int)$po['id'] ?>/xero-sync" style="display:inline">
          <?= Csrf::field() ?><button class="btn sm">Sync to Xero</button>
        </form>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
<?php if (!empty($po['xero_last_error']) && empty($po['xero_po_id']) && $showMoney): ?>
  <div class="alert" style="margin-top:.4rem"><strong>Xero error:</strong> <?= e($po['xero_last_error']) ?></div>
<?php endif; ?>
<div class="card">
  <table>
    <thead><tr><th>#</th><th>Description</th><th>Ordered</th><th>Received</th><th>Balance</th>
      <?php if ($showMoney): ?><th>Unit price</th><th>Line total</th><?php endif; ?>
      <th>Status</th></tr></thead>
    <tbody>
    <?php $tot = 0; foreach ($lines as $l): $tot += (float)$l['line_total']; ?>
      <tr>
        <td><?= (int)$l['line_no'] ?></td>
        <td><?= e($l['description']) ?><?= $l['item_code'] ? ' <span class="badge muted">'.e($l['item_code']).'</span>' : '' ?></td>
        <td><?= rtrim(rtrim(number_format((float)$l['qty_ordered'],2),'0'),'.') ?> <?= e($l['uom']) ?></td>
        <td><?= rtrim(rtrim(number_format((float)$l['qty_received'],2),'0'),'.') ?></td>
        <td><?= rtrim(rtrim(number_format((float)$l['balance_qty'],2),'0'),'.') ?></td>
        <?php if ($showMoney): ?>
          <td><?= $l['unit_price'] !== null ? number_format((float)$l['unit_price'],2) : '—' ?></td>
          <td><?= $l['line_total'] !== null ? number_format((float)$l['line_total'],2) : '—' ?></td>
        <?php endif; ?>
        <td><?= $sb($l['line_status']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <?php if ($showMoney): ?>
      <tfoot><tr><th colspan="6" style="text-align:right">Total (MYR)</th><th><?= number_format($tot,2) ?></th><th></th></tr></tfoot>
    <?php endif; ?>
  </table>
</div>

<?php
$fmtq = fn($n) => rtrim(rtrim(number_format((float)$n, 2), '0'), '.');
$doBadge = fn($s) => '<span class="badge ' . (['received'=>'muted','ocr_done'=>'brand','needs_review'=>'warn','confirmed'=>'brand','matched'=>'ok','exception'=>'danger'][$s] ?? 'muted') . '">' . e(str_replace('_',' ',$s)) . '</span>';
$pct = $fulfil['ordered'] > 0 ? (int)min(100, round($fulfil['received'] / $fulfil['ordered'] * 100)) : 0;
$fullyDone = $fulfil['outstanding'] <= 1e-6 && $fulfil['ordered'] > 0;
?>
<div class="card">
  <div class="dash-sec-h" style="margin-bottom:.7rem">
    <h2 style="margin:0;font-size:1.05rem">📦 Deliveries<?= $showMoney ? ' &amp; invoices' : '' ?></h2>
    <span class="muted small"><?= count($dos) ?> DO<?= count($dos) == 1 ? '' : 's' ?><?= $showMoney
        ? ' · ' . count($bills) . ' invoice' . (count($bills) == 1 ? '' : 's') : '' ?></span>
  </div>

  <div class="po-fulfil">
    <div><span class="fu-num"><?= $fmtq($fulfil['ordered']) ?></span><span class="fu-lbl">Ordered</span></div>
    <div><span class="fu-num ok"><?= $fmtq($fulfil['received']) ?></span><span class="fu-lbl">Received</span></div>
    <div><span class="fu-num <?= $fulfil['outstanding'] > 1e-6 ? 'warn' : '' ?>"><?= $fmtq($fulfil['outstanding']) ?></span><span class="fu-lbl">Outstanding</span></div>
    <div><span class="fu-num"><?= (int)$fulfil['open_lines'] ?>/<?= (int)$fulfil['lines'] ?></span><span class="fu-lbl">Lines not delivered</span></div>
  </div>
  <div class="po-bar"><span style="width:<?= $pct ?>%"></span></div>
  <p class="muted small" style="margin:.35rem 0 0"><?= $pct ?>% delivered<?= $fullyDone ? ' · fully received ✅' : ($fulfil['outstanding'] > 1e-6 ? ' · ' . $fmtq($fulfil['outstanding']) . ' still on backorder' : '') ?></p>

  <?php if ($dos): ?>
  <table style="margin-top:1.1rem">
    <thead><tr><th>DO No.</th><th>Date</th><th>Qty received</th><th>Lines</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($dos as $d): ?>
      <tr>
        <td><strong><?= e($d['do_number'] ?: ('#' . $d['id'])) ?></strong></td>
        <td><?= e($d['delivery_date'] ?: '—') ?></td>
        <td><?= $fmtq($d['qty_received']) ?></td>
        <td><?= (int)$d['line_count'] ?></td>
        <td><?= $doBadge($d['status']) ?></td>
        <td style="text-align:right"><a class="btn sm secondary" href="<?= e($base) ?>/delivery-orders/<?= (int)$d['id'] ?>">Open →</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
    <p class="muted small" style="margin:.9rem 0 0">No delivery orders booked against this PO yet.</p>
  <?php endif; ?>

  <?php if ($showMoney): /* invoices are money — requesters don't get this block */ ?>
  <h3 style="margin:1.3rem 0 .4rem;font-size:.98rem">🧾 Invoices</h3>
  <?php if ($bills): ?>
  <table>
    <thead><tr><th>Invoice</th><th>Date</th><th>Total (MYR)</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($bills as $b): ?>
      <tr>
        <td><strong><?= e($b['invoice_number'] ?: ('#' . $b['id'])) ?></strong></td>
        <td><?= e($b['invoice_date'] ?: '—') ?></td>
        <td><?= $b['total_amount'] !== null ? number_format((float)$b['total_amount'], 2) : '—' ?></td>
        <td><span class="badge muted"><?= e($b['status']) ?></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
    <p class="muted small" style="margin:0">No invoices recorded against this PO yet — invoice capture is a later phase.</p>
  <?php endif; ?>
  <?php endif; /* $showMoney */ ?>
</div>

<?php if (Auth::isAdmin()): ?>
<div class="card">
  <details>
    <summary style="cursor:pointer;font-weight:600">Edit / delete this PO</summary>
    <form method="post" action="<?= e($base) ?>/purchase-orders/<?= (int)$po['id'] ?>/edit" class="row" style="align-items:flex-end;margin-top:1rem">
      <?= Csrf::field() ?>
      <div><label>PO number</label><input name="po_number" value="<?= e($po['po_number']) ?>" required></div>
      <div><label>Order date</label><input name="order_date" type="date" value="<?= e($po['order_date']) ?>"></div>
      <div style="flex:0"><button class="btn secondary">Save changes</button></div>
    </form>
    <?php if (!empty($po['xero_po_id'])): ?>
      <p class="muted small" style="margin:.6rem 0 0">Note: this PO is already synced to Xero — edits here do not update the Xero copy.</p>
    <?php endif; ?>
    <hr style="border:none;border-top:1px solid var(--fe-border);margin:1rem 0">
    <form method="post" action="<?= e($base) ?>/purchase-orders/<?= (int)$po['id'] ?>/delete" onsubmit="return confirm('Delete PO <?= e($po['po_number']) ?>? Ordered quantities on the source requisition will be released. This cannot be undone.')">
      <?= Csrf::field() ?><button class="btn ghost-danger">Delete purchase order</button>
      <span class="muted small">Blocked if goods have already been delivered against it.</span>
    </form>
  </details>
</div>
<?php endif; ?>
<p><a href="<?= e($base) ?>/purchase-orders">← All purchase orders</a></p>
