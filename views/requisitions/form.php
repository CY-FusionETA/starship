<?php /** @var array $projects @var ?array $req @var ?array $lines @var ?array $attachments */
use App\Csrf;
$base = rtrim(parse_url(cfg('app.base_url', ''), PHP_URL_PATH) ?? '', '/');
$editing = !empty($req);
$action  = $editing ? "/requisitions/{$req['id']}/update" : "/requisitions/save";
$seed = [];
if ($editing) {
    $ci = 0;
    foreach (($lines ?? []) as $l) {
        $isCustom = empty($l['catalogue_item_id']);
        $seed[] = [
            'key'    => $isCustom ? ('x' . $ci++) : ('c' . (int)$l['catalogue_item_id']),
            'id'     => $isCustom ? null : (int)$l['catalogue_item_id'],
            'code'   => $isCustom ? 'custom' : ($l['item_code'] ?? ''),
            'name'   => $isCustom ? $l['raw_description'] : ($l['item_name'] ?: $l['raw_description']),
            'uom'    => $l['uom'] ?: 'nos',
            'price'  => (!$isCustom && $l['ref_price'] !== null) ? (float)$l['ref_price'] : null,
            'qty'    => (float)$l['qty'],
            'custom' => $isCustom,
        ];
    }
} ?>
<h1 style="margin-bottom:.2rem"><?= $editing ? 'Edit Material Requisition' : 'New Material Requisition' ?></h1>
<p class="muted small" style="margin-top:0">Form GE(S)-PU-F01/1 — search the catalogue and add parts to the requisition.</p>

<form id="mrForm" method="post" action="<?= e($base . $action) ?>" enctype="multipart/form-data">
  <?= Csrf::field() ?>

  <div class="steps" role="tablist">
    <button type="button" class="step active" id="tabItems" role="tab" aria-selected="true" aria-controls="paneItems" onclick="showStep('items')">
      <span class="step-n">1</span> Items <span class="badge muted" id="stepLineCount">0</span>
    </button>
    <button type="button" class="step" id="tabQuotes" role="tab" aria-selected="false" aria-controls="paneQuotes" onclick="showStep('quotes')">
      <span class="step-n">2</span> Quotations <span class="badge muted" id="stepQuoteCount">0</span>
    </button>
  </div>

  <div id="paneItems" role="tabpanel" aria-labelledby="tabItems">
  <div class="card">
    <div class="mr-fields">
      <div class="row">
        <div style="flex:0 1 150px;min-width:120px"><label>MR No. *</label><input name="mr_number" required placeholder="48" value="<?= $editing ? e($req['mr_number']) : '' ?>"></div>
        <div style="flex:3"><label>Project *</label>
          <select name="project_id" required>
            <option value="">— select project —</option>
            <?php foreach ($projects as $p): ?><option value="<?= (int)$p['id'] ?>" <?= ($editing && (int)$req['project_id'] === (int)$p['id']) ? 'selected' : '' ?>><?= e($p['project_code']) ?> — <?= e($p['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="row">
        <div style="flex:3"><label>Supplier <span class="muted small" style="font-weight:400">— optional; the draft PO is raised to them when the PM approves</span></label>
          <?php $selSup = $editing ? (int)($req['supplier_id'] ?? 0) : 0; ?>
          <select name="supplier_id">
            <option value="">— to be confirmed —</option>
            <?php foreach (($suppliers ?? []) as $s): ?><option value="<?= (int)$s['id'] ?>" <?= $selSup === (int)$s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="row">
        <div><label>Requested by</label><input name="requested_by" placeholder="ARASH" value="<?= $editing ? e($req['requested_by']) : '' ?>"></div>
        <div><label>Mobile Number</label><input name="requester_mobile" type="tel" placeholder="+60 12-345 6789" value="<?= $editing ? e($req['requester_mobile'] ?? '') : '' ?>"></div>
        <div><label>Email</label><input name="requester_email" type="email" placeholder="name@company.com" value="<?= $editing ? e($req['requester_email'] ?? '') : '' ?>"></div>
      </div>
      <div class="row">
        <div><label>Submission Date</label><input name="request_date" type="date" value="<?= $editing ? e($req['request_date']) : '' ?>"></div>
        <div><label>Urgency</label>
          <?php $urg = $editing ? ($req['urgency'] ?? '') : ''; ?>
          <select name="urgency" id="urgency" onchange="syncUrgency()">
            <option value="">— select urgency —</option>
            <?php foreach (['ASAP/URGENT','ASAP - Partial Delivery Accepted','Specify Date Below','TBA - To Be Advised'] as $opt): ?>
              <option value="<?= e($opt) ?>" <?= $urg === $opt ? 'selected' : '' ?>><?= e($opt) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div id="deliveryDateField" hidden><label>Specific Delivery Date</label><input name="delivery_date" id="deliveryDate" type="date" value="<?= $editing ? e($req['delivery_date']) : '' ?>"></div>
      </div>
    </div>
  </div>

  <div class="rq-grid">
    <!-- left: search -->
    <div>
      <div class="rq-search">
        <input class="big" id="q" placeholder="Try &ldquo;FSP&rdquo;, &ldquo;Victaulic&rdquo;, &ldquo;CO2&rdquo;, &ldquo;Notifier&rdquo;, &ldquo;nozzle&rdquo;&hellip;" autocomplete="off">
      </div>
      <div class="chips" id="chips">
        <?php foreach (['Notifier','Victaulic','CO2','nozzle','FSP','coupling'] as $c): ?>
          <button type="button" class="chip" onclick="chip('<?= e($c) ?>')"><?= e($c) ?></button>
        <?php endforeach; ?>
        <span class="chip count" id="count"></span>
      </div>
      <div class="item-list" id="items"></div>
      <div class="add-row">
        <button type="button" class="btn sm" onclick="openNewProduct()">＋ Add product</button>
      </div>
      <p class="muted small" style="margin:.5rem 0 0">
        Adds to the catalogue for everyone — or tick <strong>one-off</strong> in the dialog to add a free-text line to this requisition only.
      </p>
    </div>

    <!-- right: cart -->
    <div class="cart">
      <div class="cart-h"><h3>Requisition</h3><span class="badge muted" id="lineCount">0 lines</span></div>
      <div class="cart-body" id="cart"><div class="cart-empty">No lines yet — add parts from the catalogue.</div></div>
      <div class="cart-foot">
        <div class="cart-total"><span class="muted">Estimated value</span><span class="v" id="total">RM 0.00</span></div>
        <div id="lineInputs"></div>
        <button type="button" class="btn" id="raise" style="width:100%" disabled onclick="showStep('quotes')">Next: quotations →</button>
      </div>
    </div>
  </div>
  </div><!-- /paneItems -->

  <div id="paneQuotes" role="tabpanel" aria-labelledby="tabQuotes" hidden>
    <div class="card">
      <h3 style="margin-top:0">Quotations <span class="muted small" style="font-weight:400">— optional, but the PM approves faster with one attached</span></h3>
      <?php $already = $editing ? ($attachments ?? []) : []; if ($already): ?>
        <ul class="attach-list" style="margin-top:0">
          <?php foreach ($already as $a): ?>
            <li class="attach-row">
              <span class="clip"><?= \App\Icons::svg('paperclip', 'clip-ico') ?></span>
              <a class="fn" href="<?= e($base) ?>/requisitions/<?= (int)$req['id'] ?>/attachments/<?= (int)$a['id'] ?>/file" target="_blank"><?= e($a['original_filename']) ?></a>
              <span class="sz muted small">attached</span>
            </li>
          <?php endforeach; ?>
        </ul>
        <p class="muted small" style="margin:.2rem 0 .8rem">Already attached — remove them from the requisition page. Anything you add below joins them.</p>
      <?php endif; ?>
      <label class="dropzone" id="dropzone" for="quotes">
        <span class="dz-clip"><?= \App\Icons::svg('paperclip', 'clip-ico') ?></span>
        <span class="dz-main">Drop quotation files here, or <span class="dz-link">browse</span></span>
        <span class="dz-sub">PDF, JPG, PNG or WEBP · up to 15 MB each · attach as many as you have</span>
        <input type="file" id="quotes" name="quotations[]" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/*" hidden>
      </label>
      <ul class="attach-list" id="quoteList"></ul>
      <p class="muted small" id="quoteEmpty"><?= $already
          ? 'No new files selected.'
          : 'No quotations attached yet — you can still ' . ($editing ? 'save' : 'raise') . ' the requisition without one.' ?></p>
    </div>
    <div class="card quote-actions">
      <button type="button" class="btn secondary" onclick="showStep('items')">← Back to items</button>
      <button class="btn" id="submitMr" disabled><?= $editing ? 'Save changes →' : 'Raise requisition →' ?></button>
    </div>
  </div>
</form>

<!-- Add product modal (outside #mrForm so its fields never submit with the MR) -->
<div class="modal-overlay" id="npOverlay" hidden>
  <div class="modal np-modal" role="dialog" aria-modal="true" aria-labelledby="npTitle">
    <div class="modal-h">
      <h3 id="npTitle">Add product</h3>
      <button type="button" class="modal-x" onclick="closeNewProduct()" aria-label="Close">×</button>
    </div>
    <div class="modal-b">
      <div id="npErr" class="alert" hidden></div>
      <label>Product name *</label>
      <input id="np_name" placeholder="e.g. 6&quot; Victaulic Firelock Coupling" autocomplete="off">
      <div class="row">
        <div class="np-cat"><label>Item code</label><input id="np_code" placeholder="auto if blank"></div>
        <div><label>UOM</label><input id="np_uom" placeholder="nos / ft / m" value="nos"></div>
      </div>
      <div class="row">
        <div class="np-cat"><label>Brand</label><input id="np_brand" placeholder="Notifier / Victaulic …"></div>
        <div class="np-cat"><label>Model</label><input id="np_model"></div>
      </div>
      <div class="row">
        <div class="np-cat"><label>Category</label><input id="np_category" placeholder="Fire Alarm / Piping …"></div>
        <div><label>Ref. unit price (MYR)</label><input id="np_price" type="number" step="any" min="0" placeholder="optional"></div>
      </div>
      <label class="switch-row" style="margin:.5rem 0 .2rem">
        <input type="checkbox" id="np_oneoff" onchange="toggleOneOff()">
        <span>One-off item — this requisition only, don’t save to the catalogue</span>
      </label>
      <p class="muted small" id="npHint" style="margin:.1rem 0 0">Saved to the catalogue and added straight to this requisition.</p>
    </div>
    <div class="modal-f">
      <button type="button" class="btn secondary" onclick="closeNewProduct()">Cancel</button>
      <button type="button" class="btn" id="npSave" onclick="saveNewProduct()">Save &amp; add →</button>
    </div>
  </div>
</div>

<script>
const BASE = <?= json_encode($base) ?>;
const CSRF = <?= json_encode(Csrf::token()) ?>;
const CLIP = <?= json_encode(\App\Icons::svg('paperclip', 'clip-ico')) ?>;
const cart = new Map();      // id -> {id, code, name, uom, price, qty, custom}
let items = [], customSeq = 0;
const SEED = <?= json_encode($seed) ?>;
SEED.forEach(l=>{ cart.set(l.key,{id:l.id,code:l.code,name:l.name,uom:l.uom,price:l.price,qty:l.qty,custom:l.custom}); });
customSeq = SEED.filter(l=>l.custom).length;

async function load(q){
  const r = await fetch(`${BASE}/catalogue/search.json?q=${encodeURIComponent(q||'')}`, {headers:{'X-Requested-With':'fetch'}});
  const d = await r.json(); items = d.items || [];
  renderItems();
}
function renderItems(){
  document.getElementById('count').textContent = `${items.length} item${items.length==1?'':'s'}`;
  document.getElementById('items').innerHTML = items.map(it => {
    const added = cart.has('c'+it.id);
    const price = it.unit_price!=null
      ? `<span class="amt">RM ${fmt(it.unit_price)}</span><span class="per">/ ${esc(it.uom)}</span>`
      : `<span class="amt none">No ref price</span><span class="per">/ ${esc(it.uom)}</span>`;
    const sub = [it.brand, it.model, it.category].filter(Boolean).map(esc).join(' · ');
    return `<div class="item-card">
      <div class="item-main"><div class="nm">${esc(it.name)} <span class="badge code">${esc(it.item_code)}</span></div>
        <div class="sub">${sub||'&nbsp;'}</div></div>
      <div class="item-price">${price}</div>
      <div class="item-add">${added
        ? `<span class="pill-added">✓ Added</span>`
        : `<button type="button" class="btn sm" onclick='addItem(${JSON.stringify(it)})'>+ Add</button>`}</div>
    </div>`;
  }).join('') || `<p class="muted" style="padding:1rem">No items found.</p>`;
}
function addItem(it){
  const key='c'+it.id;
  if(!cart.has(key)) cart.set(key,{id:it.id,code:it.item_code,name:it.name,uom:it.uom,price:it.unit_price,qty:1,custom:false});
  renderItems(); renderCart();
}
// ---- Add product (catalogue product, or a one-off line via the checkbox) ----
const npOverlay = document.getElementById('npOverlay');
function openNewProduct(){
  document.getElementById('npErr').hidden = true;
  ['name','code','brand','model','category','price'].forEach(f=>document.getElementById('np_'+f).value='');
  document.getElementById('np_uom').value='nos';
  document.getElementById('np_oneoff').checked=false;
  toggleOneOff();
  npOverlay.hidden = false;
  setTimeout(()=>document.getElementById('np_name').focus(),30);
}
function closeNewProduct(){ npOverlay.hidden = true; }
// Ticking "one-off" dims + disables the catalogue-only fields (they aren't saved).
function toggleOneOff(){
  const on = document.getElementById('np_oneoff').checked;
  document.querySelectorAll('#npOverlay .np-cat').forEach(d=>{
    d.style.opacity = on ? .45 : '';
    const inp = d.querySelector('input'); if(inp){ inp.disabled = on; if(on) inp.value=''; }
  });
  document.getElementById('npHint').innerHTML = on
    ? 'A free-text line for <strong>this requisition only</strong> — not saved to the catalogue.'
    : 'Saved to the catalogue and added straight to this requisition.';
  document.getElementById('npSave').textContent = on ? 'Add one-off →' : 'Save & add →';
}
npOverlay.addEventListener('click',e=>{ if(e.target===npOverlay) closeNewProduct(); });
document.addEventListener('keydown',e=>{ if(e.key==='Escape' && !npOverlay.hidden) closeNewProduct(); });
async function saveNewProduct(){
  const name = document.getElementById('np_name').value.trim();
  const err = document.getElementById('npErr');
  if(!name){ err.textContent = (document.getElementById('np_oneoff').checked?'Description':'Product name')+' is required.'; err.hidden=false; document.getElementById('np_name').focus(); return; }
  const uom = document.getElementById('np_uom').value.trim() || 'nos';
  const priceRaw = document.getElementById('np_price').value.trim();
  const price = priceRaw === '' ? null : (parseFloat(priceRaw) || 0);

  // One-off: add a free-text line to this requisition only — no catalogue write.
  if(document.getElementById('np_oneoff').checked){
    cart.set('x'+(customSeq++),{id:null,code:'custom',name,uom,price,qty:1,custom:true});
    closeNewProduct(); renderCart(); return;
  }

  const btn = document.getElementById('npSave'); btn.disabled=true; btn.textContent='Saving…';
  const body = new URLSearchParams({
    _csrf: CSRF, name, uom,
    item_code: document.getElementById('np_code').value.trim(),
    brand: document.getElementById('np_brand').value.trim(),
    model: document.getElementById('np_model').value.trim(),
    category: document.getElementById('np_category').value.trim(),
    unit_price: priceRaw,
  });
  try{
    const r = await fetch(`${BASE}/catalogue/quick-add.json`,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'fetch'},body});
    const d = await r.json();
    if(!r.ok || d.error){ err.textContent = d.error || 'Could not save product.'; err.hidden=false; return; }
    // Drop it into the list + cart, and echo the search box so it stays visible.
    if(!items.some(it=>it.id===d.item.id)) items.unshift(d.item);
    renderItems();
    addItem(d.item);
    closeNewProduct();
  }catch(ex){ err.textContent='Network error — please try again.'; err.hidden=false; }
  finally{ btn.disabled=false; btn.textContent='Save & add →'; }
}
function setQty(key,q){ const l=cart.get(key); if(!l) return; l.qty=Math.max(1,parseFloat(q)||1); renderCart(); }
function bump(key,d){ const l=cart.get(key); if(!l) return; l.qty=Math.max(1,(parseFloat(l.qty)||1)+d); renderCart(); }
function remove(key){ cart.delete(key); renderItems(); renderCart(); }
function renderCart(){
  const el=document.getElementById('cart');
  document.getElementById('lineCount').textContent=`${cart.size} line${cart.size==1?'':'s'}`;
  if(cart.size===0){ el.innerHTML=`<div class="cart-empty">No lines yet — add parts from the catalogue.</div>`; }
  else {
    el.innerHTML=[...cart.entries()].map(([k,l])=>{
      const lt = l.price!=null ? `RM ${fmt(l.price*l.qty)}` : '<span class="muted">—</span>';
      return `<div class="cart-line"><button type="button" class="x" onclick="remove('${k}')">×</button>
        <div class="nm">${esc(l.name)} ${l.custom?'':`<span class="badge code">${esc(l.code)}</span>`}</div>
        <div class="rowline">
          <span class="stepper"><button type="button" onclick="bump('${k}',-1)">−</button>
            <input type="number" min="1" step="any" value="${l.qty}" onchange="setQty('${k}',this.value)">
            <button type="button" onclick="bump('${k}',1)">+</button></span>
          <span>${esc(l.uom)} · <strong>${lt}</strong></span>
        </div></div>`;
    }).join('');
  }
  let total=0; cart.forEach(l=>{ if(l.price!=null) total+=l.price*l.qty; });
  document.getElementById('total').textContent='RM '+fmt(total);
  document.getElementById('raise').disabled = cart.size===0;
  document.getElementById('submitMr').disabled = cart.size===0;
  document.getElementById('stepLineCount').textContent = cart.size;
}
document.getElementById('mrForm').addEventListener('submit',e=>{
  const box=document.getElementById('lineInputs'); box.innerHTML=''; let i=0;
  cart.forEach(l=>{
    const add=(n,v)=>{ const inp=document.createElement('input'); inp.type='hidden'; inp.name=`lines[${i}][${n}]`; inp.value=v==null?'':v; box.appendChild(inp); };
    if(!l.custom) add('catalogue_item_id',l.id);
    add('raw_description',l.name); add('qty',l.qty); add('uom',l.uom);
    i++;
  });
  if(i===0){ e.preventDefault(); showStep('items'); alert('Add at least one line.'); }
});

// ---- Step 1 Items / Step 2 Quotations -------------------------------------
function showStep(which){
  const onQuotes = which === 'quotes';
  document.getElementById('paneItems').hidden = onQuotes;
  document.getElementById('paneQuotes').hidden = !onQuotes;
  document.getElementById('tabItems').classList.toggle('active', !onQuotes);
  document.getElementById('tabQuotes').classList.toggle('active', onQuotes);
  document.getElementById('tabItems').setAttribute('aria-selected', String(!onQuotes));
  document.getElementById('tabQuotes').setAttribute('aria-selected', String(onQuotes));
  window.scrollTo({top:0,behavior:'smooth'});
}

// The file input is the source of truth; removing a file rebuilds it via DataTransfer.
const quotes = document.getElementById('quotes');
const dropzone = document.getElementById('dropzone');
let thumbUrls = [];
function renderQuotes(){
  const files = [...quotes.files];
  document.getElementById('stepQuoteCount').textContent = files.length;
  document.getElementById('quoteEmpty').hidden = files.length > 0;
  thumbUrls.forEach(URL.revokeObjectURL);
  thumbUrls = [];
  document.getElementById('quoteList').innerHTML = files.map((f,i)=>{
    let thumb = `<span class="ft-pill">${esc((f.name.split('.').pop()||'file').toUpperCase())}</span>`;
    if(f.type.startsWith('image/')){
      const u = URL.createObjectURL(f); thumbUrls.push(u);
      thumb = `<img class="ft-thumb" src="${u}" alt="">`;
    }
    return `<li class="attach-row">
      ${thumb}
      <span class="clip">${CLIP}</span>
      <span class="fn">${esc(f.name)}</span>
      <span class="sz muted small">${size(f.size)}</span>
      <button type="button" class="x" title="Remove" onclick="dropQuote(${i})">×</button>
    </li>`;
  }).join('');
}
function dropQuote(idx){
  const dt = new DataTransfer();
  [...quotes.files].forEach((f,i)=>{ if(i!==idx) dt.items.add(f); });
  quotes.files = dt.files;
  renderQuotes();
}
quotes.addEventListener('change', renderQuotes);
['dragenter','dragover'].forEach(ev=>dropzone.addEventListener(ev,e=>{ e.preventDefault(); dropzone.classList.add('over'); }));
['dragleave','drop'].forEach(ev=>dropzone.addEventListener(ev,e=>{ e.preventDefault(); dropzone.classList.remove('over'); }));
dropzone.addEventListener('drop',e=>{
  const dt = new DataTransfer();
  [...quotes.files].forEach(f=>dt.items.add(f));
  [...e.dataTransfer.files].forEach(f=>dt.items.add(f));
  quotes.files = dt.files;
  renderQuotes();
});
function size(b){
  if(b < 1024) return b + ' B';
  if(b < 1024*1024) return Math.round(b/1024) + ' KB';
  return (b/1024/1024).toFixed(1) + ' MB';
}
let t; document.getElementById('q').addEventListener('input',e=>{ clearTimeout(t); t=setTimeout(()=>load(e.target.value),180); });
function chip(v){ document.getElementById('q').value=v; load(v); }
function fmt(n){ return (Math.round(n*100)/100).toLocaleString('en-MY',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function esc(s){ return (s==null?'':String(s)).replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }

// The delivery-date picker only shows when urgency is "Specify Date Below".
function syncUrgency(){
  const u = document.getElementById('urgency').value;
  const d = document.getElementById('deliveryDate');
  const specify = (u === 'Specify Date Below');
  document.getElementById('deliveryDateField').hidden = !specify;
  d.disabled = !specify;
  if(!specify) d.value = '';
}
// Grey out empty selects (placeholder option) and empty date inputs (dd/mm/yyyy).
document.querySelectorAll('.mr-fields select, .mr-fields input[type=date]').forEach(el=>{
  const ph = () => el.classList.toggle('is-placeholder', el.value === '');
  el.addEventListener('change', ph); el.addEventListener('input', ph); ph();
});
renderCart();
renderQuotes();
load('');
syncUrgency();
</script>
