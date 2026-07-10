<?php /** @var ?array $supplier */
use App\Csrf;
$base = rtrim(parse_url(cfg('app.base_url', ''), PHP_URL_PATH) ?? '', '/');
$v = fn($k) => e($supplier[$k] ?? ''); ?>
<div class="card" style="max-width:720px">
  <h2><?= $supplier ? 'Edit supplier' : 'New supplier' ?></h2>
  <form method="post" action="<?= e($base) ?>/suppliers/save">
    <?= Csrf::field() ?>
    <?php if ($supplier): ?><input type="hidden" name="id" value="<?= (int)$supplier['id'] ?>"><?php endif; ?>
    <div class="row">
      <div><label>Name *</label><input name="name" value="<?= $v('name') ?>" required></div>
      <div><label>Short code</label><input name="short_code" value="<?= $v('short_code') ?>"></div>
    </div>
    <div class="row">
      <div><label>Phone</label><input name="phone" value="<?= $v('phone') ?>"></div>
      <div><label>WhatsApp (E.164)</label><input name="whatsapp_e164" value="<?= $v('whatsapp_e164') ?>" placeholder="60xxxxxxxxx"></div>
    </div>
    <label>Email</label><input name="email" type="email" value="<?= $v('email') ?>">
    <div class="row">
      <div><label>SST reg. no.</label><input name="sst_reg_no" value="<?= $v('sst_reg_no') ?>"></div>
      <div><label>MyInvois TIN</label><input name="myinvois_tin" value="<?= $v('myinvois_tin') ?>"></div>
    </div>
    <label>PO number format hint</label><input name="po_number_hint" value="<?= $v('po_number_hint') ?>">
    <div style="margin-top:1.2rem;display:flex;gap:.6rem">
      <button class="btn">Save</button>
      <a class="btn secondary" href="<?= e($base) ?>/suppliers">Cancel</a>
    </div>
  </form>
</div>
