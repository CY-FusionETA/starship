<?php
/** @var string $content  @var string $title */
use App\Auth;
use App\Perm;
use App\Router;
use App\Icons;
use App\Repo\RequisitionRepo;
$base = rtrim(parse_url(cfg('app.base_url', ''), PHP_URL_PATH) ?? '', '/');
$path = Router::path();
$nav = function (string $href, string $label, string $icon, ?int $badge = null) use ($base, $path) {
    $active = ($href === '/' ? $path === '/' : str_starts_with($path, $href)) ? 'active' : '';
    $b = $badge ? ' <span class="nav-badge">' . (int)$badge . '</span>' : '';
    echo '<a class="' . $active . '" href="' . e($base . $href) . '">' . $icon . ' <span>' . e($label) . '</span>' . $b . '</a>';
};
$isAdmin = Auth::isAdmin();
$canApprove = Perm::can('mr_approve');
// The pending badge counts only what this user could actually approve.
$pending = $canApprove ? RequisitionRepo::pendingCount() : 0;
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($title ?: 'Starship') ?> | Starship</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e($base) ?>/public/css/app.css?v=<?= @filemtime(APP_ROOT . '/public/css/app.css') ?: '1' ?>">
</head>
<body>
<div class="app">
  <aside class="sidebar">
    <div class="brand"><span class="logo">St</span> <span>Starship</span></div>
    <div class="tag">Procure</div>
    <nav>
      <?php $nav('/', 'Dashboard', Icons::svg('dashboard')); ?>
      <?php if ($canApprove) $nav('/approvals', 'Approvals', Icons::svg('approvals'), $pending ?: null); ?>
      <?php $nav('/requisitions', 'Requisitions', Icons::svg('requisitions')); ?>
      <?php // Requesters do see POs and DOs for their projects — just not the money on them. ?>
      <?php $nav('/purchase-orders', 'Purchase Orders', Icons::svg('po')); ?>
      <?php $nav('/delivery-orders', 'Delivery Orders', Icons::svg('delivery')); ?>
      <?php if ($isAdmin): ?>
        <div class="tag">System</div>
        <?php $nav('/settings', 'Settings', Icons::svg('settings')); ?>
      <?php endif; ?>
    </nav>
  </aside>
  <div class="main">
    <div class="topbar">
      <div><strong><?= e($title ?: 'Starship') ?></strong></div>
      <div class="who">
        <span class="role-pill <?= $isAdmin ? 'admin' : 'staff' ?> role-<?= e(Auth::role()) ?>"><?= e(Auth::roleLabel()) ?></span>
        <span class="who-name"><?= e(Auth::user()['name'] ?? '') ?></span>
        <a class="signout" href="<?= e($base) ?>/logout">Sign out</a>
      </div>
    </div>
    <div class="content"><?= $content ?></div>
  </div>
</div>

<!-- Cute global loading overlay — shows on any real form submit (Xero pushes,
     approvals, saves) so a slow round-trip feels intentional, not laggy. -->
<div class="ship-loader" id="shipLoader" role="status" aria-live="polite" aria-hidden="true">
  <div class="sl-card">
    <div class="sl-stage"><div class="sl-rocket">🚀</div></div>
    <div class="sl-thrust"><i></i><i></i><i></i></div>
    <div class="sl-msg" id="slMsg"><span>Launching…</span></div>
    <div class="sl-sub">just a moment ✨</div>
  </div>
</div>
<script>
(function(){
  var el = document.getElementById('shipLoader');
  var msgEl = document.getElementById('slMsg');
  var MSGS = [
    "Launching your request into orbit… 🚀",
    "Beaming your PO up to Xero… ✨",
    "Chatting with the accounting robots… 🤖",
    "Packing your purchase order with care… 📦",
    "Doing the boring paperwork for you… 🪄",
    "Zooming through hyperspace… 🌌",
    "Tightening the last little bolt… 🔧",
    "Almost there — you look great today 💫",
  ];
  var showTimer = null, cycleTimer = null;
  function pick(){ return MSGS[Math.floor(Math.random()*MSGS.length)]; }
  function paint(m){ msgEl.innerHTML = '<span>' + m + '</span>'; }
  function show(msg){
    paint(msg || pick());
    el.classList.add('on'); el.setAttribute('aria-hidden','false');
    clearInterval(cycleTimer);
    cycleTimer = setInterval(function(){ paint(pick()); }, 2600);
  }
  function hide(){
    clearTimeout(showTimer); clearInterval(cycleTimer);
    el.classList.remove('on'); el.setAttribute('aria-hidden','true');
  }
  // Delay the reveal so instant navigations never flash the loader.
  function scheduleShow(msg){ clearTimeout(showTimer); showTimer = setTimeout(function(){ show(msg); }, 220); }

  document.addEventListener('submit', function(e){
    if (e.defaultPrevented) return;                    // validation failed / confirm() cancelled
    var f = e.target;
    if (!f || f.nodeName !== 'FORM') return;
    if (f.hasAttribute('data-no-loader')) return;      // opt-out for instant/inline forms
    if (f.getAttribute('target') === '_blank') return; // opens elsewhere, page stays put
    scheduleShow(f.getAttribute('data-loader-msg'));
  }, false);

  // Let AJAX flows drive it too: window.shipLoader.show('…') / .hide()
  window.shipLoader = { show: show, hide: hide, scheduleShow: scheduleShow };
  // Never leave it stuck when arriving via the back/forward cache.
  window.addEventListener('pageshow', hide);
  window.addEventListener('pagehide', hide);
})();
</script>
</body>
</html>
