<?php /** @var array $req @var array $lines @var array $suppliers @var ?string $error */
use App\Auth;
use App\Csrf;
$base = rtrim(parse_url(cfg('app.base_url', ''), PHP_URL_PATH) ?? '', '/');
$hasRemaining = false;
foreach ($lines as $l) { if ($l['remaining'] > 0.00001) { $hasRemaining = true; break; } }
$canOrder = in_array($req['status'], ['approved', 'partially_ordered'], true) && $hasRemaining;
$sbadge = fn($s) => '<span class="badge ' . (['open'=>'muted','partially_ordered'=>'warn','fully_ordered'=>'ok','cancelled'=>'muted'][$s] ?? 'muted') . '">' . e(str_replace('_',' ',$s)) . '</span>'; ?>

<?php if ($error): ?><div class="alert"><?= e($error) ?></div><?php endif; ?>

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
    <?php if ($req['status'] === 'draft' && Auth::is('requester', 'staff', 'purchaser')): ?>
      <a class="btn sm ghost" href="<?= e($base) ?>/requisitions/<?= (int)$req['id'] ?>/edit">Edit</a>
    <?php endif; ?>
    <?php if (Auth::isAdmin()): ?>
      <form method="post" action="<?= e($base) ?>/requisitions/<?= (int)$req['id'] ?>/delete" onsubmit="return confirm('Delete requisition <?= e($req['mr_number']) ?>? This cannot be undone.')" style="display:inline">
        <?= Csrf::field() ?><button class="btn sm ghost-danger">Delete</button>
      </form>
    <?php endif; ?>
    <?php if ($req['status'] === 'draft'): ?>
      <?php if (Auth::isAdmin()): ?>
        <form method="post" action="<?= e($base) ?>/requisitions/<?= (int)$req['id'] ?>/reject" onsubmit="return confirm('Reject this requisition?')" style="display:inline">
          <?= Csrf::field() ?><button class="btn sm ghost-danger">Reject</button>
        </form>
        <form method="post" action="<?= e($base) ?>/requisitions/<?= (int)$req['id'] ?>/approve" style="display:inline">
          <?= Csrf::field() ?><button class="btn sm">Approve ✓</button>
        </form>
      <?php else: ?>
        <span class="badge warn" style="font-size:.8rem">⏳ Awaiting superadmin approval</span>
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

<?php if ($canOrder): ?>
<div class="card">
  <h3>Raise a Purchase Order <span class="muted small">— pick a supplier and the lines to order from them</span></h3>
  <form method="post" action="<?= e($base) ?>/requisitions/<?= (int)$req['id'] ?>/create-po">
    <?= Csrf::field() ?>
    <div class="row">
      <div><label>Supplier *</label>
        <select name="supplier_id" required>
          <option value="">— select —</option>
          <?php foreach ($suppliers as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div><label>PO No. *</label><input name="po_number" required placeholder="130536"></div>
      <div><label>Order date</label><input name="order_date" type="date"></div>
    </div>
    <table style="margin-top:.6rem">
      <thead><tr><th>Include</th><th>Particulars</th><th>Remaining</th><th style="width:12%">Order qty</th><th style="width:14%">Unit price (MYR)</th></tr></thead>
      <tbody>
      <?php foreach ($lines as $l): if ($l['remaining'] <= 0.00001) continue; ?>
        <tr>
          <td><input type="checkbox" name="poline[<?= (int)$l['id'] ?>][include]" value="1" checked style="width:auto"></td>
          <td><?= e($l['raw_description']) ?></td>
          <td><?= rtrim(rtrim(number_format((float)$l['remaining'],2),'0'),'.') ?> <?= e($l['uom']) ?></td>
          <td><input name="poline[<?= (int)$l['id'] ?>][qty]" type="number" step="any" min="0" max="<?= (float)$l['remaining'] ?>" value="<?= rtrim(rtrim(number_format((float)$l['remaining'],2,'.',''),'0'),'.') ?>"></td>
          <td><input name="poline[<?= (int)$l['id'] ?>][unit_price]" type="number" step="any" min="0" placeholder="optional"></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div style="margin-top:1rem"><button class="btn">Create Purchase Order</button></div>
    <p class="muted small">Xero push is stubbed until its credentials are provisioned — the PO is recorded here and the intent is logged.</p>
  </form>
</div>
<?php endif; ?>

<p><a href="<?= e($base) ?>/requisitions">← All requisitions</a></p>
