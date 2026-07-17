<?php /** @var array $req @var array $lines @var array $attachments @var ?string $error @var ?string $ok */
use App\Auth;
use App\Perm;
use App\Csrf;
use App\Icons;
$base = rtrim(parse_url(cfg('app.base_url', ''), PHP_URL_PATH) ?? '', '/');
$sbadge = fn($s) => '<span class="badge ' . (['open'=>'muted','partially_ordered'=>'warn','fully_ordered'=>'ok','cancelled'=>'muted'][$s] ?? 'muted') . '">' . e(str_replace('_',' ',$s)) . '</span>'; ?>

<?php if ($error): ?><div class="alert"><?= e($error) ?></div><?php endif; ?>
<?php if (!empty($ok)): ?><div class="alert ok">✓ <?= e($ok) ?></div><?php endif; ?>

<div class="toolbar">
  <div>
    <h1 style="margin:0">MR <?= e($req['mr_number']) ?></h1>
    <span class="muted small"><span class="badge brand"><?= e($req['project_code']) ?></span> <?= e($req['project_name']) ?>
      · requested by <?= e($req['requested_by'] ?: '—') ?><?php if (!empty($req['requester_mobile'])): ?> · 📱 <?= e($req['requester_mobile']) ?><?php endif; ?><?php if (!empty($req['requester_email'])): ?> · ✉️ <?= e($req['requester_email']) ?><?php endif; ?>
      · submitted <?= e($req['request_date'] ?: '—') ?>
      <?php if (!empty($req['urgency'])): ?> · <span class="badge <?= str_starts_with((string)$req['urgency'], 'ASAP') ? 'danger' : 'warn' ?>"><?= e($req['urgency']) ?></span><?php endif; ?>
      <?php if (!empty($req['delivery_date'])): ?> · delivery <?= e($req['delivery_date']) ?><?php endif; ?></span>
  </div>
  <div>
    <span class="badge <?= ['draft'=>'muted','approved'=>'brand','partially_ordered'=>'warn','fully_ordered'=>'ok'][$req['status']] ?? 'muted' ?>" style="font-size:.8rem"><?= e(str_replace('_',' ',$req['status'])) ?></span>
    <?php if ($req['status'] === 'draft' && Perm::can('mr_edit')): ?>
      <a class="btn sm ghost" href="<?= e($base) ?>/requisitions/<?= (int)$req['id'] ?>/edit">Edit</a>
    <?php endif; ?>
    <?php if (Auth::isAdmin()): ?>
      <form method="post" action="<?= e($base) ?>/requisitions/<?= (int)$req['id'] ?>/delete" onsubmit="return confirm('Delete requisition <?= e($req['mr_number']) ?>? This cannot be undone.')" style="display:inline">
        <?= Csrf::field() ?><button class="btn sm ghost-danger">Delete</button>
      </form>
    <?php endif; ?>
    <?php if ($req['status'] === 'draft'): ?>
      <?php if (Perm::can('mr_approve')): ?>
        <form method="post" action="<?= e($base) ?>/requisitions/<?= (int)$req['id'] ?>/reject" onsubmit="return confirm('Reject this requisition?')" style="display:inline">
          <?= Csrf::field() ?><button class="btn sm ghost-danger">Reject</button>
        </form>
        <form method="post" action="<?= e($base) ?>/requisitions/<?= (int)$req['id'] ?>/approve" style="display:inline" data-loader-msg="Approving &amp; sending your PO to Xero… 🚀">
          <?= Csrf::field() ?><button class="btn sm">Approve ✓</button>
        </form>
      <?php else: ?>
        <span class="badge warn" style="font-size:.8rem">⏳ Awaiting PM approval</span>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <h3>Requisition lines</h3>
  <table>
    <thead><tr><th>#</th><th>Particulars</th><th>Model/Type</th><th>Qty</th><th>Ordered</th><th>Remaining</th><th>PO ref(s)</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($lines as $l): ?>
      <tr>
        <td><?= (int)$l['line_no'] ?></td>
        <td><?= e($l['raw_description']) ?><?= $l['item_code'] ? ' <span class="badge muted">'.e($l['item_code']).'</span>' : '' ?></td>
        <td><?= e($l['model_type'] ?: '—') ?></td>
        <td><?= rtrim(rtrim(number_format((float)$l['qty'],2),'0'),'.') ?> <?= e($l['uom']) ?></td>
        <td><?= rtrim(rtrim(number_format((float)$l['qty_ordered'],2),'0'),'.') ?></td>
        <td><?= rtrim(rtrim(number_format((float)$l['remaining'],2),'0'),'.') ?></td>
        <td><?php foreach ($l['po_refs'] as $pr): ?><span class="badge brand"><?= e($pr['po_number']) ?></span> <?php endforeach; ?><?= $l['po_refs'] ? '' : '<span class="muted">—</span>' ?></td>
        <td><?= $sbadge($l['status']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php
$isDraft   = $req['status'] === 'draft';
$canAttach = $isDraft && Perm::can('mr_edit');
$fsize = function ($b) {
    $b = (int)$b;
    if ($b <= 0) return '';
    if ($b < 1024) return $b . ' B';
    if ($b < 1024 * 1024) return round($b / 1024) . ' KB';
    return number_format($b / 1024 / 1024, 1) . ' MB';
}; ?>
<div class="card">
  <h3>Attachments <span class="badge muted"><?= count($attachments) ?></span>
    <span class="muted small" style="font-weight:400">— quotations supporting this requisition</span></h3>
  <?php if ($attachments): ?>
    <ul class="attach-list">
      <?php foreach ($attachments as $a):
        $aUrl = $base . '/requisitions/' . (int)$req['id'] . '/attachments/' . (int)$a['id'] . '/file';
        $aExt = strtoupper(pathinfo($a['original_filename'], PATHINFO_EXTENSION) ?: 'FILE');
        $isImg = in_array(strtolower($aExt), ['jpg', 'jpeg', 'png', 'webp'], true); ?>
        <li class="attach-row">
          <?php if ($isImg): ?>
            <img class="ft-thumb" src="<?= e($aUrl) ?>" alt="">
          <?php else: ?>
            <span class="ft-pill"><?= e($aExt) ?></span>
          <?php endif; ?>
          <span class="clip"><?= Icons::svg('paperclip', 'clip-ico') ?></span>
          <a class="fn" href="<?= e($aUrl) ?>" target="_blank"><?= e($a['original_filename']) ?></a>
          <span class="sz muted small"><?= e($fsize($a['size_bytes'])) ?><?= $a['uploaded_by_name'] ? ' · ' . e($a['uploaded_by_name']) : '' ?></span>
          <?php if ($canAttach || Auth::isAdmin()): ?>
            <form method="post" action="<?= e($base) ?>/requisitions/<?= (int)$req['id'] ?>/attachments/<?= (int)$a['id'] ?>/delete" onsubmit="return confirm('Remove <?= e($a['original_filename']) ?>?')" style="display:inline">
              <?= Csrf::field() ?><button class="x" title="Remove">×</button>
            </form>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php else: ?>
    <p class="muted small" style="margin:0">No quotations attached to this requisition.</p>
  <?php endif; ?>
  <?php if ($canAttach): ?>
    <form method="post" action="<?= e($base) ?>/requisitions/<?= (int)$req['id'] ?>/attachments" enctype="multipart/form-data" class="row" style="align-items:flex-end;margin-top:.8rem">
      <?= Csrf::field() ?>
      <div style="flex:2"><label>Attach quotation</label>
        <input type="file" name="quotations[]" multiple required accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/*"></div>
      <div><button class="btn secondary">Attach</button></div>
    </form>
  <?php endif; ?>
</div>

<?php
// A single draft PO is raised automatically when the PM approves this MR, then
// pushed to Xero as a DRAFT with the quotations attached. Show where it landed.
$pos = \App\Db::all(
    "SELECT id, po_number, status, xero_po_id, xero_last_error
     FROM purchase_orders WHERE requisition_id = ? ORDER BY created_at DESC",
    [(int)$req['id']]
);
if ($pos): ?>
<div class="card">
  <h3>Purchase order <span class="muted small">— raised on approval, drafted in Xero for procurement to finalise</span></h3>
  <ul class="attach-list" style="margin:0">
    <?php foreach ($pos as $po): ?>
      <li class="attach-row">
        <a class="fn" href="<?= e($base) ?>/purchase-orders/<?= (int)$po['id'] ?>"><?= e($po['po_number']) ?></a>
        <?php if (!empty($po['xero_po_id'])): ?>
          <span class="badge ok">✓ in Xero (draft)</span>
        <?php elseif (!empty($po['xero_last_error'])): ?>
          <span class="badge danger" title="<?= e($po['xero_last_error']) ?>">Xero push failed</span>
        <?php else: ?>
          <span class="badge muted">not synced</span>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<p><a href="<?= e($base) ?>/requisitions">← All requisitions</a></p>
