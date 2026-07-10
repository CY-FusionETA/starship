<?php /** @var array $suppliers */
$base = rtrim(parse_url(cfg('app.base_url', ''), PHP_URL_PATH) ?? '', '/'); ?>
<div class="toolbar">
  <h1 style="margin:0">Suppliers</h1>
  <a class="btn" href="<?= e($base) ?>/suppliers/new">+ New supplier</a>
</div>
<div class="card">
  <table>
    <thead><tr><th>Name</th><th>Code</th><th>WhatsApp</th><th>MyInvois TIN</th><th>PO format hint</th><th></th></tr></thead>
    <tbody>
    <?php if (!$suppliers): ?><tr><td colspan="6" class="muted">No suppliers yet.</td></tr>
    <?php else: foreach ($suppliers as $s): ?>
      <tr>
        <td><?= e($s['name']) ?></td>
        <td><span class="badge muted"><?= e($s['short_code'] ?: '—') ?></span></td>
        <td><?= e($s['whatsapp_e164'] ?: '—') ?></td>
        <td><?= e($s['myinvois_tin'] ?: '—') ?></td>
        <td class="small muted"><?= e($s['po_number_hint'] ?: '—') ?></td>
        <td><a class="btn sm secondary" href="<?= e($base) ?>/suppliers/<?= (int)$s['id'] ?>/edit">Edit</a></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
