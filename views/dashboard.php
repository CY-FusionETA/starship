<?php
/** @var array $kpis @var array $pending @var array $ready @var array $upcoming @var array $overdue @var array $mine */
use App\Auth;
$base = rtrim(parse_url(cfg('app.base_url', ''), PHP_URL_PATH) ?? '', '/');
$isAdmin = Auth::isAdmin();
$name = trim((string)(Auth::user()['name'] ?? ''));
$first = $name ? explode(' ', $name)[0] : '';

/** Urgency → coloured pill. */
$urgBadge = function (?string $u): string {
    if (!$u) return '<span class="badge muted">No urgency</span>';
    $cls = str_starts_with($u, 'ASAP') ? 'danger' : (str_starts_with($u, 'Specify') ? 'warn' : 'muted');
    return '<span class="badge ' . $cls . '">' . e($u) . '</span>';
};
/** Delivery-date → a "due in / overdue" chip driven by days_to_delivery. */
$dueChip = function (array $r): string {
    $d = $r['delivery_date'] ?? '';
    if ($d === '' || $d === null || $r['days_to_delivery'] === null) return '<span class="muted small">No delivery date</span>';
    $n = (int)$r['days_to_delivery'];
    if ($n < 0)   return '<span class="badge danger">Overdue ' . abs($n) . 'd</span>';
    if ($n === 0) return '<span class="badge warn">Due today</span>';
    if ($n <= 3)  return '<span class="badge warn">In ' . $n . 'd</span>';
    return '<span class="badge ok">In ' . $n . 'd</span>';
};
/** One clickable priority row, left-accented by urgency rank. */
$priRow = function (array $r) use ($base, $urgBadge, $dueChip): string {
    $rank = (int)($r['urgency_rank'] ?? 5);
    $wait = (int)($r['days_waiting'] ?? 0);
    $waitTxt = $wait <= 0 ? 'today' : ($wait . 'd ago');
    ob_start(); ?>
    <a class="pri-item u<?= $rank ?>" href="<?= e($base) ?>/requisitions/<?= (int)$r['id'] ?>">
      <span class="pri-mr">MR <?= e($r['mr_number']) ?></span>
      <span class="pri-meta">
        <span class="badge brand"><?= e($r['project_code']) ?></span>
        <?= $urgBadge($r['urgency'] ?? null) ?>
        <span class="muted small"><?= (int)$r['line_count'] ?> lines · <?= e($r['requested_by'] ?: '—') ?></span>
      </span>
      <span class="pri-right">
        <?= $dueChip($r) ?>
        <span class="muted small">submitted <?= $waitTxt ?></span>
      </span>
    </a>
    <?php return ob_get_clean();
};
/** A section list with a heading + empty state. */
$section = function (string $title, string $link, array $rows, string $empty) use ($priRow, $base): string {
    ob_start(); ?>
    <div class="card dash-card">
      <div class="dash-sec-h"><h2><?= $title ?></h2><?php if ($link): ?><a class="small" href="<?= e($base . $link) ?>">View all →</a><?php endif; ?></div>
      <?php if (empty($rows)): ?>
        <div class="dash-empty"><?= $empty ?></div>
      <?php else: ?>
        <div class="pri-list"><?php foreach ($rows as $r) echo $priRow($r); ?></div>
      <?php endif; ?>
    </div>
    <?php return ob_get_clean();
};
/** A KPI tile. */
$kpi = function (string $accent, string $num, string $label, string $sub = '', string $href = '') use ($base): string {
    $tag  = $href ? 'a' : 'div';
    $attr = $href ? ' href="' . e($base . $href) . '"' : '';
    return "<$tag class=\"stat kpi $accent\"$attr><div class=\"num\">" . e($num) . "</div><div class=\"lbl\">" . e($label) . "</div>"
         . ($sub ? "<div class=\"sub\">" . e($sub) . "</div>" : '') . "</$tag>";
};
?>

<div class="hero">
  <div class="hero-txt">
    <div class="hero-kicker"><?= e(Auth::roleLabel()) ?> · Globe Engineering</div>
    <?php if ($isAdmin): ?>
      <h1>Approvals first<?= $first ? ', ' . e($first) : '' ?> 🚦</h1>
      <p><?= (int)$kpis['pending'] ?> requisition<?= $kpis['pending'] == 1 ? '' : 's' ?> awaiting your approval<?php if ($kpis['urgent']): ?> — <strong style="color:#fff"><?= (int)$kpis['urgent'] ?> marked ASAP</strong><?php endif; ?>. Clear the urgent queue to keep the team moving.</p>
    <?php else: ?>
      <h1>Your worklist<?= $first ? ', ' . e($first) : '' ?> 🎯</h1>
      <p><?= (int)$kpis['my_open'] ?> open requisition<?= $kpis['my_open'] == 1 ? '' : 's' ?><?php if ($kpis['due_week']): ?> · <strong style="color:#fff"><?= (int)$kpis['due_week'] ?> due this week</strong><?php endif; ?><?php if ($kpis['overdue']): ?> · <strong style="color:#ffd7d7"><?= (int)$kpis['overdue'] ?> overdue</strong><?php endif; ?>. Stay ahead of the delivery dates.</p>
    <?php endif; ?>
    <div class="hero-cta">
      <a class="btn" href="<?= e($base) ?>/requisitions/new">+ New requisition</a>
      <?php if ($isAdmin && $kpis['pending'] > 0): ?>
        <a class="btn secondary on-dark" href="<?= e($base) ?>/approvals">Review <?= (int)$kpis['pending'] ?> approval<?= $kpis['pending'] == 1 ? '' : 's' ?> →</a>
      <?php endif; ?>
    </div>
  </div>
  <div class="hero-orb"></div>
</div>

<div class="kpi-grid">
  <?php if ($isAdmin): ?>
    <?= $kpi('accent-pending', (string)$kpis['pending'], 'Awaiting approval', $kpis['avg_wait'] > 0 ? ('avg wait ' . $kpis['avg_wait'] . 'd') : 'all clear', '/approvals') ?>
    <?= $kpi('accent-urgent',  (string)$kpis['urgent'],  'Urgent · unapproved', 'ASAP / URGENT', '/approvals') ?>
    <?= $kpi('accent-ok',      (string)$kpis['ready'],   'Approved · to order', 'ready for PO', '/requisitions') ?>
    <?= $kpi('accent-danger',  (string)$kpis['overdue'], 'Overdue deliveries', 'past due date', '/requisitions') ?>
  <?php else: ?>
    <?= $kpi('accent-blue',    (string)$kpis['my_open'], 'My open MRs', 'in progress', '/requisitions') ?>
    <?= $kpi('accent-pending', (string)$kpis['pending'], 'Awaiting approval', 'blocked on PM', '/requisitions') ?>
    <?= $kpi('accent-ok',      (string)$kpis['due_week'],'Due this week', 'plan ahead', '/requisitions') ?>
    <?= $kpi('accent-danger',  (string)$kpis['overdue'], 'Overdue', 'chase now', '/requisitions') ?>
  <?php endif; ?>
</div>

<div class="dash-grid">
  <?php if ($isAdmin): ?>
    <?= $section('🚦 Approval queue — most urgent first', '/approvals', $pending, 'Nothing waiting — the queue is clear. ✅') ?>
    <div class="dash-col">
      <?= $section('📦 Approved · awaiting PO', '/requisitions', $ready, 'No approved MRs waiting on a purchase order.') ?>
      <?= $section('⚠️ Overdue deliveries', '/requisitions', $overdue, 'No overdue deliveries. On track. ✅') ?>
    </div>
  <?php else: ?>
    <?= $section('🎯 My open requisitions', '/requisitions', $mine, 'You have no open requisitions — <a href="' . e($base) . '/requisitions/new">raise one</a>.') ?>
    <div class="dash-col">
      <?= $section('🚚 Upcoming deliveries', '/requisitions', $upcoming, 'No upcoming deliveries scheduled.') ?>
      <?= $section('⚠️ Overdue / at-risk', '/requisitions', $overdue, 'Nothing overdue. Nice. ✅') ?>
    </div>
  <?php endif; ?>
</div>
