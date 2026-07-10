<?php /** @var array $suppliers @var array $openPos @var ?string $error */
use App\Csrf;
$base = rtrim(parse_url(cfg('app.base_url', ''), PHP_URL_PATH) ?? '', '/');
$ocrReady = cfg('gemini.api_key') && cfg('gemini.api_key') !== 'x'; ?>
<h1 style="margin-bottom:.2rem">Capture delivery order</h1>
<p class="muted small" style="margin-top:0">Attach the signed DO photo. With AI reading on, Gemini extracts the supplier, DO/PO number, project code and every line for you to confirm.</p>
<?php if ($error): ?><div class="alert"><?= e($error) ?></div><?php endif; ?>

<form method="post" action="<?= e($base) ?>/delivery-orders/save" enctype="multipart/form-data">
  <?= Csrf::field() ?>
  <div class="card">
    <div class="row">
      <div><label>DO image (photo / PDF) *</label><input type="file" name="image" accept="image/*,application/pdf" required></div>
      <div><label>DO No. <span class="muted small">(optional — AI fills)</span></label><input name="do_number" placeholder="auto"></div>
      <div><label>Delivery date</label><input name="delivery_date" type="date"></div>
    </div>
    <div style="margin:.4rem 0 .2rem">
      <label style="display:flex;align-items:center;gap:.5rem;font-weight:600">
        <input type="checkbox" name="use_ocr" value="1" <?= $ocrReady ? 'checked' : 'disabled' ?> style="width:auto">
        Read the DO automatically with Gemini AI <?= $ocrReady ? '' : '<span class="muted small">(configure a Gemini key to enable)</span>' ?>
      </label>
    </div>
    <div class="row">
      <div><label>Supplier <span class="muted small">(optional — AI infers)</span></label>
        <select name="supplier_id"><option value="">— auto / unknown —</option>
          <?php foreach ($suppliers as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div><label>PO reference <span class="muted small">(optional)</span></label><input name="po_reference_raw" placeholder="auto"></div>
      <div><label>Project code <span class="muted small">(optional)</span></label><input name="project_code_raw" placeholder="auto"></div>
    </div>
    <div class="row">
      <div><label>&hellip; or link a PO directly</label>
        <select name="purchase_order_id"><option value="">— resolve automatically —</option>
          <?php foreach ($openPos as $po): ?><option value="<?= (int)$po['id'] ?>"><?= e($po['po_number']) ?> · <?= e($po['supplier_name']) ?> · <?= e($po['project_code']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div><label>Signed / accepted?</label>
        <select name="signature_present"><option value="">Auto (from image)</option><option value="1">Yes — signed</option><option value="0">No signature</option></select>
      </div>
      <div><label>Handwritten notes <span class="muted small">(optional)</span></label><input name="handwritten_notes" placeholder="auto"></div>
    </div>
  </div>

  <details class="card">
    <summary style="cursor:pointer;font-weight:600">Enter lines manually (optional — used if AI reading is off)</summary>
    <table id="lineTable" style="margin-top:.8rem">
      <thead><tr><th>Description (as on DO)</th><th style="width:16%">Supplier code</th><th style="width:12%">Qty</th><th style="width:10%">UOM</th><th></th></tr></thead>
      <tbody id="lineBody"></tbody>
    </table>
    <button type="button" class="btn sm secondary" onclick="addLine()">+ Add line</button>
  </details>

  <div style="margin-top:1.2rem;display:flex;gap:.6rem">
    <button class="btn">Capture &amp; match →</button>
    <a class="btn secondary" href="<?= e($base) ?>/delivery-orders">Cancel</a>
  </div>
</form>

<script>
let ix=0;
function addLine(){
  const i=ix++;
  const tr=document.createElement('tr');
  tr.innerHTML=`
    <td><input name="line[${i}][ocr_description]"></td>
    <td><input name="line[${i}][ocr_supplier_code]"></td>
    <td><input name="line[${i}][ocr_qty]" type="number" step="any" min="0"></td>
    <td><input name="line[${i}][ocr_uom]" placeholder="nos"></td>
    <td><button type="button" class="btn sm secondary" onclick="this.closest('tr').remove()">×</button></td>`;
  document.getElementById('lineBody').appendChild(tr);
}
addLine();
</script>
