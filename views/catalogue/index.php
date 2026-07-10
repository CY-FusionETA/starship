<?php /** @var array $items  @var string $q */
$base = rtrim(parse_url(cfg('app.base_url', ''), PHP_URL_PATH) ?? '', '/'); ?>
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
        <td><a class="btn sm secondary" href="<?= e($base) ?>/catalogue/<?= (int)$it['id'] ?>/edit">Edit</a></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
<p class="muted small"><?= count($items) ?> item(s) shown.</p>
