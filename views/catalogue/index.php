<?php /** @var array $items  @var string $q */
use App\Auth;
use App\Csrf;
$base = rtrim(parse_url(cfg('app.base_url', ''), PHP_URL_PATH) ?? '', '/');
$isAdmin = Auth::isAdmin();
$msg = $_GET['msg'] ?? '';
$msgText = ['deleted' => 'Product permanently deleted.', 'archived' => 'Product was in use, so it was archived (hidden) to keep history intact.'][$msg] ?? ''; ?>
<?php if ($msgText): ?><div class="notice"><?= e($msgText) ?></div><?php endif; ?>
<div class="toolbar">
  <form class="search" method="get" action="<?= e($base) ?>/catalogue">
    <input name="q" value="<?= e($q) ?>" placeholder="Search by brand, model, code, name or description…" autofocus>
  </form>
  <a class="btn" href="<?= e($base) ?>/catalogue/new">+ New item</a>
</div>
<div class="card">
  <table>
    <thead><tr><th>Code</th><th>Name</th><th>Brand</th><th>Category</th><th>UOM</th><th>Ref price</th><th></th></tr></thead>
    <tbody>
    <?php if (!$items): ?>
      <tr><td colspan="7" class="muted">No items<?= $q !== '' ? ' matching "' . e($q) . '"' : '' ?>.</td></tr>
    <?php else: foreach ($items as $it): ?>
      <tr>
        <td><span class="badge code"><?= e($it['item_code']) ?></span></td>
        <td><?= e($it['name']) ?><?= !empty($it['model']) ? ' <span class="muted small">'.e($it['model']).'</span>' : '' ?></td>
        <td><?= $it['brand'] ? '<span class="badge brand">' . e($it['brand']) . '</span>' : '<span class="muted">—</span>' ?></td>
        <td><?= e(($it['category'] ?? '') ?: '—') ?></td>
        <td><?= e($it['uom'] ?: '—') ?></td>
        <td><?= isset($it['unit_price']) && $it['unit_price'] !== null ? 'RM ' . number_format((float)$it['unit_price'], 2) : '<span class="muted">—</span>' ?></td>
        <td class="row-actions">
          <a class="btn sm secondary" href="<?= e($base) ?>/catalogue/<?= (int)$it['id'] ?>/edit">Edit</a>
          <?php if ($isAdmin): ?>
          <form method="post" action="<?= e($base) ?>/catalogue/<?= (int)$it['id'] ?>/delete"
                onsubmit="return confirm('Delete this product? If it has been used on a requisition or PO it will be archived instead of removed.')" style="display:inline">
            <?= Csrf::field() ?><button class="btn sm ghost-danger">Delete</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
<p class="muted small"><?= count($items) ?> item(s) shown.<?= $isAdmin ? '' : ' Only a superadmin can delete products.' ?></p>
