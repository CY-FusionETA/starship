<?php /** @var array $events @var array $stats */
$fmtLoc = function (array $e): string {
    if (\App\Service\GeoIp::isPrivate((string)($e['ip'] ?? ''))) return '<span class="muted">local / private</span>';
    $bits = array_filter([$e['city'] ?? '', $e['country'] ?? '']);
    $where = $bits ? e(implode(', ', $bits)) : '<span class="muted">—</span>';
    $isp = trim((string)($e['isp'] ?? ''));
    return $where . ($isp ? '<div class="muted small">' . e($isp) . '</div>' : '');
};
$fmtDevice = function (array $e): string {
    $bits = array_filter([$e['browser'] ?? '', $e['os'] ?? '']);
    $main = $bits ? e(implode(' · ', $bits)) : '<span class="muted">unknown</span>';
    $dt = trim((string)($e['device_type'] ?? ''));
    return $main . ($dt ? ' <span class="badge muted">' . e($dt) . '</span>' : '');
};
?>
<div class="toolbar">
  <div>
    <h1 style="margin:0">Access log</h1>
    <span class="muted small">Every sign-in: which account, from where, on what device. Visible to you only.</span>
  </div>
</div>

<div class="kpi-row" style="display:flex;gap:.8rem;flex-wrap:wrap;margin-bottom:1rem">
  <?php foreach ([
      ['Sign-ins (24h)', $stats['last24']],
      ['Total recorded', $stats['total']],
      ['Distinct accounts', $stats['accounts']],
      ['Distinct IPs', $stats['ips']],
      ['Failed attempts', $stats['failed']],
    ] as [$label, $val]): ?>
    <div class="card" style="flex:1;min-width:130px;padding:.7rem .9rem">
      <div style="font-size:1.5rem;font-weight:800;font-family:'Plus Jakarta Sans',Inter,sans-serif"><?= (int)$val ?></div>
      <div class="muted small"><?= e($label) ?></div>
    </div>
  <?php endforeach; ?>
</div>

<div class="card">
  <table>
    <thead><tr><th>When</th><th>Account</th><th>Result</th><th>IP address</th><th>Location</th><th>Device</th></tr></thead>
    <tbody>
    <?php if (!$events): ?>
      <tr><td colspan="6" class="empty-cell">No sign-ins recorded yet.</td></tr>
    <?php else: foreach ($events as $e): ?>
      <tr>
        <td class="muted small" style="white-space:nowrap"><?= e($e['created_at']) ?></td>
        <td>
          <?php if (!empty($e['user_name'])): ?>
            <strong><?= e($e['user_name']) ?></strong>
            <?php if (!empty($e['user_role'])): ?><span class="badge muted"><?= e($e['user_role']) ?></span><?php endif; ?>
            <div class="muted small"><?= e($e['email']) ?></div>
          <?php else: ?>
            <?= e($e['email'] ?: '—') ?>
          <?php endif; ?>
        </td>
        <td>
          <?php if ((int)$e['success'] === 1): ?><span class="badge ok">success</span>
          <?php else: ?><span class="badge warn">failed</span><?php endif; ?>
        </td>
        <td style="white-space:nowrap"><?= e($e['ip'] ?: '—') ?></td>
        <td><?= $fmtLoc($e) ?></td>
        <td><?= $fmtDevice($e) ?></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
