<?php /** @var array $stats @var array $pendingReqs @var array $recentReqs */
use App\Auth;
$base = rtrim(parse_url(cfg('app.base_url', ''), PHP_URL_PATH) ?? '', '/');
$isAdmin = Auth::isAdmin();
$name = trim((string)(Auth::user()['name'] ?? ''));
$statusBadge = function (string $s): string {
    $map = ['draft'=>'muted','approved'=>'brand','partially_ordered'=>'warn','fully_ordered'=>'ok','closed'=>'muted','cancelled'=>'danger'];
    return '<span class="badge ' . ($map[$s] ?? 'muted') . '">' . e(str_replace('_', ' ', $s)) . '</span>';
}; ?>

<div class="hero">
  <div class="hero-txt">
    <div class="hero-kicker"><?= e(Auth::roleLabel()) ?> · Globe Engineering</div>
    <h1>Welcome back<?= $name ? ', ' . e(explode(' ', $name)[0]) : '' ?> 👋</h1>
    <p>Your procurement backbone — catalogue, requisitions and purchase orders, with AI delivery-order matching.</p>
    <div class="hero-cta">
      <a class="btn" href="<?= e($base) ?>/requisitions/new">+ New requisition</a>
      <?php if ($isAdmin && $stats['pending'] > 0): ?>
        <a class="btn secondary on-dark" href="<?= e($base) ?>/approvals"><?= (int)$stats['pending'] ?> awaiting approval →</a>
      <?php endif; ?>
    </div>
  </div>
  <div class="hero-orb"></div>
</div>

<?php if ($isAdmin && !empty($pendingReqs)): ?>
<div class="card approve-strip">
  <div class="as-head">
    <h2 style="margin:0">⏳ Awaiting your approval</h2>
    <a class="small" href="<?= e($base) ?>/approvals">Open approvals →</a>
  </div>
  <div class="as-list">
    <?php foreach ($pendingReqs as $r): ?>
      <a class="as-item" href="<?= e($base) ?>/requisitions/<?= (int)$r['id'] ?>">
        <span class="as-mr">MR <?= e($r['mr_number']) ?></span>
        <span class="badge brand"><?= e($r['project_code']) ?></span>
        <span class="muted small"><?= (int)$r['line_count'] ?> lines · <?= e($r['requested_by'] ?: '—') ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="grid stat-grid">
  <a class="stat accent-req" href="<?= e($base) ?>/requisitions">
    <div class="stat-ic">▣</div>
    <div class="num"><?= (int)$stats['requisitions'] ?></div><div class="lbl">Requisitions</div>
  </a>
  <a class="stat accent-pending" href="<?= e($base) ?>/<?= $isAdmin ? 'approvals' : 'requisitions' ?>">
    <div class="stat-ic">⏳</div>
    <div class="num"><?= (int)$stats['pending'] ?></div><div class="lbl">Pending approval</div>
  </a>
  <a class="stat accent-po" href="<?= e($base) ?>/purchase-orders">
    <div class="stat-ic">▤</div>
    <div class="num"><?= (int)$stats['pos'] ?></div><div class="lbl">Purchase orders</div>
  </a>
  <a class="stat accent-cat" href="<?= e($base) ?>/catalogue">
    <div class="stat-ic">▥</div>
    <div class="num"><?= (int)$stats['catalogue'] ?></div><div class="lbl">Catalogue items</div>
  </a>
  <a class="stat" href="<?= e($base) ?>/suppliers">
    <div class="stat-ic">◫</div>
    <div class="num"><?= (int)$stats['suppliers'] ?></div><div class="lbl">Suppliers</div>
  </a>
  <a class="stat" href="<?= e($base) ?>/projects">
    <div class="stat-ic">◈</div>
    <div class="num"><?= (int)$stats['projects'] ?></div><div class="lbl">Projects / sites</div>
  </a>
</div>

<div class="card" style="margin-top:1.25rem">
  <div class="as-head"><h2 style="margin:0">Recent requisitions</h2>
    <a class="small" href="<?= e($base) ?>/requisitions">View all →</a></div>
  <table style="margin-top:.4rem">
    <thead><tr><th>MR No.</th><th>Project</th><th>Requested by</th><th>Lines</th><th>Status</th></tr></thead>
    <tbody>
    <?php if (empty($recentReqs)): ?>
      <tr><td colspan="5" class="muted">No requisitions yet — <a href="<?= e($base) ?>/requisitions/new">create the first one</a>.</td></tr>
    <?php else: foreach ($recentReqs as $r): ?>
      <tr onclick="location='<?= e($base) ?>/requisitions/<?= (int)$r['id'] ?>'" style="cursor:pointer">
        <td><strong><?= e($r['mr_number']) ?></strong></td>
        <td><span class="badge brand"><?= e($r['project_code']) ?></span> <?= e($r['project_name']) ?></td>
        <td><?= e($r['requested_by'] ?: '—') ?></td>
        <td><?= (int)$r['line_count'] ?></td>
        <td><?= $statusBadge($r['status']) ?></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
