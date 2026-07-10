<?php /** @var array $projects */
$base = rtrim(parse_url(cfg('app.base_url', ''), PHP_URL_PATH) ?? '', '/'); ?>
<div class="toolbar">
  <h1 style="margin:0">Projects / sites</h1>
  <a class="btn" href="<?= e($base) ?>/projects/new">+ New project</a>
</div>
<div class="card">
  <table>
    <thead><tr><th>Code</th><th>Name</th><th>Site address</th><th></th></tr></thead>
    <tbody>
    <?php if (!$projects): ?><tr><td colspan="4" class="muted">No projects yet.</td></tr>
    <?php else: foreach ($projects as $p): ?>
      <tr>
        <td><span class="badge brand"><?= e($p['project_code']) ?></span></td>
        <td><?= e($p['name']) ?></td>
        <td class="small muted"><?= e($p['site_address'] ?: '—') ?></td>
        <td><a class="btn sm secondary" href="<?= e($base) ?>/projects/<?= (int)$p['id'] ?>/edit">Edit</a></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
