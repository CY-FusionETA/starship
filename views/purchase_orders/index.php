<?php /** @var array $pos */
$base = rtrim(parse_url(cfg('app.base_url', ''), PHP_URL_PATH) ?? '', '/');
$sb = fn($s) => '<span class="badge ' . (['draft'=>'muted','issued'=>'brand','partially_received'=>'warn','fully_received'=>'ok','closed'=>'muted','cancelled'=>'muted'][$s] ?? 'muted') . '">' . e(str_replace('_',' ',$s)) . '</span>'; ?>
<h1>Purchase Orders</h1>
<div class="card">
  <table>
    <thead><tr><th>PO No.</th><th>Supplier</th><th>Project</th><th>Order date</th><th>Total (MYR)</th><th>Xero</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php if (!$pos): ?><tr><td colspan="8" class="muted">No purchase orders yet.</td></tr>
    <?php else: foreach ($pos as $po): ?>
      <tr>
        <td><strong><?= e($po['po_number']) ?></strong></td>
        <td><?= e($po['supplier_name']) ?></td>
        <td><span class="badge brand"><?= e($po['project_code']) ?></span></td>
        <td><?= e($po['order_date'] ?: '—') ?></td>
        <td><?= $po['total_amount'] !== null ? number_format((float)$po['total_amount'],2) : '—' ?></td>
        <td><?= $po['xero_po_id'] ? '<span class="badge ok">synced</span>' : '<span class="badge muted">stub</span>' ?></td>
        <td><?= $sb($po['status']) ?></td>
        <td><a class="btn sm secondary" href="<?= e($base) ?>/purchase-orders/<?= (int)$po['id'] ?>">Open</a></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
