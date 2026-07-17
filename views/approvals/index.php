<?php /** @var array $pending */
use App\Csrf;
$base = rtrim(parse_url(cfg('app.base_url', ''), PHP_URL_PATH) ?? '', '/'); ?>
<div class="toolbar">
  <div>
    <h1 style="margin:0">Approvals</h1>
    <span class="muted small">Incoming material requisitions awaiting your sign-off</span>
  </div>
  <span class="badge <?= $pending ? 'warn' : 'ok' ?>" style="font-size:.85rem">
    <?= count($pending) ?> pending
  </span>
</div>

<?php if (!$pending): ?>
  <div class="card empty-hero">
    <div class="empty-emoji">✓</div>
    <h2>All caught up</h2>
    <p class="muted">There are no requisitions waiting for approval right now.</p>
    <a class="btn secondary" href="<?= e($base) ?>/requisitions">View all requisitions</a>
  </div>
<?php else: ?>
  <div class="approval-grid">
    <?php foreach ($pending as $r): ?>
      <div class="approval-card">
        <div class="ac-top">
          <div>
            <div class="ac-mr">MR <?= e($r['mr_number']) ?></div>
            <div class="ac-proj"><span class="badge brand"><?= e($r['project_code']) ?></span> <?= e($r['project_name']) ?></div>
          </div>
          <span class="ac-lines"><?= (int)$r['line_count'] ?> line<?= (int)$r['line_count'] === 1 ? '' : 's' ?></span>
        </div>
        <div class="ac-meta">
          <span>👤 <?= e($r['requested_by'] ?: ($r['created_by_name'] ?? '—')) ?></span>
          <span>🗓 <?= e($r['request_date'] ?: '—') ?></span>
          <span>🚚 <?= e($r['delivery_date'] ?: 'A.S.A.P.') ?></span>
        </div>
        <div class="ac-actions">
          <a class="btn sm secondary" href="<?= e($base) ?>/requisitions/<?= (int)$r['id'] ?>">Review</a>
          <form method="post" action="<?= e($base) ?>/requisitions/<?= (int)$r['id'] ?>/reject" onsubmit="return confirm('Reject MR <?= e($r['mr_number']) ?>?')" style="display:inline">
            <?= Csrf::field() ?><input type="hidden" name="return" value="approvals">
            <button class="btn sm ghost-danger">Reject</button>
          </form>
          <form method="post" action="<?= e($base) ?>/requisitions/<?= (int)$r['id'] ?>/approve" style="display:inline" data-loader-msg="Approving &amp; sending your PO to Xero… 🚀">
            <?= Csrf::field() ?><input type="hidden" name="return" value="approvals">
            <button class="btn sm">Approve ✓</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
