<?php /** @var ?array $project */
use App\Csrf;
$base = rtrim(parse_url(cfg('app.base_url', ''), PHP_URL_PATH) ?? '', '/');
$v = fn($k) => e($project[$k] ?? ''); ?>
<div class="card" style="max-width:640px">
  <h2><?= $project ? 'Edit project' : 'New project' ?></h2>
  <form method="post" action="<?= e($base) ?>/projects/save">
    <?= Csrf::field() ?>
    <?php if ($project): ?><input type="hidden" name="id" value="<?= (int)$project['id'] ?>"><?php endif; ?>
    <div class="row">
      <div><label>Project code *</label><input name="project_code" value="<?= $v('project_code') ?>" required placeholder="24B-135494"></div>
      <div><label>Name *</label><input name="name" value="<?= $v('name') ?>" required></div>
    </div>
    <label>Site address</label><textarea name="site_address" rows="2"><?= $v('site_address') ?></textarea>
    <div style="margin-top:1.2rem;display:flex;gap:.6rem">
      <button class="btn">Save</button>
      <a class="btn secondary" href="<?= e($base) ?>/projects">Cancel</a>
    </div>
  </form>
</div>
