<?php /** @var array $suppliers @var array $openPos @var ?string $error */
use App\Csrf;
$base = rtrim(parse_url(cfg('app.base_url', ''), PHP_URL_PATH) ?? '', '/');
$ocrReady = cfg('gemini.api_key') && cfg('gemini.api_key') !== 'x'; ?>
<h1 style="margin-bottom:.2rem">Capture delivery order</h1>
<p class="muted small" style="margin-top:0">Attach the signed DO photo. With AI reading on, Gemini extracts the supplier, DO/PO number, project code and every line for you to confirm.</p>
<?php if ($error): ?><div class="alert"><?= e($error) ?></div><?php endif; ?>

<form method="post" action="<?= e($base) ?>/delivery-orders/save" enctype="multipart/form-data" id="doForm">
  <?= Csrf::field() ?>
  <div class="alert" id="doErr" hidden></div>
  <div class="card">
    <label style="display:block;margin-bottom:.35rem">Signed DO — photo or PDF *</label>
    <label class="dropzone" id="doDrop" for="doFile">
      <span class="dz-clip"><?= \App\Icons::svg('paperclip', 'clip-ico') ?></span>
      <span class="dz-main">Drop the signed DO here, or <span class="dz-link">browse</span></span>
      <span class="dz-sub">JPG, PNG, WEBP or PDF · one file · a phone photo is fine</span>
      <!-- No `required`: a hidden required input is unfocusable, so Chrome would
           block submit with an error it can't show. Checked in JS + server-side. -->
      <input type="file" id="doFile" name="image" accept=".jpg,.jpeg,.png,.webp,.pdf,image/*,application/pdf" hidden>
    </label>
    <ul class="attach-list" id="doPicked" hidden></ul>

    <div class="row" style="margin-top:.9rem">
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
// ---- Signed DO picker: drop or browse, with a preview of what's attached ----
const CLIP = <?= json_encode(\App\Icons::svg('paperclip', 'clip-ico')) ?>;
const doFile = document.getElementById('doFile');
const doDrop = document.getElementById('doDrop');
const doPicked = document.getElementById('doPicked');
let thumbUrl = null;

function renderPicked(){
  const f = doFile.files[0];
  doPicked.hidden = !f;
  doDrop.classList.toggle('has-file', !!f);
  // Picking a file resolves the "attach one first" error — drop it right away.
  if (f) { document.getElementById('doErr').hidden = true; doDrop.classList.remove('over'); }
  if (thumbUrl) { URL.revokeObjectURL(thumbUrl); thumbUrl = null; }
  if (!f) { doPicked.innerHTML = ''; return; }
  const isImg = f.type.startsWith('image/');
  let thumb = `<span class="ft-pill">${esc((f.name.split('.').pop()||'file').toUpperCase())}</span>`;
  if (isImg) { thumbUrl = URL.createObjectURL(f); thumb = `<img class="ft-thumb" src="${thumbUrl}" alt="">`; }
  doPicked.innerHTML = `
    <li class="attach-row">
      ${thumb}
      <span class="clip">${CLIP}</span>
      <span class="fn">${esc(f.name)}</span>
      <span class="sz muted small">${size(f.size)}</span>
      <button type="button" class="x" title="Remove" onclick="clearPicked()">×</button>
    </li>`;
}
function clearPicked(){ doFile.value = ''; renderPicked(); }
doFile.addEventListener('change', renderPicked);
['dragenter','dragover'].forEach(ev => doDrop.addEventListener(ev, e => { e.preventDefault(); doDrop.classList.add('over'); }));
['dragleave','drop'].forEach(ev => doDrop.addEventListener(ev, e => { e.preventDefault(); doDrop.classList.remove('over'); }));
doDrop.addEventListener('drop', e => {
  if (!e.dataTransfer.files.length) return;
  const dt = new DataTransfer();
  dt.items.add(e.dataTransfer.files[0]); // single DO per capture
  doFile.files = dt.files;
  renderPicked();
});
function size(b){
  if (b < 1024) return b + ' B';
  if (b < 1024*1024) return Math.round(b/1024) + ' KB';
  return (b/1024/1024).toFixed(1) + ' MB';
}
function esc(s){ return (s==null?'':String(s)).replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }
document.getElementById('doForm').addEventListener('submit', e => {
  const err = document.getElementById('doErr');
  if (!doFile.files.length) {
    e.preventDefault();
    err.textContent = 'Attach the signed DO first — a photo or PDF of it.';
    err.hidden = false;
    doDrop.classList.add('over');
    doDrop.scrollIntoView({behavior:'smooth', block:'center'});
    return;
  }
  err.hidden = true;
});
renderPicked();

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
