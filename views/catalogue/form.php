<?php /** @var ?array $item */
use App\Csrf;
$base = rtrim(parse_url(cfg('app.base_url', ''), PHP_URL_PATH) ?? '', '/');
$v = fn($k) => e($item[$k] ?? ''); ?>
<div class="card" style="max-width:720px">
  <h2><?= $item ? 'Edit item' : 'New catalogue item' ?></h2>
  <form method="post" action="<?= e($base) ?>/catalogue/save">
    <?= Csrf::field() ?>
    <?php if ($item): ?><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><?php endif; ?>
    <div class="row">
      <div><label>Item code *</label><input name="item_code" value="<?= $v('item_code') ?>" required></div>
      <div><label>UOM</label><input name="uom" value="<?= $v('uom') ?>" placeholder="nos / ft / m"></div>
    </div>
    <label>Name *</label><input name="name" value="<?= $v('name') ?>" required>
    <div class="row">
      <div><label>Brand</label><input name="brand" value="<?= $v('brand') ?>"></div>
      <div><label>Model</label><input name="model" value="<?= $v('model') ?>"></div>
    </div>
    <div class="row">
      <div><label>Category</label><input name="category" value="<?= $v('category') ?>" placeholder="Fire Alarm / CO2 Suppression …"></div>
      <div><label>Reference unit price (MYR)</label><input name="unit_price" type="number" step="any" min="0" value="<?= $item && $item['unit_price'] !== null ? e($item['unit_price']) : '' ?>"></div>
    </div>
    <label>Description</label><textarea name="description" rows="2"><?= $v('description') ?></textarea>
    <label>Xero item code</label><input name="xero_item_code" value="<?= $v('xero_item_code') ?>" placeholder="mapped later">
    <div style="margin-top:1.2rem;display:flex;gap:.6rem">
      <button class="btn">Save</button>
      <a class="btn secondary" href="<?= e($base) ?>/catalogue">Cancel</a>
    </div>
  </form>
</div>
