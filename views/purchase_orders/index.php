<?php /** @var array $pos @var array $filters @var array $suppliers @var array $projects @var array $statuses @var int $total */
use App\Csrf; use App\Auth; use App\Perm;
$base = rtrim(parse_url(cfg('app.base_url', ''), PHP_URL_PATH) ?? '', '/');
$isAdmin = Auth::isAdmin();
// Requesters see the PO exists — number, supplier, quantities, status — but not
// what it costs. The Xero column goes too: it's commercial plumbing.
$showMoney = Perm::can('view_money');
$sb = fn($s) => '<span class="badge ' . (['draft'=>'muted','issued'=>'brand','partially_received'=>'warn','fully_received'=>'ok','closed'=>'muted','cancelled'=>'muted'][$s] ?? 'muted') . '">' . e(str_replace('_',' ',$s)) . '</span>'; ?>
<h1>Purchase Orders</h1>

<?php
$fbAction = '/purchase-orders';
$fbFilters = $filters;
$fbPlaceholder = 'Search PO no., supplier or project…';
$fbFields = [
    ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'all' => 'All statuses',
     'options' => array_combine($statuses, array_map(fn($s) => ucfirst(str_replace('_', ' ', $s)), $statuses))],
    ['name' => 'supplier_id', 'label' => 'Supplier', 'type' => 'select', 'all' => 'All suppliers',
     'options' => array_column($suppliers, 'name', 'id')],
    ['name' => 'project_id', 'label' => 'Project', 'type' => 'select', 'all' => 'All projects',
     'options' => array_column($projects, 'project_code', 'id')],
];
// The Xero filter only makes sense next to the Xero column, which requesters don't get.
if ($showMoney) {
    $fbFields[] = ['name' => 'xero', 'label' => 'Xero', 'type' => 'select', 'all' => 'Xero: any',
                   'options' => ['synced' => 'Xero: synced', 'not_synced' => 'Xero: not synced']];
}
$fbFields[] = ['name' => 'from', 'label' => 'From', 'type' => 'date'];
$fbFields[] = ['name' => 'to',   'label' => 'To',   'type' => 'date'];
$fbShown = count($pos);
$fbTotal = $total;
$fbNoun  = 'purchase order';
include VIEW_ROOT . '/partials/filterbar.php';
?>

<div class="card">
  <table>
    <thead><tr><th>PO No.</th><th>Supplier</th><th>Project</th><th>Order date</th>
      <?php if ($showMoney): ?><th>Total (MYR)</th><th>Xero</th><?php endif; ?>
      <th>Status</th><th></th></tr></thead>
    <tbody>
    <?php if (!$pos): ?>
      <tr><td colspan="<?= $showMoney ? 8 : 6 ?>" class="empty-cell">
        <?= $total > 0
            ? 'No purchase orders match these filters. <a href="' . e($base) . '/purchase-orders">Clear them</a> to see all ' . (int)$total . '.'
            : 'No purchase orders yet.' ?>
      </td></tr>
    <?php else: foreach ($pos as $po): ?>
      <tr>
        <td><strong><?= e($po['po_number']) ?></strong></td>
        <td><?= e($po['supplier_name']) ?></td>
        <td><span class="badge brand"><?= e($po['project_code']) ?></span></td>
        <td><?= e($po['order_date'] ?: '—') ?></td>
        <?php if ($showMoney): ?>
          <td><?= $po['total_amount'] !== null ? number_format((float)$po['total_amount'],2) : '—' ?></td>
          <td><?= $po['xero_po_id'] ? '<span class="badge ok">synced</span>' : '<span class="badge muted">stub</span>' ?></td>
        <?php endif; ?>
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
