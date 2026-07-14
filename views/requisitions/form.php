<?php /** @var array $projects @var ?array $req @var ?array $lines */
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

<form id="mrForm" method="post" action="<?= e($base . $action) ?>">
  <?= Csrf::field() ?>
  <div class="card">
    <div class="rq-head">
      <div class="row"><label>MR No. *</label><input name="mr_number" required placeholder="48" value="<?= $editing ? e($req['mr_number']) : '' ?>"></div>
      <div class="row"><label>Project *</label>
        <select name="project_id" required>
          <option value="">— select —</option>
          <?php foreach ($projects as $p): ?><option value="<?= (int)$p['id'] ?>" <?= ($editing && (int)$req['project_id'] === (int)$p['id']) ? 'selected' : '' ?>><?= e($p['project_code']) ?> — <?= e($p['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="row"><label>Requested by</label><input name="requested_by" placeholder="ARASH" value="<?= $editing ? e($req['requested_by']) : '' ?>"></div>
      <div class="row"><label>Request date</label><input name="request_date" type="date" value="<?= $editing ? e($req['request_date']) : '' ?>"></div>
      <div class="row"><label>Delivery date</label><input name="delivery_date" placeholder="A.S.A.P." value="<?= $editing ? e($req['delivery_date']) : '' ?>"></div>
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
        <button type="button" class="btn sm" onclick="openNewProduct()">＋ New product</button>
        <button type="button" class="btn sm secondary" onclick="openOneOff()">＋ One-off (non-catalogue) item</button>
      </div>
      <p class="muted small" style="margin:.5rem 0 0">
        <strong>New product</strong> saves to the catalogue for everyone. <strong>One-off</strong> adds a free-text line to this requisition only.
      </p>
    </div>

    <!-- right: cart -->
    <div class="cart">
      <div class="cart-h"><h3>Requisition</h3><span class="badge muted" id="lineCount">0 lines</span></div>
      <div class="cart-body" id="cart"><div class="cart-empty">No lines yet — add parts from the catalogue.</div></div>
      <div class="cart-foot">
        <div class="cart-total"><span class="muted">Estimated value</span><span class="v" id="total">RM 0.00</span></div>
        <div id="lineInputs"></div>
        <button class="btn" id="raise" style="width:100%" disabled><?= $editing ? 'Save changes →' : 'Raise requisition →' ?></button>
      </div>
    </div>
  </div>
</form>

<!-- New catalogue product modal (outside #mrForm so its fields never submit with the MR) -->
<div class="modal-overlay" id="npOverlay" hidden>
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="npTitle">
    <div class="modal-h">
      <h3 id="npTitle">New catalogue product</h3>
      <button type="button" class="modal-x" onclick="closeNewProduct()" aria-label="Close">×</button>
    </div>
    <div class="modal-b">
      <p class="muted small" style="margin-top:0">Saved to the catalogue and added straight to this requisition.</p>
      <div id="npErr" class="alert" hidden></div>
      <label>Product name *</label>
      <input id="np_name" placeholder="e.g. 6&quot; Victaulic Firelock Coupling" autocomplete="off">
      <div class="row">
        <div><label>Item code</label><input id="np_code" placeholder="auto if blank"></div>
        <div><label>UOM</label><input id="np_uom" placeholder="nos / ft / m" value="nos"></div>
      </div>
      <div class="row">
        <div><label>Brand</label><input id="np_brand" placeholder="Notifier / Victaulic …"></div>
        <div><label>Model</label><input id="np_model"></div>
      </div>
      <div class="row">
        <div><label>Category</label><input id="np_category" placeholder="Fire Alarm / Piping …"></div>
        <div><label>Ref. unit price (MYR)</label><input id="np_price" type="number" step="any" min="0" placeholder="optional"></div>
      </div>
    </div>
    <div class="modal-f">
      <button type="button" class="btn secondary" onclick="closeNewProduct()">Cancel</button>
      <button type="button" class="btn" id="npSave" onclick="saveNewProduct()">Save &amp; add →</button>
    </div>
  </div>
</div>

<!-- One-off (non-catalogue) line modal -->
<div class="modal-overlay" id="ooOverlay" hidden>
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="ooTitle">
    <div class="modal-h">
      <h3 id="ooTitle">One-off item</h3>
      <button type="button" class="modal-x" onclick="closeOneOff()" aria-label="Close">×</button>
    </div>
    <div class="modal-b">
      <p class="muted small" style="margin-top:0">Adds a free-text line to <strong>this requisition only</strong> — it is not saved to the catalogue.</p>
      <div id="ooErr" class="alert" hidden></div>
      <label>Description *</label>
      <input id="oo_name" placeholder="e.g. Site-specific bracket, fabricated on order" autocomplete="off">
      <div class="row">
        <div><label>Quantity *</label><input id="oo_qty" type="number" step="any" min="1" value="1"></div>
        <div><label>UOM</label><input id="oo_uom" placeholder="nos / ft / m" value="nos"></div>
      </div>
      <div class="row">
        <div><label>Est. unit price (MYR)</label><input id="oo_price" type="number" step="any" min="0" placeholder="optional"></div>
        <div></div>
      </div>
    </div>
    <div class="modal-f">
      <button type="button" class="btn secondary" onclick="closeOneOff()">Cancel</button>
      <button type="button" class="btn" onclick="saveOneOff()">Add to requisition →</button>
    </div>
  </div>
</div>

<script>
const BASE = <?= json_encode($base) ?>;
const CSRF = <?= json_encode(Csrf::token()) ?>;
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
// ---- One-off (non-catalogue) line ----
const ooOverlay = document.getElementById('ooOverlay');
function openOneOff(){
  document.getElementById('ooErr').hidden = true;
  document.getElementById('oo_name').value = '';
  document.getElementById('oo_qty').value = '1';
  document.getElementById('oo_uom').value = 'nos';
  document.getElementById('oo_price').value = '';
  ooOverlay.hidden = false;
  setTimeout(()=>document.getElementById('oo_name').focus(),30);
}
function closeOneOff(){ ooOverlay.hidden = true; }
ooOverlay.addEventListener('click',e=>{ if(e.target===ooOverlay) closeOneOff(); });
document.addEventListener('keydown',e=>{ if(e.key==='Escape' && !ooOverlay.hidden) closeOneOff(); });
function saveOneOff(){
  const err = document.getElementById('ooErr');
  const name = document.getElementById('oo_name').value.trim();
  if(!name){ err.textContent='Description is required.'; err.hidden=false; document.getElementById('oo_name').focus(); return; }
  const qty = Math.max(1, parseFloat(document.getElementById('oo_qty').value) || 1);
  const uom = document.getElementById('oo_uom').value.trim() || 'nos';
  const priceRaw = document.getElementById('oo_price').value.trim();
  const price = priceRaw === '' ? null : (parseFloat(priceRaw) || 0);
  const key='x'+(customSeq++);
  cart.set(key,{id:null,code:'custom',name,uom,price,qty,custom:true});
  closeOneOff();
  renderCart();
}

// ---- New catalogue product (inline create) ----
const npOverlay = document.getElementById('npOverlay');
function openNewProduct(){
  document.getElementById('npErr').hidden = true;
  ['name','code','brand','model','category','price'].forEach(f=>document.getElementById('np_'+f).value='');
  document.getElementById('np_uom').value='nos';
  npOverlay.hidden = false;
  setTimeout(()=>document.getElementById('np_name').focus(),30);
}
function closeNewProduct(){ npOverlay.hidden = true; }
npOverlay.addEventListener('click',e=>{ if(e.target===npOverlay) closeNewProduct(); });
document.addEventListener('keydown',e=>{ if(e.key==='Escape' && !npOverlay.hidden) closeNewProduct(); });
async function saveNewProduct(){
  const name = document.getElementById('np_name').value.trim();
  const err = document.getElementById('npErr');
  if(!name){ err.textContent='Product name is required.'; err.hidden=false; document.getElementById('np_name').focus(); return; }
  const btn = document.getElementById('npSave'); btn.disabled=true; btn.textContent='Saving…';
  const body = new URLSearchParams({
    _csrf: CSRF, name,
    item_code: document.getElementById('np_code').value.trim(),
    uom: document.getElementById('np_uom').value.trim(),
    brand: document.getElementById('np_brand').value.trim(),
    model: document.getElementById('np_model').value.trim(),
    category: document.getElementById('np_category').value.trim(),
    unit_price: document.getElementById('np_price').value.trim(),
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
}
document.getElementById('mrForm').addEventListener('submit',e=>{
  const box=document.getElementById('lineInputs'); box.innerHTML=''; let i=0;
  cart.forEach(l=>{
    const add=(n,v)=>{ const inp=document.createElement('input'); inp.type='hidden'; inp.name=`lines[${i}][${n}]`; inp.value=v==null?'':v; box.appendChild(inp); };
    if(!l.custom) add('catalogue_item_id',l.id);
    add('raw_description',l.name); add('qty',l.qty); add('uom',l.uom);
    i++;
  });
  if(i===0){ e.preventDefault(); alert('Add at least one line.'); }
});
let t; document.getElementById('q').addEventListener('input',e=>{ clearTimeout(t); t=setTimeout(()=>load(e.target.value),180); });
function chip(v){ document.getElementById('q').value=v; load(v); }
function fmt(n){ return (Math.round(n*100)/100).toLocaleString('en-MY',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function esc(s){ return (s==null?'':String(s)).replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }
renderCart();
load('');
</script>
