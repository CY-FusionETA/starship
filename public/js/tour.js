/* Guided Tour / Training Mode.
 * Config is provided by the layout as window.STARSHIP_TOUR = {active,base,csrf,sampleDo}.
 * Step index lives in sessionStorage so the tour survives page navigations.
 */
(function () {
  var CFG = window.STARSHIP_TOUR || {};
  var BASE = CFG.base || '';
  var KEY = 'starship_tour_step';

  // --- launcher (shown when not in a tour) --------------------------------
  window.starshipTourStart = function (btn) {
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

  if (!CFG.active) return;   // launcher wired above; nothing more to do off-tour

  // Fresh start (redirected to /?tour=1) resets to the first step.
  try {
    var qp = new URLSearchParams(location.search);
    if (qp.get('tour') === '1') sessionStorage.setItem(KEY, '0');
  } catch (e) {}

  // --- the walkthrough ----------------------------------------------------
  var STEPS = [
    { role: 'Everyone', title: 'Welcome to Training Mode 👋',
      html: "<p>You're on a private <b>practice copy</b> with sample data — nothing here touches your live system. We'll walk the full loop in about 7 minutes:</p><ul><li>Raise a material requisition</li><li>Approve it &amp; raise the PO</li><li>Receive a delivery by WhatsApp photo</li><li>3-way match &amp; confirm it</li></ul><p>Use <b>Next</b> to move along. You can leave anytime with <b>Exit training</b>.</p>" },

    { role: 'Everyone', title: 'Your control centre', go: '/', target: 'nav',
      html: "<p>This is the <b>Dashboard</b> — pending approvals, deliveries due and exceptions at a glance. The left nav takes you to each part of the flow. Let's start at the beginning.</p>" },

    { role: 'Requester', title: 'Raise a requisition', go: '/requisitions/new', target: '#mrForm',
      html: "<p>A site team needs parts. On this form:</p><ul><li>Pick the <b>Project</b> (use <b>TR-DEMO</b>)</li><li><b>Search the catalogue</b> — try “gauge”, “CO2”, “bolt” — and hit <b>+ Add</b></li><li>Set quantities in the cart on the right</li><li>Click <b>Raise requisition →</b></li></ul><p>When it's raised, come back and hit Next.</p>" },

    { role: 'Project Manager', title: 'Approve it', go: '/approvals', target: '.ac-actions',
      html: "<p>Requisitions land here for sign-off. Approving one <b>raises the purchase order</b> to the supplier automatically. Click <b>Approve ✓</b> on a card, then hit Next.</p>" },

    { role: 'Procurement', title: 'Check the Purchase Order', go: '/purchase-orders', target: 'table',
      html: "<p>Here are your POs. Open one to see its lines, quantities and totals — this is what the supplier is expected to deliver against. We pre-loaded <b>PO-DEMO-001</b> for the next step.</p>" },

    { role: 'Site / Procurement', title: 'Receive a delivery by WhatsApp 📸', sample: true,
      html: "<p>When goods arrive, the person receiving them just <b>photographs the Delivery Order</b> and sends it to your Starship WhatsApp line. Starship reads it with AI and replies with the details.</p><p>Try it now: tap the button below to show a sample DO, <b>pick up your phone, snap the screen</b>, and send it to your Starship number. You'll get a reply in seconds.</p>" },

    { role: 'Procurement / PM', title: '3-way match & confirm', go: '/delivery-orders', target: 'table',
      html: "<p>Your delivery appears here. Open it to see Starship's <b>3-way match</b> — it lines the DO up against the PO automatically. Adjust anything received short or damaged, then <b>Confirm receipt</b>. That closes the loop back to the PO.</p>" },

    { role: 'Admin', title: 'The rest of the system', go: '/', target: 'nav',
      html: "<p>That's the core loop! The nav also holds your <b>master data</b> — catalogue, suppliers, projects — and <b>Settings</b> (users, roles and the WhatsApp hotline). Explore freely; it's all still sample data.</p>" },

    { role: 'Done', title: "You've done the whole flow 🎉",
      html: "<p>Requisition → approval → PO → WhatsApp delivery → 3-way match. That's Starship end to end.</p><p>Click <b>Finish</b> to leave Training Mode and return to your live data. You can retake the tour anytime from the sidebar.</p>", last: true }
  ];

  function getStep() { var n = parseInt(sessionStorage.getItem(KEY) || '0', 10); return isNaN(n) ? 0 : Math.max(0, Math.min(STEPS.length - 1, n)); }
  function setStep(n) { sessionStorage.setItem(KEY, String(Math.max(0, Math.min(STEPS.length - 1, n)))); }
  function samePath(go) {
    if (!go) return true;
    var a = location.pathname.replace(/\/+$/, '');
    var b = (BASE + go).replace(/\/+$/, '');
    return a === b || a.endsWith(go.replace(/\/+$/, ''));
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
    // If this step belongs on another screen, go there first; it re-renders on load.
    if (step.go && !samePath(step.go)) { location.href = BASE + step.go; return; }

    if (!panel) { panel = document.createElement('div'); panel.className = 'stour-panel'; document.body.appendChild(panel); }
    var pct = Math.round((n) / (STEPS.length - 1) * 100);
    panel.innerHTML =
      '<div class="hd"><div class="prog">Step ' + (n + 1) + ' of ' + STEPS.length + '</div>' +
      '<div class="ttl">' + step.title + '</div></div>' +
      '<div class="stour-bar"><i style="width:' + pct + '%"></i></div>' +
      '<div class="bd"><span class="stour-role">' + step.role + '</span>' + step.html +
      (step.sample ? '<button class="stour-btn sample" id="stourSample">📄 Show the sample Delivery Order</button>' : '') +
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
    if (b = panel.querySelector('#stourNext')) b.onclick = function () { clearSpot(); setStep(n + 1); render(); };
    if (b = panel.querySelector('#stourBack')) b.onclick = function () { clearSpot(); setStep(n - 1); render(); };
    if (b = panel.querySelector('#stourQuit')) b.onclick = window.starshipTourExit;
    if (b = panel.querySelector('#stourFinish')) b.onclick = window.starshipTourExit;
    if (b = panel.querySelector('#stourSample')) b.onclick = showSample;
  }

  function showSample() {
    var lb = document.createElement('div');
    lb.className = 'stour-lb';
    lb.innerHTML = '<div class="card"><h3>Sample Delivery Order</h3>' +
      '<p>Point your phone at this and send the photo to your Starship WhatsApp line. Starship will read it and reply.</p>' +
      '<img src="' + CFG.sampleDo + '" alt="Sample delivery order">' +
      '<div class="x"><button class="stour-btn primary" id="stourLbClose">Done — close</button></div></div>';
    lb.addEventListener('click', function (e) { if (e.target === lb || e.target.id === 'stourLbClose') document.body.removeChild(lb); });
    document.body.appendChild(lb);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', render);
  else render();
})();
