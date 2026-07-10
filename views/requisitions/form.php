<?php /** @var array $projects */
use App\Csrf;
$base = rtrim(parse_url(cfg('app.base_url', ''), PHP_URL_PATH) ?? '', '/'); ?>
<h1 style="margin-bottom:.2rem">New Material Requisition</h1>
<p class="muted small" style="margin-top:0">Form GE(S)-PU-F01/1 — search the catalogue and add parts to the requisition.</p>

<form id="mrForm" method="post" action="<?= e($base) ?>/requisitions/save">
  <?= Csrf::field() ?>
  <div class="card">
    <div class="rq-head">
      <div class="row"><label>MR No. *</label><input name="mr_number" required placeholder="48"></div>
      <div class="row"><label>Project *</label>
        <select name="project_id" required>
          <option value="">— select —</option>
          <?php foreach ($projects as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['project_code']) ?> — <?= e($p['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="row"><label>Requested by</label><input name="requested_by" placeholder="ARASH"></div>
      <div class="row"><label>Request date</label><input name="request_date" type="date"></div>
      <div class="row"><label>Delivery date</label><input name="delivery_date" placeholder="A.S.A.P."></div>
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
      <p style="margin-top:.8rem"><button type="button" class="btn sm secondary" onclick="addCustom()">＋ Add custom (non-catalogue) item</button></p>
    </div>

    <!-- right: cart -->
    <div class="cart">
      <div class="cart-h"><h3>Requisition</h3><span class="badge muted" id="lineCount">0 lines</span></div>
      <div class="cart-body" id="cart"><div class="cart-empty">No lines yet — add parts from the catalogue.</div></div>
      <div class="cart-foot">
        <div class="cart-total"><span class="muted">Estimated value</span><span class="v" id="total">RM 0.00</span></div>
        <div id="lineInputs"></div>
        <button class="btn" id="raise" style="width:100%" disabled>Raise requisition →</button>
      </div>
    </div>
  </div>
</form>

<script>
const BASE = <?= json_encode($base) ?>;
const cart = new Map();      // id -> {id, code, name, uom, price, qty, custom}
let items = [], customSeq = 0;

async function load(q){
  const r = await fetch(`${BASE}/catalogue/search.json?q=${encodeURIComponent(q||'')}`, {headers:{'X-Requested-With':'fetch'}});
  const d = await r.json(); items = d.items || [];
  renderItems();
}
function renderItems(){
  document.getElementById('count').textContent = `${items.length} item${items.length==1?'':'s'}`;
  document.getElementById('items').innerHTML = items.map(it => {
    const added = cart.has('c'+it.id);
    const price = it.unit_price!=null ? `RM ${fmt(it.unit_price)}<span class="per">per ${esc(it.uom)}</span>` : `<span class="per">no ref price</span>`;
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
function addCustom(){
  const name = prompt('Item description (non-catalogue):'); if(!name) return;
  const key='x'+(customSeq++);
  cart.set(key,{id:null,code:'custom',name:name,uom:'nos',price:null,qty:1,custom:true});
  renderCart();
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
load('');
</script>
