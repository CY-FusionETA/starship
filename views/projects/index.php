<?php /** @var array $projects @var bool $xeroConnected @var ?string $notice @var ?string $error */
use App\Auth;
use App\Csrf;
$base = rtrim(parse_url(cfg('app.base_url', ''), PHP_URL_PATH) ?? '', '/');
$isAdmin = Auth::isAdmin(); ?>
<?php if (!empty($notice)): ?><div class="notice"><?= e($notice) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="notice err"><?= e($error) ?></div><?php endif; ?>
<div class="toolbar">
  <h1 style="margin:0">Projects / sites</h1>
  <div class="row-actions">
    <?php if ($isAdmin && !empty($xeroConnected)): ?>
      <form method="post" action="<?= e($base) ?>/projects/xero-sync" style="display:inline">
        <?= Csrf::field() ?><button class="btn sm secondary" title="Pull projects from the Xero &quot;Project&quot; tracking category">↻ Sync from Xero</button>
      </form>
    <?php endif; ?>
    <a class="btn" href="<?= e($base) ?>/projects/new">+ New project</a>
  </div>
</div>
<div class="card">
  <table>
    <thead><tr><th>Code</th><th>Name</th><th>Site address</th><th>Xero</th><th></th></tr></thead>
    <tbody>
    <?php if (!$projects): ?><tr><td colspan="5" class="muted">No projects yet.</td></tr>
    <?php else: foreach ($projects as $p): ?>
      <tr>
        <td><span class="badge brand"><?= e($p['project_code']) ?></span></td>
        <td><?= e($p['name']) ?></td>
        <td class="small muted"><?= e($p['site_address'] ?: '—') ?></td>
        <td><?= !empty($p['xero_tracking_option_id']) ? '<span class="badge brand" title="Linked to a Xero tracking option">✓ Xero</span>' : '<span class="muted">—</span>' ?></td>
        <td><a class="btn sm secondary" href="<?= e($base) ?>/projects/<?= (int)$p['id'] ?>/edit">Edit</a></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
<?php if (empty($xeroConnected)): ?><p class="muted small">Connect Xero in <a href="<?= e($base) ?>/settings">Settings</a> to sync projects from your Xero tracking categories.</p><?php endif; ?>
