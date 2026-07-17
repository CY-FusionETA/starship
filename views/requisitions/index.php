<?php /** @var array $requisitions @var array $filters @var array $projects @var array $statuses @var int $total */
use App\Csrf; use App\Auth; use App\Icons;
$base = rtrim(parse_url(cfg('app.base_url', ''), PHP_URL_PATH) ?? '', '/');
$isAdmin = Auth::isAdmin();
$statusBadge = function (string $s): string {
    $map = ['draft'=>'muted','approved'=>'brand','partially_ordered'=>'warn','fully_ordered'=>'ok','closed'=>'muted','cancelled'=>'muted'];
    return '<span class="badge ' . ($map[$s] ?? 'muted') . '">' . e(str_replace('_', ' ', $s)) . '</span>';
}; ?>
<div class="toolbar">
  <h1 style="margin:0">Material Requisitions</h1>
  <a class="btn" href="<?= e($base) ?>/requisitions/new">+ New requisition</a>
</div>

<?php
$fbAction = '/requisitions';
$fbFilters = $filters;
$fbPlaceholder = 'Search MR no., requester or project…';
$fbFields = [
    ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'all' => 'All statuses',
     'options' => array_combine($statuses, array_map(fn($s) => ucfirst(str_replace('_', ' ', $s)), $statuses))],
    ['name' => 'project_id', 'label' => 'Project', 'type' => 'select', 'all' => 'All projects',
     'options' => array_column($projects, 'project_code', 'id')],
    ['name' => 'from', 'label' => 'From', 'type' => 'date'],
    ['name' => 'to',   'label' => 'To',   'type' => 'date'],
];
$fbShown = count($requisitions);
$fbTotal = $total;
$fbNoun  = 'requisition';
include VIEW_ROOT . '/partials/filterbar.php';
?>

<div class="card">
  <table>
    <thead><tr><th>MR No.</th><th>Project</th><th>Requested by</th><th>Date</th><th>Lines</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php if (!$requisitions): ?>
      <tr><td colspan="7" class="empty-cell">
        <?= $total > 0
            ? 'No requisitions match these filters. <a href="' . e($base) . '/requisitions">Clear them</a> to see all ' . (int)$total . '.'
            : 'No requisitions yet.' ?>
      </td></tr>
    <?php else: foreach ($requisitions as $r): ?>
      <tr>
        <td><strong><?= e($r['mr_number']) ?></strong>
          <?php if ((int)($r['attachment_count'] ?? 0) > 0): ?>
            <span class="clip-count" title="<?= (int)$r['attachment_count'] ?> quotation<?= (int)$r['attachment_count'] === 1 ? '' : 's' ?> attached"><?= Icons::svg('paperclip', 'clip-ico') ?><?= (int)$r['attachment_count'] ?></span>
          <?php endif; ?>
        </td>
        <td><span class="badge brand"><?= e($r['project_code']) ?></span> <?= e($r['project_name']) ?></td>
        <td><?= e($r['requested_by'] ?: '—') ?></td>
        <td><?= e($r['request_date'] ?: '—') ?></td>
        <td><?= (int)$r['line_count'] ?></td>
        <td><?= $statusBadge($r['status']) ?></td>
        <td class="row-actions">
          <a class="btn sm secondary" href="<?= e($base) ?>/requisitions/<?= (int)$r['id'] ?>">Open</a>
          <?php if ($r['status'] === 'draft'): ?>
            <a class="btn sm ghost" href="<?= e($base) ?>/requisitions/<?= (int)$r['id'] ?>/edit">Edit</a>
          <?php endif; ?>
          <?php if ($isAdmin): ?>
            <form method="post" action="<?= e($base) ?>/requisitions/<?= (int)$r['id'] ?>/delete" onsubmit="return confirm('Delete requisition <?= e($r['mr_number']) ?>? This cannot be undone.')" style="display:inline">
              <?= Csrf::field() ?><button class="btn sm ghost-danger">Delete</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
