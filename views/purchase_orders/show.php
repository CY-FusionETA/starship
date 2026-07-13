<?php /** @var array $po @var array $lines */
use App\Auth;
use App\Csrf;
$base = rtrim(parse_url(cfg('app.base_url', ''), PHP_URL_PATH) ?? '', '/');
$sb = fn($s) => '<span class="badge ' . (['open'=>'muted','partially_received'=>'warn','fully_received'=>'ok','over_received'=>'warn','closed'=>'muted'][$s] ?? 'muted') . '">' . e(str_replace('_',' ',$s)) . '</span>';
$xflash = $_GET['xero'] ?? null;
$flashMsg = ['ok'=>'Purchase order pushed to Xero.', 'stub'=>'Xero is not connected — nothing was sent. Connect it in Settings.', 'err'=>'Xero push failed — see the error below.'][$xflash] ?? null; ?>
<?php if ($flashMsg): ?><div class="<?= $xflash==='ok'?'notice':'alert' ?>"><?= e($flashMsg) ?></div><?php endif; ?>
<div class="toolbar">
  <div>
    <h1 style="margin:0">PO <?= e($po['po_number']) ?></h1>
    <span class="muted small"><?= e($po['supplier_name']) ?> · <span class="badge brand"><?= e($po['project_code']) ?></span> <?= e($po['project_name']) ?>
      <?= $po['mr_number'] ? '· from MR ' . e($po['mr_number']) : '' ?> · <?= e($po['order_date'] ?: '') ?></span>
  </div>
  <div>
    <span class="badge <?= ['issued'=>'brand','partially_received'=>'warn','fully_received'=>'ok'][$po['status']] ?? 'muted' ?>"><?= e(str_replace('_',' ',$po['status'])) ?></span>
    <?php if (!empty($po['xero_po_id'])): ?>
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
<?php if (!empty($po['xero_last_error']) && empty($po['xero_po_id'])): ?>
  <div class="alert" style="margin-top:.4rem"><strong>Xero error:</strong> <?= e($po['xero_last_error']) ?></div>
<?php endif; ?>
<div class="card">
  <table>
    <thead><tr><th>#</th><th>Description</th><th>Ordered</th><th>Received</th><th>Balance</th><th>Unit price</th><th>Line total</th><th>Status</th></tr></thead>
    <tbody>
    <?php $tot = 0; foreach ($lines as $l): $tot += (float)$l['line_total']; ?>
      <tr>
        <td><?= (int)$l['line_no'] ?></td>
        <td><?= e($l['description']) ?><?= $l['item_code'] ? ' <span class="badge muted">'.e($l['item_code']).'</span>' : '' ?></td>
        <td><?= rtrim(rtrim(number_format((float)$l['qty_ordered'],2),'0'),'.') ?> <?= e($l['uom']) ?></td>
        <td><?= rtrim(rtrim(number_format((float)$l['qty_received'],2),'0'),'.') ?></td>
        <td><?= rtrim(rtrim(number_format((float)$l['balance_qty'],2),'0'),'.') ?></td>
        <td><?= $l['unit_price'] !== null ? number_format((float)$l['unit_price'],2) : '—' ?></td>
        <td><?= $l['line_total'] !== null ? number_format((float)$l['line_total'],2) : '—' ?></td>
        <td><?= $sb($l['line_status']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><th colspan="6" style="text-align:right">Total (MYR)</th><th><?= number_format($tot,2) ?></th><th></th></tr></tfoot>
  </table>
</div>
<p><a href="<?= e($base) ?>/purchase-orders">← All purchase orders</a></p>
