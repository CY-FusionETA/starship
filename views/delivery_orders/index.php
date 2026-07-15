<?php /** @var array $dos */
use App\Csrf; use App\Auth;
$base = rtrim(parse_url(cfg('app.base_url', ''), PHP_URL_PATH) ?? '', '/');
$isAdmin = Auth::isAdmin();
$sb = fn($s) => '<span class="badge ' . (['received'=>'muted','ocr_done'=>'brand','needs_review'=>'warn','confirmed'=>'brand','matched'=>'ok','exception'=>'danger'][$s] ?? 'muted') . '">' . e(str_replace('_',' ',$s)) . '</span>'; ?>
<div class="toolbar">
  <h1 style="margin:0">Delivery Orders</h1>
  <a class="btn" href="<?= e($base) ?>/delivery-orders/new">+ Capture DO</a>
</div>
<div class="card">
  <table>
    <thead><tr><th>DO No.</th><th>Supplier</th><th>PO</th><th>Received</th><th>Match summary</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php if (!$dos): ?><tr><td colspan="7" class="muted">No delivery orders yet.</td></tr>
    <?php else: foreach ($dos as $d): ?>
      <tr>
        <td><strong><?= e($d['do_number'] ?: '—') ?></strong></td>
        <td><?= e($d['supplier_name'] ?: '—') ?></td>
        <td><?= $d['po_number'] ? '<a href="'.e($base).'/purchase-orders/'.(int)$d['purchase_order_id'].'"><span class="badge brand">'.e($d['po_number']).'</span></a>' : '<span class="muted">—</span>' ?></td>
        <td><?= e($d['delivery_date'] ?: '—') ?></td>
        <td class="small muted"><?= e($d['match_summary'] ?: '—') ?></td>
        <td><?= $sb($d['status']) ?></td>
        <td class="row-actions">
          <a class="btn sm secondary" href="<?= e($base) ?>/delivery-orders/<?= (int)$d['id'] ?>">Review</a>
          <?php if ($isAdmin): ?>
            <form method="post" action="<?= e($base) ?>/delivery-orders/<?= (int)$d['id'] ?>/delete" onsubmit="return confirm('Delete this delivery order? Any posted receipts will be reversed on the PO.')" style="display:inline">
              <?= Csrf::field() ?><button class="btn sm ghost-danger">Delete</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
