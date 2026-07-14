<?php /** @var array $suppliers @var bool $xeroConnected @var ?string $notice @var ?string $error */
use App\Auth;
use App\Csrf;
$base = rtrim(parse_url(cfg('app.base_url', ''), PHP_URL_PATH) ?? '', '/');
$isAdmin = Auth::isAdmin(); ?>
<?php if (!empty($notice)): ?><div class="notice"><?= e($notice) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="notice err"><?= e($error) ?></div><?php endif; ?>
<div class="toolbar">
  <h1 style="margin:0">Suppliers</h1>
  <div class="row-actions">
    <?php if ($isAdmin && !empty($xeroConnected)): ?>
      <form method="post" action="<?= e($base) ?>/suppliers/xero-sync" style="display:inline">
        <?= Csrf::field() ?><button class="btn sm secondary" title="Pull the contact list from Xero">↻ Sync from Xero</button>
      </form>
    <?php endif; ?>
    <a class="btn" href="<?= e($base) ?>/suppliers/new">+ New supplier</a>
  </div>
</div>
<div class="card">
  <table>
    <thead><tr><th>Name</th><th>Code</th><th>WhatsApp</th><th>MyInvois TIN</th><th>PO format hint</th><th>Xero</th><th></th></tr></thead>
    <tbody>
    <?php if (!$suppliers): ?><tr><td colspan="7" class="muted">No suppliers yet.</td></tr>
    <?php else: foreach ($suppliers as $s): ?>
      <tr>
        <td><?= e($s['name']) ?></td>
        <td><span class="badge muted"><?= e($s['short_code'] ?: '—') ?></span></td>
        <td><?= e($s['whatsapp_e164'] ?: '—') ?></td>
        <td><?= e($s['myinvois_tin'] ?: '—') ?></td>
        <td class="small muted"><?= e($s['po_number_hint'] ?: '—') ?></td>
        <td><?= !empty($s['xero_contact_id']) ? '<span class="badge brand" title="Linked to a Xero contact">✓ Xero</span>' : '<span class="muted">—</span>' ?></td>
        <td><a class="btn sm secondary" href="<?= e($base) ?>/suppliers/<?= (int)$s['id'] ?>/edit">Edit</a></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
<?php if (empty($xeroConnected)): ?><p class="muted small">Connect Xero in <a href="<?= e($base) ?>/settings">Settings</a> to sync suppliers from your Xero contacts.</p><?php endif; ?>
