<?php /** @var array $do @var array $lines @var array $poLines @var array $openPos @var bool $hasOver @var ?string $error */
use App\Csrf;
$base = rtrim(parse_url(cfg('app.base_url', ''), PHP_URL_PATH) ?? '', '/');
$confirmed = false;
foreach ($lines as $l) { if ((int)$l['is_confirmed'] === 1) { $confirmed = true; break; } }
$hasPo = !empty($do['purchase_order_id']);
$fmt = fn($n) => $n === null ? '' : rtrim(rtrim(number_format((float)$n, 2), '0'), '.');
$vbadge = function (?string $v): string {
    if (!$v) return '<span class="badge muted">—</span>';
    $c = ['fully_received'=>'ok','partially_received'=>'warn','over'=>'warn','rejected'=>'danger','unmatched'=>'danger'][$v] ?? 'muted';
    return '<span class="badge ' . $c . '">' . e(str_replace('_',' ',$v)) . '</span>';
};
$dostatus = ['received'=>'muted','needs_review'=>'warn','matched'=>'ok','exception'=>'danger'][$do['status']] ?? 'muted'; ?>

<?php if (!empty($error)): ?><div class="alert"><?= e($error) ?></div><?php endif; ?>

<div class="toolbar">
  <div>
    <h1 style="margin:0">Delivery Order <?= e($do['do_number'] ?: ('#' . $do['id'])) ?></h1>
    <span class="muted small">
      <?= e($do['supplier_name'] ?: 'supplier unresolved') ?>
      <?php if ($hasPo): ?> · matched to <a href="<?= e($base) ?>/purchase-orders/<?= (int)$do['purchase_order_id'] ?>"><span class="badge brand"><?= e($do['po_number']) ?></span></a><?php else: ?> · <span class="badge danger">no PO resolved</span><?php endif; ?>
      <?php if ($do['project_code']): ?> · <span class="badge brand"><?= e($do['project_code']) ?></span><?php endif; ?>
      <?= $do['signature_present'] !== null ? ((int)$do['signature_present'] === 1 ? ' · ✅ signed' : ' · ⚠️ no signature') : '' ?>
    </span>
  </div>
  <div style="text-align:right">
    <span class="badge <?= $dostatus ?>" style="font-size:.8rem"><?= e(str_replace('_',' ',$do['status'])) ?></span>
    <?php if ($hasPo): ?>
      <div style="margin-top:.4rem"><a class="btn sm secondary" href="<?= e($base) ?>/purchase-orders/<?= (int)$do['purchase_order_id'] ?>">View PO <?= e($do['po_number']) ?> →</a></div>
    <?php endif; ?>
    <?php if (!empty($do['ocr_model'])): ?>
      <div class="muted small" style="margin-top:.3rem">🤖 AI-read · <?= e($do['ocr_model']) ?> · <?= e(rtrim(rtrim(number_format((float)$do['ocr_confidence'],1),'0'),'.')) ?>% confidence</div>
    <?php endif; ?>
  </div>
</div>

<?php if ($do['match_summary']): ?>
  <?php // Green would read as "all good" on a delivery that's short or over. ?>
  <?php $sumTone = $do['status'] === 'exception'
      ? 'background:#fffbeb;border-color:#fde68a'
      : (str_contains((string)$do['match_summary'], 'backorder') ? 'background:#f8fafc;border-color:var(--fe-border)' : 'background:#f0fdf4;border-color:#bbf7d0'); ?>
  <div class="card" style="<?= $sumTone ?>"><strong><?= e($do['match_summary']) ?></strong></div>
<?php endif; ?>

<?php if ($hasPo && $poFulfil): ?>
<?php
$fmtq = fn($n) => rtrim(rtrim(number_format((float)$n, 2), '0'), '.');
$pct  = $poFulfil['ordered'] > 0 ? (int)min(100, round($poFulfil['received'] / $poFulfil['ordered'] * 100)) : 0;
$plsb = fn($s) => '<span class="badge ' . (['open'=>'muted','partially_received'=>'warn','fully_received'=>'ok','over_received'=>'warn','closed'=>'muted'][$s] ?? 'muted') . '">' . e(str_replace('_',' ',$s)) . '</span>';
?>
<div class="card">
  <div class="dash-sec-h" style="margin-bottom:.7rem">
    <h3 style="margin:0">📦 PO <?= e($do['po_number']) ?> — what's still outstanding</h3>
    <a class="small" href="<?= e($base) ?>/purchase-orders/<?= (int)$do['purchase_order_id'] ?>">Open PO →</a>
  </div>
  <div class="po-fulfil">
    <div><span class="fu-num"><?= $fmtq($poFulfil['ordered']) ?></span><span class="fu-lbl">Ordered</span></div>
    <div><span class="fu-num ok"><?= $fmtq($poFulfil['received']) ?></span><span class="fu-lbl">Received</span></div>
    <div><span class="fu-num <?= $poFulfil['outstanding'] > 1e-6 ? 'warn' : '' ?>"><?= $fmtq($poFulfil['outstanding']) ?></span><span class="fu-lbl">Still outstanding</span></div>
    <div><span class="fu-num"><?= (int)$poFulfil['open_lines'] ?>/<?= (int)$poFulfil['lines'] ?></span><span class="fu-lbl">Lines not delivered</span></div>
  </div>
  <div class="po-bar"><span style="width:<?= $pct ?>%"></span></div>
  <?php if (!empty($poAllLines)): ?>
  <table style="margin-top:1rem">
    <thead><tr><th>#</th><th>PO line</th><th>Ordered</th><th>Received</th><th>Outstanding</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($poAllLines as $pl): $bal = (float)$pl['balance_qty']; ?>
      <tr>
        <td><?= (int)$pl['line_no'] ?></td>
        <td><?= e($pl['description']) ?></td>
        <td><?= $fmtq($pl['qty_ordered']) ?> <?= e($pl['uom']) ?></td>
        <td><?= $fmtq($pl['qty_received']) ?></td>
        <td><?= $bal > 1e-6 ? '<strong>' . $fmtq($bal) . '</strong>' : '0' ?></td>
        <td><?= $plsb($pl['line_status']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
  <?php // "fully received" would be a lie on an over-received PO — say so plainly.
        $overQty = max(0, $poFulfil['received'] - $poFulfil['ordered']); ?>
  <p class="muted small" style="margin:.45rem 0 0"><?= $pct ?>% of this PO delivered across all DOs<?php
    if ($poFulfil['outstanding'] > 1e-6) { echo ' · ' . $fmtq($poFulfil['outstanding']) . ' still to come'; }
    elseif ($overQty > 1e-6)             { echo ' · ' . $fmtq($overQty) . ' more than ordered ⚠️'; }
    else                                  { echo ' · fully received ✅'; }
  ?></p>
</div>
<?php endif; ?>

<div class="rq-grid">
  <!-- image + confirm -->
  <div>
    <?php if (!$confirmed): ?>
    <form method="post" action="<?= e($base) ?>/delivery-orders/<?= (int)$do['id'] ?>/confirm">
      <?= Csrf::field() ?>
      <div class="card">
        <h3>Confirm line matches</h3>
        <?php if (!$hasPo): ?>
          <p class="muted">No PO was resolved from the DO's reference (<?= e($do['po_reference_raw'] ?: '—') ?>) / project code (<?= e($do['project_code_raw'] ?: '—') ?>). Link a PO below, then the lines will match.</p>
          <table>
            <thead><tr><th>DO line (as read)</th><th>Supplier code</th><th>Qty</th><th>UOM</th></tr></thead>
            <tbody>
            <?php foreach ($lines as $l): ?>
              <tr><td><?= e($l['ocr_description']) ?></td><td><?= e($l['ocr_supplier_code'] ?: '—') ?></td><td><?= e($fmt($l['ocr_qty'])) ?></td><td><?= e($l['ocr_uom'] ?: '—') ?></td></tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php else: ?>
        <p class="muted small" style="margin-top:0">
          For each line say what <strong>arrived</strong> and how much you <strong>accepted</strong>.
          Anything you don't accept is recorded as refused with a reason, stays owed by the supplier,
          and never counts as received.
        </p>
        <div class="recv-lines">
          <?php foreach ($lines as $l):
            $bal = null;
            foreach ($poLines as $pl) { if ((int)$pl['id'] === (int)$l['matched_po_line_id']) { $bal = (float)$pl['balance_qty']; break; } }
            $delivered = $fmt($l['qty_delivered'] ?? $l['ocr_qty']); ?>
            <div class="recv-line" data-id="<?= (int)$l['id'] ?>">
              <div class="rl-head">
                <div class="rl-desc"><?= e($l['ocr_description']) ?>
                  <?= $l['ocr_supplier_code'] ? ' <span class="badge code">'.e($l['ocr_supplier_code']).'</span>' : '' ?>
                  <?= $l['match_score'] !== null
                      ? ' <span class="badge '.($l['match_score']>=82?'ok':'warn').'" title="'.e($l['match_method']).' match">'.e($fmt($l['match_score'])).'%</span>'
                      : '' ?>
                </div>
                <select class="rl-po" name="line[<?= (int)$l['id'] ?>][po_line_id]" data-bal="">
                  <option value="">— not on this PO —</option>
                  <?php foreach ($poLines as $pl): ?>
                    <option value="<?= (int)$pl['id'] ?>" data-bal="<?= (float)$pl['balance_qty'] ?>"
                            <?= (int)$l['matched_po_line_id'] === (int)$pl['id'] ? 'selected' : '' ?>>
                      L<?= (int)$pl['line_no'] ?> · <?= e($pl['description']) ?> (<?= $fmt($pl['balance_qty']) ?> <?= e($pl['uom'] ?: '') ?> outstanding)
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="rl-qty">
                <label>Delivered
                  <input class="rl-delivered" name="line[<?= (int)$l['id'] ?>][qty_delivered]" type="number" step="any" min="0"
                         value="<?= e($delivered) ?>" oninput="recalc(this)">
                </label>
                <label>Accepted
                  <input class="rl-accepted" name="line[<?= (int)$l['id'] ?>][qty]" type="number" step="any" min="0"
                         value="<?= e($fmt($l['qty_accepted'] ?? $l['ocr_qty'])) ?>" oninput="recalc(this)">
                </label>
                <span class="rl-uom muted small"><?= e($l['ocr_uom'] ?: '') ?></span>
                <span class="rl-rejected" hidden>Rejected <strong class="rl-rej-n">0</strong></span>
              </div>
              <div class="rl-reject" hidden>
                <label>Why weren't they accepted? *
                  <select class="rl-reason" name="line[<?= (int)$l['id'] ?>][reject_reason]">
                    <option value="">— pick a reason —</option>
                    <?php foreach (\App\Service\MatchingService::REASONS as $k => $lbl): ?>
                      <option value="<?= e($k) ?>" <?= ($l['reject_reason'] ?? '') === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <label>Note <span class="muted small">(optional)</span>
                  <input name="line[<?= (int)$l['id'] ?>][reject_note]" value="<?= e($l['reject_note'] ?? '') ?>"
                         placeholder="e.g. 3 crushed in transit, driver took them back">
                </label>
              </div>
              <p class="rl-warn" hidden></p>
            </div>
          <?php endforeach; ?>
        </div>
        <div style="margin-top:1rem"><button class="btn" <?= $hasPo ? '' : 'disabled' ?>>Confirm &amp; post receipts</button></div>
        <p class="muted small">
          Only the accepted quantity is receipted against the PO. Short or refused
          goods stay outstanding so the supplier still owes them. Accepting more
          than was ordered is allowed but held for a PM to sign off.
        </p>
        <?php endif; ?>
      </div>
    </form>
    <script>
    // Rejected = delivered − accepted. The reason box only appears when there is
    // something to explain, and over-delivery is flagged before you submit.
    function recalc(el){
      const row = el.closest('.recv-line');
      const del = parseFloat(row.querySelector('.rl-delivered').value) || 0;
      const acc = parseFloat(row.querySelector('.rl-accepted').value) || 0;
      const rej = Math.round((del - acc) * 1e6) / 1e6;
      const sel = row.querySelector('.rl-po');
      const bal = parseFloat(sel.selectedOptions[0]?.dataset.bal ?? '');

      row.querySelector('.rl-rejected').hidden = !(rej > 0);
      row.querySelector('.rl-rej-n').textContent = rej > 0 ? rej : 0;
      row.querySelector('.rl-reject').hidden = !(rej > 0);
      row.querySelector('.rl-reason').required = rej > 0;

      const warn = row.querySelector('.rl-warn');
      let msg = '';
      if (acc > del + 1e-6) msg = `You can't accept ${acc} when only ${del} arrived.`;
      else if (!isNaN(bal) && acc > bal + 1e-6) msg = `${acc} is more than the ${bal} still outstanding — a PM will need to sign off the overage.`;
      warn.textContent = msg;
      warn.hidden = !msg;
      warn.className = 'rl-warn' + (acc > del + 1e-6 ? ' is-error' : ' is-warn');
    }
    document.querySelectorAll('.rl-po').forEach(s => s.addEventListener('change', () => recalc(s)));
    document.querySelectorAll('.recv-line').forEach(r => recalc(r.querySelector('.rl-accepted')));
    </script>
    <?php else: ?>
      <div class="card">
        <h3>Received</h3>
        <table>
          <thead><tr><th>DO line</th><th>Delivered</th><th>Accepted</th><th>Rejected</th><th>Verdict</th></tr></thead>
          <tbody>
          <?php foreach ($lines as $l): ?>
            <tr>
              <td><?= e($l['ocr_description']) ?>
                <?php if ((float)($l['qty_rejected'] ?? 0) > 0 && $l['reject_reason']): ?>
                  <div class="muted small"><?= e(\App\Service\MatchingService::REASONS[$l['reject_reason']] ?? $l['reject_reason']) ?><?= $l['reject_note'] ? ' — “' . e($l['reject_note']) . '”' : '' ?></div>
                <?php endif; ?>
              </td>
              <td><?= e($fmt($l['qty_delivered'] ?? $l['ocr_qty'])) ?></td>
              <td><strong><?= e($fmt($l['qty_accepted'])) ?></strong></td>
              <td><?= (float)($l['qty_rejected'] ?? 0) > 0 ? '<span class="badge danger">' . e($fmt($l['qty_rejected'])) . '</span>' : '<span class="muted">—</span>' ?></td>
              <td><?= $vbadge($l['verdict']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php if ($do['purchase_order_id']): ?><p style="margin-top:.8rem"><a class="btn sm secondary" href="<?= e($base) ?>/purchase-orders/<?= (int)$do['purchase_order_id'] ?>">View PO receipts →</a></p><?php endif; ?>
      </div>

      <?php if (!empty($hasOver)): ?>
        <div class="card over-card">
          <h3 style="margin-top:0">⚠️ More was delivered than ordered</h3>
          <?php if (!empty($do['over_approved_at'])): ?>
            <p class="muted small" style="margin:0">
              ✓ Signed off <?= e($do['over_approved_at']) ?><?= $do['over_approval_note'] ? ' — “' . e($do['over_approval_note']) . '”' : '' ?>.
            </p>
          <?php else: ?>
            <p class="small" style="margin-top:0">
              The goods are recorded, but this delivery stays an exception until a PM accepts taking
              more than the PO ordered. To refuse the surplus instead, send it back and re-capture
              the DO with the correct quantity, or amend the PO.
            </p>
            <?php if (\App\Perm::can('do_approve_over')): ?>
              <form method="post" action="<?= e($base) ?>/delivery-orders/<?= (int)$do['id'] ?>/approve-over" class="row" style="align-items:flex-end">
                <?= Csrf::field() ?>
                <div style="flex:2"><label>Note <span class="muted small">(optional)</span></label>
                  <input name="note" placeholder="e.g. agreed with supplier, surplus kept for V50"></div>
                <div><button class="btn">Approve the overage</button></div>
              </form>
            <?php else: ?>
              <p class="muted small" style="margin:0">⏳ Waiting for a PM or superadmin to sign this off.</p>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>

    <?php if (!$confirmed): ?>
    <div class="card">
      <form method="post" action="<?= e($base) ?>/delivery-orders/<?= (int)$do['id'] ?>/relink" class="row" style="align-items:flex-end">
        <?= Csrf::field() ?>
        <div style="flex:2"><label>Change / set the matched PO</label>
          <select name="purchase_order_id">
            <option value="">— none (re-resolve from reference) —</option>
            <?php foreach ($openPos as $po): ?><option value="<?= (int)$po['id'] ?>" <?= (int)$do['purchase_order_id'] === (int)$po['id'] ? 'selected' : '' ?>><?= e($po['po_number']) ?> · <?= e($po['supplier_name']) ?> · <?= e($po['project_code']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div><button class="btn secondary">Re-match</button></div>
      </form>
    </div>
    <?php endif; ?>
  </div>

  <!-- image -->
  <div class="card">
    <?php
      $ext = strtolower(pathinfo($do['image_path'], PATHINFO_EXTENSION));
      $abs = \App\Storage::absPath($do['image_path']);
      $fileExists = $do['image_path'] && is_file($abs);
      $fileUrl = $base . '/delivery-orders/' . (int)$do['id'] . '/image';
      // Uploads before the filename column, and DOs that arrived over WhatsApp,
      // have no original name — label them by where they came from.
      $fileName = trim((string)($do['original_filename'] ?? '')) ?: (
        $do['source_channel'] === 'wazzup'
          ? 'Signed DO from WhatsApp.' . ($ext ?: 'jpg')
          : 'Signed DO.' . ($ext ?: 'jpg')
      );
      $bytes = $fileExists ? (int)filesize($abs) : 0;
      $human = match (true) {
          $bytes <= 0             => '',
          $bytes < 1024           => $bytes . ' B',
          $bytes < 1024 * 1024    => round($bytes / 1024) . ' KB',
          default                 => number_format($bytes / 1024 / 1024, 1) . ' MB',
      };
    ?>
    <h3 style="margin-bottom:.5rem">Attachments <span class="badge muted"><?= $fileExists ? 1 : 0 ?></span></h3>
    <ul class="attach-list">
      <li class="attach-row">
        <?php if ($fileExists && $ext !== 'pdf'): ?>
          <img class="ft-thumb" src="<?= e($fileUrl) ?>" alt="">
        <?php else: ?>
          <span class="ft-pill"><?= e(strtoupper($ext ?: 'FILE')) ?></span>
        <?php endif; ?>
        <span class="clip"><?= \App\Icons::svg('paperclip', 'clip-ico') ?></span>
        <?php if ($fileExists): ?>
          <a class="fn" href="<?= e($fileUrl) ?>" target="_blank"><?= e($fileName) ?></a>
          <span class="sz muted small"><?= e($human) ?></span>
        <?php else: ?>
          <span class="fn muted"><?= e($fileName) ?></span>
          <span class="sz muted small">missing</span>
        <?php endif; ?>
      </li>
    </ul>
    <?php if (trim((string)($do['original_filename'] ?? '')) === '' && $fileExists): ?>
      <p class="muted small" style="margin:.4rem 0 0">
        Captured before file names were recorded — the original name wasn’t saved.
        Set it under <em>Edit delivery order details</em> below.
      </p>
    <?php endif; ?>
    <?php if (!$fileExists): ?>
      <p class="muted small" style="margin:.4rem 0 0">The uploaded file is no longer available. Please re-capture this delivery order.</p>
    <?php elseif ($ext !== 'pdf'): ?>
      <a href="<?= e($fileUrl) ?>" target="_blank">
        <img src="<?= e($fileUrl) ?>" alt="<?= e($fileName) ?>" style="width:100%;border:1px solid var(--fe-border);border-radius:8px;margin-top:.6rem">
      </a>
    <?php else: ?>
      <a class="btn secondary" style="margin-top:.6rem" href="<?= e($fileUrl) ?>" target="_blank">Open PDF</a>
    <?php endif; ?>
    <?php if ($do['handwritten_notes']): ?><p class="small muted" style="margin-top:.6rem"><strong>Notes:</strong> <?= e($do['handwritten_notes']) ?></p><?php endif; ?>
  </div>
</div>

<div class="card">
  <details>
    <summary style="cursor:pointer;font-weight:600">Edit delivery order details<?= \App\Auth::isAdmin() ? ' / delete' : '' ?></summary>
    <form method="post" action="<?= e($base) ?>/delivery-orders/<?= (int)$do['id'] ?>/edit" style="margin-top:1rem">
      <?= Csrf::field() ?>
      <div class="row">
        <div><label>DO number</label><input name="do_number" value="<?= e($do['do_number']) ?>"></div>
        <div><label>Delivery date</label><input name="delivery_date" value="<?= e($do['delivery_date']) ?>" placeholder="YYYY-MM-DD"></div>
        <div><label>File name <span class="muted small">(shown under Attachments)</span></label>
          <input name="original_filename" value="<?= e($do['original_filename'] ?? '') ?>" placeholder="<?= e($fileName) ?>"></div>
      </div>
      <label>Notes</label>
      <input name="handwritten_notes" value="<?= e($do['handwritten_notes']) ?>">
      <div style="margin-top:.8rem"><button class="btn secondary">Save details</button></div>
    </form>
    <?php if (\App\Auth::isAdmin()): ?>
      <hr style="border:none;border-top:1px solid var(--fe-border);margin:1rem 0">
      <form method="post" action="<?= e($base) ?>/delivery-orders/<?= (int)$do['id'] ?>/delete" onsubmit="return confirm('Delete this delivery order? Any receipts it posted will be reversed on the PO. This cannot be undone.')">
        <?= Csrf::field() ?><button class="btn ghost-danger">Delete delivery order</button>
      </form>
    <?php endif; ?>
  </details>
</div>

<p><a href="<?= e($base) ?>/delivery-orders">← All delivery orders</a></p>
