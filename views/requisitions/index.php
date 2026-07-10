<?php /** @var array $requisitions */
$base = rtrim(parse_url(cfg('app.base_url', ''), PHP_URL_PATH) ?? '', '/');
$statusBadge = function (string $s): string {
    $map = ['draft'=>'muted','approved'=>'brand','partially_ordered'=>'warn','fully_ordered'=>'ok','closed'=>'muted','cancelled'=>'muted'];
    return '<span class="badge ' . ($map[$s] ?? 'muted') . '">' . e(str_replace('_', ' ', $s)) . '</span>';
}; ?>
<div class="toolbar">
  <h1 style="margin:0">Material Requisitions</h1>
  <a class="btn" href="<?= e($base) ?>/requisitions/new">+ New requisition</a>
</div>
<div class="card">
  <table>
    <thead><tr><th>MR No.</th><th>Project</th><th>Requested by</th><th>Date</th><th>Lines</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php if (!$requisitions): ?><tr><td colspan="7" class="muted">No requisitions yet.</td></tr>
    <?php else: foreach ($requisitions as $r): ?>
      <tr>
        <td><strong><?= e($r['mr_number']) ?></strong></td>
        <td><span class="badge brand"><?= e($r['project_code']) ?></span> <?= e($r['project_name']) ?></td>
        <td><?= e($r['requested_by'] ?: '—') ?></td>
        <td><?= e($r['request_date'] ?: '—') ?></td>
        <td><?= (int)$r['line_count'] ?></td>
        <td><?= $statusBadge($r['status']) ?></td>
        <td><a class="btn sm secondary" href="<?= e($base) ?>/requisitions/<?= (int)$r['id'] ?>">Open</a></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
