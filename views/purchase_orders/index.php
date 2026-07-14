<?php /** @var array $pos */
use App\Csrf; use App\Auth;
$base = rtrim(parse_url(cfg('app.base_url', ''), PHP_URL_PATH) ?? '', '/');
$isAdmin = Auth::isAdmin();
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
        <td class="row-actions">
          <a class="btn sm secondary" href="<?= e($base) ?>/purchase-orders/<?= (int)$po['id'] ?>">Open</a>
          <?php if ($isAdmin): ?>
            <form method="post" action="<?= e($base) ?>/purchase-orders/<?= (int)$po['id'] ?>/delete" onsubmit="return confirm('Delete PO <?= e($po['po_number']) ?>? Ordered quantities on the source requisition will be released.')" style="display:inline">
              <?= Csrf::field() ?><button class="btn sm ghost-danger">Delete</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
