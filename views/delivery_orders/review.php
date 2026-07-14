<?php /** @var array $do @var array $lines @var array $poLines @var array $openPos */
use App\Csrf;
$base = rtrim(parse_url(cfg('app.base_url', ''), PHP_URL_PATH) ?? '', '/');
$confirmed = false;
foreach ($lines as $l) { if ((int)$l['is_confirmed'] === 1) { $confirmed = true; break; } }
$hasPo = !empty($do['purchase_order_id']);
$fmt = fn($n) => $n === null ? '' : rtrim(rtrim(number_format((float)$n, 2), '0'), '.');
$vbadge = function (?string $v): string {
    if (!$v) return '<span class="badge muted">—</span>';
    $c = ['fully_received'=>'ok','partially_received'=>'warn','over'=>'warn','price_variance'=>'warn','unmatched'=>'danger','no_po'=>'danger','no_signature'=>'danger'][$v] ?? 'muted';
    return '<span class="badge ' . $c . '">' . e(str_replace('_',' ',$v)) . '</span>';
};
$dostatus = ['received'=>'muted','needs_review'=>'warn','matched'=>'ok','exception'=>'danger'][$do['status']] ?? 'muted'; ?>

<div class="toolbar">
  <div>
    <h1 style="margin:0">Delivery Order <?= e($do['do_number'] ?: ('#' . $do['id'])) ?></h1>
    <span class="muted small">
      <?= e($do['supplier_name'] ?: 'supplier unresolved') ?>
      <?php if ($do['po_number']): ?> · matched to <span class="badge brand"><?= e($do['po_number']) ?></span><?php else: ?> · <span class="badge danger">no PO resolved</span><?php endif; ?>
      <?php if ($do['project_code']): ?> · <span class="badge brand"><?= e($do['project_code']) ?></span><?php endif; ?>
      <?= $do['signature_present'] !== null ? ((int)$do['signature_present'] === 1 ? ' · ✅ signed' : ' · ⚠️ no signature') : '' ?>
    </span>
  </div>
  <div style="text-align:right">
    <span class="badge <?= $dostatus ?>" style="font-size:.8rem"><?= e(str_replace('_',' ',$do['status'])) ?></span>
    <?php if (!empty($do['ocr_model'])): ?>
      <div class="muted small" style="margin-top:.3rem">🤖 AI-read · <?= e($do['ocr_model']) ?> · <?= e(rtrim(rtrim(number_format((float)$do['ocr_confidence'],1),'0'),'.')) ?>% confidence</div>
    <?php endif; ?>
  </div>
</div>

<?php if ($do['match_summary']): ?><div class="card" style="background:#f0fdf4;border-color:#bbf7d0"><strong><?= e($do['match_summary']) ?></strong></div><?php endif; ?>

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
        <table>
          <thead><tr><th>DO line</th><th>Qty</th><th>Match to PO line</th><th>Score</th><th>Verdict</th></tr></thead>
          <tbody>
          <?php foreach ($lines as $l): ?>
            <tr>
              <td><?= e($l['ocr_description']) ?><?= $l['ocr_supplier_code'] ? ' <span class="badge code">'.e($l['ocr_supplier_code']).'</span>' : '' ?></td>
              <td style="width:90px"><input style="min-width:70px" name="line[<?= (int)$l['id'] ?>][qty]" type="number" step="any" min="0" value="<?= e($fmt($l['qty_accepted'] ?? $l['ocr_qty'])) ?>"></td>
              <td>
                <select name="line[<?= (int)$l['id'] ?>][po_line_id]">
                  <option value="">— unmatched —</option>
                  <?php foreach ($poLines as $pl): ?>
                    <option value="<?= (int)$pl['id'] ?>" <?= (int)$l['matched_po_line_id'] === (int)$pl['id'] ? 'selected' : '' ?>>
                      L<?= (int)$pl['line_no'] ?> · <?= e($pl['description']) ?> (bal <?= $fmt($pl['balance_qty']) ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td><?= $l['match_score'] !== null ? '<span class="badge '.($l['match_score']>=82?'ok':'warn').'">'.e($fmt($l['match_score'])).'%</span> <span class="muted small">'.e($l['match_method']).'</span>' : '<span class="muted">—</span>' ?></td>
              <td><?= $vbadge($l['verdict']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <div style="margin-top:1rem"><button class="btn" <?= $hasPo ? '' : 'disabled' ?>>Confirm &amp; post receipts</button></div>
        <p class="muted small">Confirming updates cumulative received quantities on the PO, keeps backorders open, and teaches the supplier-alias table. Xero billing comes in a later phase.</p>
        <?php endif; ?>
      </div>
    </form>
    <?php else: ?>
      <div class="card">
        <h3>Matched result</h3>
        <table>
          <thead><tr><th>DO line</th><th>Qty accepted</th><th>Matched item</th><th>Method</th><th>Verdict</th></tr></thead>
          <tbody>
          <?php foreach ($lines as $l): ?>
            <tr>
              <td><?= e($l['ocr_description']) ?></td>
              <td><?= e($fmt($l['qty_accepted'])) ?></td>
              <td><?= $l['matched_catalogue_item_id'] ? 'item #'.(int)$l['matched_catalogue_item_id'] : '<span class="muted">—</span>' ?></td>
              <td class="small muted"><?= e($l['match_method'] ?: '—') ?><?= $l['match_score']!==null ? ' ('.e($fmt($l['match_score'])).'%)' : '' ?></td>
              <td><?= $vbadge($l['verdict']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php if ($do['purchase_order_id']): ?><p style="margin-top:.8rem"><a class="btn sm secondary" href="<?= e($base) ?>/purchase-orders/<?= (int)$do['purchase_order_id'] ?>">View PO receipts →</a></p><?php endif; ?>
      </div>
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
    <h3>Signed DO</h3>
    <?php
      $ext = strtolower(pathinfo($do['image_path'], PATHINFO_EXTENSION));
      $fileExists = $do['image_path'] && is_file(\App\Storage::absPath($do['image_path']));
    ?>
    <?php if (!$fileExists): ?>
      <p class="muted small" style="margin:0">The uploaded file is no longer available. Please re-capture this delivery order.</p>
    <?php elseif ($ext === 'pdf'): ?>
      <a class="btn secondary" href="<?= e($base) ?>/delivery-orders/<?= (int)$do['id'] ?>/image" target="_blank">Open PDF</a>
    <?php else: ?>
      <a href="<?= e($base) ?>/delivery-orders/<?= (int)$do['id'] ?>/image" target="_blank">
        <img src="<?= e($base) ?>/delivery-orders/<?= (int)$do['id'] ?>/image" alt="Signed DO" style="width:100%;border:1px solid var(--fe-border);border-radius:8px">
      </a>
    <?php endif; ?>
    <?php if ($do['handwritten_notes']): ?><p class="small muted" style="margin-top:.6rem"><strong>Notes:</strong> <?= e($do['handwritten_notes']) ?></p><?php endif; ?>
  </div>
</div>

<p><a href="<?= e($base) ?>/delivery-orders">← All delivery orders</a></p>
