/* Guided Tour / Training Mode.
 * Config from layout: window.STARSHIP_TOUR = {active,base,csrf,sampleDo,waNumber}.
 * Step index lives in sessionStorage so the tour survives page navigations.
 */
(function () {
  var CFG = window.STARSHIP_TOUR || {};
  var BASE = CFG.base || '';
  var KEY = 'starship_tour_step';

  var SEEN = 'starship_tour_seen';
  function markSeen() { try { localStorage.setItem(SEEN, '1'); } catch (e) {} }

  window.starshipTourStart = function () {
    markSeen();
    try { sessionStorage.setItem(KEY, '0'); } catch (e) {}
    post(BASE + '/tour/start');
  };
  window.starshipTourExit = function () {
    try { sessionStorage.removeItem(KEY); } catch (e) {}
    post(BASE + '/tour/exit');
  };
  function post(action) {
    var f = document.createElement('form');
    f.method = 'POST'; f.action = action;
    var i = document.createElement('input'); i.type = 'hidden'; i.name = '_csrf'; i.value = CFG.csrf || '';
    f.appendChild(i); document.body.appendChild(f); f.submit();
  }

  // First-timer nudge: show the "take the tour" tip until it's used or dismissed.
  if (!CFG.active) {
    var tip = document.getElementById('stourTip');
    var seen = false;
    try { seen = localStorage.getItem(SEEN) === '1'; } catch (e) {}
    if (tip && !seen) {
      tip.style.display = '';
      var goBtn = document.getElementById('stourTipGo');
      var xBtn = document.getElementById('stourTipX');
      if (goBtn) goBtn.onclick = window.starshipTourStart;
      if (xBtn) xBtn.onclick = function () { markSeen(); tip.style.display = 'none'; };
    }
    return;
  }

  try {
    var qp = new URLSearchParams(location.search);
    if (qp.get('tour') === '1') sessionStorage.setItem(KEY, '0');
  } catch (e) {}

  var waLine = CFG.waNumber
    ? 'your Starship WhatsApp line <b>+' + CFG.waNumber + '</b>'
    : 'your Starship WhatsApp line';

  var STEPS = [
    { role: 'Everyone', title: 'Welcome to Training Mode 👋',
      html: "<p>You're on a private <b>practice copy</b> with sample data — nothing here touches your live system. We'll walk the full loop in about 7 minutes:</p><ul><li>Raise a material requisition</li><li>Approve it &amp; raise the PO</li><li>Receive a delivery (WhatsApp photo)</li><li>3-way match &amp; confirm it</li></ul><p>Use <b>Next</b> to move along, and feel free to click around — the guide stays with you. Leave anytime with <b>Exit training</b>.</p>" },

    { role: 'Everyone', title: 'Your control centre', go: '/', goLabel: 'Dashboard', target: 'nav',
      html: "<p>This is the <b>Dashboard</b> — pending approvals, deliveries due and exceptions at a glance. The left nav takes you to each part of the flow. Let's start at the beginning.</p>" },

    { role: 'Requester', title: 'Raise a requisition', go: '/requisitions/new', goLabel: 'New requisition', target: '#mrForm',
      html: "<p>A site team needs parts. On this form:</p><ul><li>Pick the <b>Project</b> — use <b>TR-DEMO</b></li><li><b>Search the catalogue</b> (try “gauge”, “CO2”, “bolt”) and hit <b>+ Add</b></li><li>Set quantities in the cart on the right</li><li>Click <b>Raise requisition →</b></li></ul><p>Once it's raised, click <b>Next</b> here.</p>" },

    { role: 'Project Manager', title: 'Approve it', go: '/approvals', goLabel: 'Approvals', target: '.ac-actions',
      html: "<p>Requisitions land here for sign-off. Approving one <b>raises the purchase order</b> to the supplier automatically. Click <b>Approve ✓</b> on a card, then click <b>Next</b>.</p>" },

    { role: 'Procurement', title: 'Check the Purchase Order', go: '/purchase-orders', goLabel: 'Purchase Orders', target: 'table',
      html: "<p>Here are your POs — click any row to open it and see its lines, quantities and totals. This is what the supplier must deliver against. We pre-loaded <b>PO-DEMO-001</b> for the next step.</p>" },

    { role: 'Site / Procurement', title: 'Receive a delivery 📸', sample: true,
      html: "<p>When goods arrive, whoever receives them just <b>photographs the Delivery Order</b> and sends it to " + waLine + ". Starship reads it with AI and replies with the details.</p>" +
            "<p><b>Important:</b> Starship only replies to numbers on its <b>approved-sender list</b> — that's a security control, so an unknown phone can't push documents in. Pick one:</p>" +
            "<p style='margin:.2rem 0 .1rem'><b>A) Try the real WhatsApp send.</b> Approve your phone below, then tap <b>Show the sample DO</b>, snap the screen and send it — you'll get a live reply in seconds.</p>" +
            "<p style='margin:.2rem 0'><b>B) No phone handy?</b> Use the green button to drop the same delivery straight into the practice data.</p>" },

    { role: 'Procurement / PM', title: '3-way match & confirm', go: '/delivery-orders', goLabel: 'Delivery Orders', target: 'table',
      html: "<p>Your delivery shows here. Click <b>Review</b> on the row to open it — Starship has already lined the DO up against the PO (the <b>3-way match</b>). Adjust anything received short or damaged, then <b>Confirm receipt</b>. That closes the loop back to the PO.</p>" },

    { role: 'Admin', title: 'The rest of the system', go: '/', goLabel: 'Dashboard', target: 'nav',
      html: "<p>That's the core loop! The nav also holds your <b>master data</b> — catalogue, suppliers, projects — and (for admins) <b>Settings</b>: users, roles and the WhatsApp hotline. Explore freely; it's all still sample data.</p>" +
            "<p style='background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:.45rem .6rem;font-size:.84rem'>💡 <b>Extra tip:</b> when you're ready for the accounting side, an admin can connect your own <b>Xero organisation</b> under <b>Settings → Xero</b>. After that, approving a requisition also raises the PO straight into Xero. (That's a live setup — not part of this practice run.)</p>" },

    { role: 'Done', title: "You've done the whole flow 🎉",
      html: "<p>Requisition → approval → PO → delivery → 3-way match. That's Starship end to end.</p><p>Click <b>Finish</b> to leave Training Mode and return to your live data. You can retake the tour anytime from the sidebar.</p>", last: true }
  ];

  function clamp(n) { return Math.max(0, Math.min(STEPS.length - 1, n)); }
  function getStep() { var n = parseInt(sessionStorage.getItem(KEY) || '0', 10); return isNaN(n) ? 0 : clamp(n); }
  function setStep(n) { sessionStorage.setItem(KEY, String(clamp(n))); }
  function samePath(go) {
    if (!go) return true;
    var a = location.pathname.replace(/\/+$/, '');
    var b = (BASE + go).replace(/\/+$/, '');
    return a === b || a.endsWith(go.replace(/\/+$/, ''));
  }

  // Navigate to a step. Only an explicit Next/Back navigates the browser; a
  // plain page load just re-draws the panel where you are (so you can click
  // Review, open a PO, etc. without the tour yanking you back).
  function goToStep(n) {
    n = clamp(n);
    setStep(n);
    var s = STEPS[n];
    if (s.go && !samePath(s.go)) { location.href = BASE + s.go; }
    else render();
  }

  var panel, spotEl;
  function clearSpot() { if (spotEl) { spotEl.classList.remove('stour-spot'); spotEl = null; } }
  function spotlight(sel) {
    clearSpot();
    if (!sel) return;
    var el = document.querySelector(sel);
    if (el) { el.classList.add('stour-spot'); spotEl = el; try { el.scrollIntoView({ block: 'center', behavior: 'smooth' }); } catch (e) {} }
  }

  function render() {
    var n = getStep(), step = STEPS[n];
    if (!panel) { panel = document.createElement('div'); panel.className = 'stour-panel'; document.body.appendChild(panel); }
    var pct = Math.round(n / (STEPS.length - 1) * 100);
    var offPage = step.go && !samePath(step.go);
    panel.innerHTML =
      '<div class="hd"><div class="prog">Step ' + (n + 1) + ' of ' + STEPS.length + '</div>' +
      '<div class="ttl">' + step.title + '</div></div>' +
      '<div class="stour-bar"><i style="width:' + pct + '%"></i></div>' +
      '<div class="bd"><span class="stour-role">' + step.role + '</span>' + step.html +
      (offPage ? '<button class="stour-btn sample" id="stourGoto">↦ Open the ' + (step.goLabel || 'screen') + ' screen</button>' : '') +
      (step.sample ?
        '<div class="stour-approve">' +
          '<div class="stour-approve-h">📱 Approve my phone for a real reply</div>' +
          '<input class="stour-inp" id="stourName" placeholder="Your name (e.g. Ahmad)" autocomplete="off">' +
          '<input class="stour-inp" id="stourPhone" placeholder="60123456789 (with country code)" autocomplete="off" inputmode="numeric">' +
          '<button class="stour-btn primary" id="stourApprove" style="width:100%;margin-top:.35rem">✓ Approve my number</button>' +
          '<div class="stour-msg" id="stourApproveMsg"></div>' +
        '</div>' +
        '<button class="stour-btn sample" id="stourSample">📄 Show the sample Delivery Order</button>' +
        '<button class="stour-btn sample" id="stourDropDo" style="background:#16a34a">➕ Or add the delivery (no phone needed)</button>' : '') +
      '</div>' +
      '<div class="ft">' +
      (n > 0 ? '<button class="stour-btn ghost" id="stourBack">← Back</button>' : '') +
      '<span class="sp"></span>' +
      '<button class="stour-btn ghost" id="stourQuit">Exit training</button>' +
      (step.last
        ? '<button class="stour-btn primary" id="stourFinish">Finish ✓</button>'
        : '<button class="stour-btn primary" id="stourNext">Next →</button>') +
      '</div>';

    spotlight(step.target);

    var b;
    if (b = panel.querySelector('#stourNext')) b.onclick = function () { clearSpot(); goToStep(n + 1); };
    if (b = panel.querySelector('#stourBack')) b.onclick = function () { clearSpot(); goToStep(n - 1); };
    if (b = panel.querySelector('#stourQuit')) b.onclick = window.starshipTourExit;
    if (b = panel.querySelector('#stourFinish')) b.onclick = window.starshipTourExit;
    if (b = panel.querySelector('#stourGoto')) b.onclick = function () { location.href = BASE + step.go; };
    if (b = panel.querySelector('#stourSample')) b.onclick = showSample;
    if (b = panel.querySelector('#stourDropDo')) b.onclick = function () { post(BASE + '/tour/sample-do'); };
    if (b = panel.querySelector('#stourApprove')) b.onclick = doApprove;
  }

  function doApprove() {
    var name = (document.getElementById('stourName') || {}).value || '';
    var phone = (document.getElementById('stourPhone') || {}).value || '';
    var msg = document.getElementById('stourApproveMsg');
    var btn = document.getElementById('stourApprove');
    if (!phone.replace(/\D+/g, '')) { msg.className = 'stour-msg err'; msg.textContent = 'Enter your phone number first.'; return; }
    btn.disabled = true; btn.textContent = 'Approving…';
    var body = new URLSearchParams({ _csrf: CFG.csrf || '', name: name, phone: phone });
    fetch(BASE + '/tour/add-sender', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'fetch' }, body: body })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d && d.ok) {
          msg.className = 'stour-msg ok';
          msg.innerHTML = '✓ <b>+' + d.phone + '</b> is approved. Now tap “Show the sample DO” and send the photo from that phone — you\'ll get a reply.';
          btn.textContent = '✓ Approved';
        } else {
          msg.className = 'stour-msg err'; msg.textContent = (d && d.error) || 'Could not approve that number.';
          btn.disabled = false; btn.textContent = '✓ Approve my number';
        }
      })
      .catch(function () { msg.className = 'stour-msg err'; msg.textContent = 'Network error — try again.'; btn.disabled = false; btn.textContent = '✓ Approve my number'; });
  }

  function showSample() {
    var lb = document.createElement('div');
    lb.className = 'stour-lb';
    lb.innerHTML = '<div class="card"><h3>Sample Delivery Order</h3>' +
      '<p>Point your phone at this and send the photo to ' + (CFG.waNumber ? '<b>+' + CFG.waNumber + '</b>' : 'your Starship WhatsApp line') + '. Starship reads it and replies.</p>' +
      '<img src="' + CFG.sampleDo + '" alt="Sample delivery order">' +
      '<div class="x"><button class="stour-btn primary" id="stourLbClose">Done — close</button></div></div>';
    lb.addEventListener('click', function (e) { if (e.target === lb || e.target.id === 'stourLbClose') document.body.removeChild(lb); });
    document.body.appendChild(lb);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', render);
  else render();
})();
