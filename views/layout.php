<?php
/** @var string $content  @var string $title */
use App\Auth;
use App\Router;
use App\Repo\RequisitionRepo;
$base = rtrim(parse_url(cfg('app.base_url', ''), PHP_URL_PATH) ?? '', '/');
$path = Router::path();
$nav = function (string $href, string $label, string $icon, ?int $badge = null) use ($base, $path) {
    $active = ($href === '/' ? $path === '/' : str_starts_with($path, $href)) ? 'active' : '';
    $b = $badge ? ' <span class="nav-badge">' . (int)$badge . '</span>' : '';
    echo '<a class="' . $active . '" href="' . e($base . $href) . '">' . $icon . ' <span>' . e($label) . '</span>' . $b . '</a>';
};
$isAdmin = Auth::isAdmin();
$pending = $isAdmin ? RequisitionRepo::pendingCount() : 0;
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($title ?: 'Starship') ?> | Starship</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e($base) ?>/public/css/app.css">
</head>
<body>
<div class="app">
  <aside class="sidebar">
    <div class="brand"><span class="logo">St</span> <span>Starship</span></div>
    <div class="tag">Procure</div>
    <nav>
      <?php $nav('/', 'Dashboard', '▦'); ?>
      <?php if ($isAdmin) $nav('/approvals', 'Approvals', '✔', $pending ?: null); ?>
      <?php $nav('/requisitions', 'Requisitions', '▣'); ?>
      <?php $nav('/purchase-orders', 'Purchase Orders', '▤'); ?>
      <?php $nav('/delivery-orders', 'Delivery Orders', '⇩'); ?>
      <div class="tag">Master data</div>
      <?php $nav('/catalogue', 'Catalogue', '▥'); ?>
      <?php $nav('/suppliers', 'Suppliers', '◫'); ?>
      <?php $nav('/projects', 'Projects', '◈'); ?>
      <?php $nav('/aliases', 'Supplier aliases', '⇄'); ?>
    </nav>
  </aside>
  <div class="main">
    <div class="topbar">
      <div><strong><?= e($title ?: 'Starship') ?></strong></div>
      <div class="who">
        <span class="role-pill <?= $isAdmin ? 'admin' : 'staff' ?>"><?= e(Auth::roleLabel()) ?></span>
        <span class="who-name"><?= e(Auth::user()['name'] ?? '') ?></span>
        <a class="signout" href="<?= e($base) ?>/logout">Sign out</a>
      </div>
    </div>
    <div class="content"><?= $content ?></div>
  </div>
</div>
</body>
</html>
