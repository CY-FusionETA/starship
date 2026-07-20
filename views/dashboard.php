<?php
/** @var array $kpis @var array $pending @var array $ready @var array $upcoming @var array $overdue @var array $mine @var array $pulse */
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
/** A KPI tile. $key wires the number up to the live pulse refresh. */
$kpi = function (string $accent, string $num, string $label, string $sub = '', string $href = '', string $key = '') use ($base): string {
    $tag  = $href ? 'a' : 'div';
    $attr = $href ? ' href="' . e($base . $href) . '"' : '';
    $data = $key ? ' data-kpi="' . e($key) . '"' : '';
    return "<$tag class=\"stat kpi $accent\"$attr><div class=\"num\"$data>" . e($num) . "</div><div class=\"lbl\">" . e($label) . "</div>"
         . ($sub ? "<div class=\"sub\">" . e($sub) . "</div>" : '') . "</$tag>";
};
/** One stage of the live pipeline strip: ECG heartbeat + count + label. */
$stage = function (string $key, string $label, string $sub, string $href, string $tone = '') use ($base): string {
    return '<a class="pl-stage ' . $tone . '" href="' . e($base . $href) . '">'
         . '<span class="pl-ecg"><svg viewBox="0 0 60 20" preserveAspectRatio="none"><polyline points="0,10 14,10 19,10 23,3 27,17 31,7 34,10 60,10"/></svg></span>'
         . '<span class="pl-num" data-pulse="' . e($key) . '">0</span>'
         . '<span class="pl-lbl">' . e($label) . '</span><span class="pl-sub">' . e($sub) . '</span></a>';
};
?>

<div class="hero">
  <span class="live-pill" title="Numbers refresh automatically"><i></i> LIVE · <span id="liveAgo">just now</span></span>
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
    <?= $kpi('accent-pending', (string)$kpis['pending'], 'Awaiting approval', $kpis['avg_wait'] > 0 ? ('avg wait ' . $kpis['avg_wait'] . 'd') : 'all clear', '/approvals', 'pending') ?>
    <?= $kpi('accent-urgent',  (string)$kpis['urgent'],  'Urgent · unapproved', 'ASAP / URGENT', '/approvals', 'urgent') ?>
    <?= $kpi('accent-ok',      (string)$kpis['ready'],   'Approved · to order', 'ready for PO', '/requisitions', 'ready') ?>
    <?= $kpi('accent-danger',  (string)$kpis['overdue'], 'Overdue deliveries', 'past due date', '/requisitions', 'overdue') ?>
  <?php else: ?>
    <?= $kpi('accent-blue',    (string)$kpis['my_open'], 'My open MRs', 'in progress', '/requisitions', 'my_open') ?>
    <?= $kpi('accent-pending', (string)$kpis['pending'], 'Awaiting approval', 'blocked on PM', '/requisitions', 'pending') ?>
    <?= $kpi('accent-ok',      (string)$kpis['due_week'],'Due this week', 'plan ahead', '/requisitions', 'due_week') ?>
    <?= $kpi('accent-danger',  (string)$kpis['overdue'], 'Overdue', 'chase now', '/requisitions', 'overdue') ?>
  <?php endif; ?>
</div>

<div class="card pulse-card">
  <div class="dash-sec-h">
    <h2>⚡ Pipeline pulse</h2>
    <span class="pulse-note">every process, beating in real time<span class="live-dot"></span></span>
  </div>
  <div class="pipeline">
    <?= $stage('mr_draft',    'MRs raised',   'awaiting approval', $isAdmin ? '/approvals' : '/requisitions') ?>
    <span class="pl-link"><i></i></span>
    <?= $stage('mr_approved', 'Approved',     'ready for PO',      '/requisitions') ?>
    <span class="pl-link"><i></i></span>
    <?= $stage('po_open',     'POs open',     'issued to suppliers','/purchase-orders') ?>
    <span class="pl-link"><i></i></span>
    <?= $stage('do_review',   'DOs in',       'OCR & review',      '/delivery-orders') ?>
    <span class="pl-link"><i></i></span>
    <?= $stage('do_matched',  'Matched',      '3-way match OK',    '/delivery-orders', 'good') ?>
    <span class="pl-link"><i></i></span>
    <?= $stage('exceptions',  'Exceptions',   'need a human',      '/delivery-orders', 'bad') ?>
  </div>
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

<script>
(function () {
  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var data = { kpis: <?= json_encode($kpis) ?>, pulse: <?= json_encode($pulse) ?> };

  /* Animate a number from its current value to `to` (count-up / count-down). */
  function tween(el, to) {
    var from = parseInt(el.textContent, 10) || 0;
    if (reduce || from === to) { el.textContent = to; return; }
    var t0 = performance.now(), dur = 700;
    (function step(t) {
      var p = Math.min((t - t0) / dur, 1), e = 1 - Math.pow(1 - p, 3);
      el.textContent = Math.round(from + (to - from) * e);
      if (p < 1) requestAnimationFrame(step);
    })(t0);
  }
  function flash(el) {
    if (reduce) return;
    el.classList.remove('num-flash'); void el.offsetWidth; el.classList.add('num-flash');
  }

  /* Paint KPI tiles + pipeline stages from a {kpis, pulse} payload. */
  function paint(d, animate) {
    document.querySelectorAll('[data-kpi]').forEach(function (el) {
      var k = el.getAttribute('data-kpi');
      if (!(k in d.kpis)) return;
      var v = parseInt(d.kpis[k], 10) || 0, cur = parseInt(el.textContent, 10) || 0;
      if (animate && v !== cur) flash(el);
      tween(el, v);
      /* Heartbeat ring on the tiles that mean "someone must act now". */
      var tile = el.closest('.stat');
      if (tile && (tile.classList.contains('accent-urgent') || tile.classList.contains('accent-danger')))
        tile.classList.toggle('beat', v > 0 && !reduce);
    });
    document.querySelectorAll('[data-pulse]').forEach(function (el) {
      var k = el.getAttribute('data-pulse');
      if (!(k in d.pulse)) return;
      var v = parseInt(d.pulse[k], 10) || 0, cur = parseInt(el.textContent, 10) || 0;
      if (animate && v !== cur) flash(el);
      tween(el, v);
      var st = el.closest('.pl-stage');
      if (st) st.classList.toggle('calm', v === 0);
    });
  }
  paint(data, false);

  /* "LIVE · updated Xs ago" ticker + gentle background refresh. */
  var agoEl = document.getElementById('liveAgo'), last = Date.now();
  if (agoEl) setInterval(function () {
    var s = Math.round((Date.now() - last) / 1000);
    agoEl.textContent = s < 5 ? 'just now' : s < 60 ? s + 's ago' : Math.round(s / 60) + 'm ago';
  }, 2000);

  var BASE = <?= json_encode($base) ?>;
  function poll() {
    fetch(BASE + '/dashboard/pulse.json', { headers: { 'Accept': 'application/json' } })
      .then(function (r) { if (!r.ok) throw 0; return r.json(); })
      .then(function (d) { last = Date.now(); paint(d, true); })
      .catch(function () { /* offline blip — keep the last numbers */ });
  }
  setInterval(poll, 10000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });
})();
</script>
