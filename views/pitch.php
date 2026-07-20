<?php
/**
 * "How it works" — animated client-pitch showcase.
 * A horizontal flow pipeline: a signed Delivery Order photo travels along a rail
 * through four stations — WhatsApp → AI reads → Starship matches → Xero — each
 * lighting up as the document arrives. Self-contained (own CSS + JS, no queries),
 * loops with two rotating scenarios (a clean match and a short-delivery exception).
 * Built to run on a projector in front of a client.
 */
?>
<style>
/* ---- Pitch stage (scoped) ---------------------------------------------- */
.pitch-stage{position:relative;overflow:hidden;border-radius:18px;color:#fff;
  background:radial-gradient(1200px 600px at 82% -10%,#24408f 0%,#16265c 45%,#101c46 100%);
  padding:2.2rem 2rem 2.2rem;margin-bottom:1.25rem;box-shadow:var(--shadow-lg)}
.pitch-stage::before{content:"";position:absolute;inset:0;pointer-events:none;opacity:.5;
  background-image:radial-gradient(rgba(255,255,255,.07) 1px,transparent 1px);background-size:26px 26px}
.pitch-kicker{font-size:.7rem;letter-spacing:.16em;text-transform:uppercase;font-weight:700;color:#8fa8ee;margin-bottom:.4rem}
.pitch-stage h1{color:#fff;font-size:1.9rem;margin:0 0 .25rem}
.pitch-stage .pitch-sub{color:#c3d0f5;margin:0;font-size:.95rem;max-width:760px}
.pitch-live{position:absolute;top:1.4rem;right:1.6rem;display:inline-flex;align-items:center;gap:.45rem;
  font-size:.72rem;font-weight:700;letter-spacing:.08em;color:#9fe8b8;background:rgba(34,197,94,.12);
  border:1px solid rgba(34,197,94,.35);padding:.3rem .7rem;border-radius:999px}
.pitch-live i{width:8px;height:8px;border-radius:50%;background:#22C55E;animation:p-blink 1.4s infinite}
@keyframes p-blink{0%,100%{box-shadow:0 0 0 0 rgba(34,197,94,.55);opacity:1}50%{box-shadow:0 0 0 6px rgba(34,197,94,0);opacity:.75}}

/* ---- Flow pipeline ------------------------------------------------------ */
.flow-wrap{overflow-x:auto;margin-top:1.8rem;padding-bottom:.4rem}
.flow{position:relative;width:1000px;height:452px;margin:0 auto}
.frail{position:absolute;left:130px;top:118px;width:759px;height:4px;background:rgba(255,255,255,.14);border-radius:99px;z-index:0}
.frail-fill{height:100%;width:0;border-radius:99px;background:linear-gradient(90deg,#1FA855,#2D4DB3 55%,#FF6B35);
  box-shadow:0 0 12px rgba(255,107,53,.45);transition:width .9s var(--ease)}

.fpacket{position:absolute;top:100px;left:130px;transform:translateX(-50%);width:42px;height:42px;border-radius:11px;
  display:grid;place-items:center;font-size:1.15rem;background:#fff;z-index:1;opacity:0;
  box-shadow:0 0 0 2px rgba(255,107,53,.65),0 8px 22px rgba(0,0,0,.45);filter:drop-shadow(0 0 9px rgba(255,107,53,.6));
  transition:left .9s var(--ease),opacity .3s var(--ease)}
.fpacket.go{opacity:1}

.fstation{position:absolute;top:78px;width:224px;text-align:center;opacity:.42;z-index:2;
  transition:opacity .45s var(--ease)}
.fstation.on{opacity:1}
.s0{left:18px;--sc:#1FA855}.s1{left:271px;--sc:#2D4DB3}.s2{left:524px;--sc:#FF6B35}.s3{left:777px;--sc:#13B5EA}

.fnode{width:84px;height:84px;border-radius:50%;margin:0 auto;display:grid;place-items:center;position:relative;
  font-size:2rem;color:#fff;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.2);
  transition:background .35s var(--ease),box-shadow .35s var(--ease),transform .35s var(--ease)}
.fstation.on .fnode{transform:translateY(-2px)}
.s0.on .fnode{background:radial-gradient(circle at 32% 28%,#3ad787,#128a4e);box-shadow:0 0 0 6px rgba(31,168,85,.18),0 12px 28px rgba(0,0,0,.4)}
.s1.on .fnode{background:radial-gradient(circle at 32% 28%,#3d5dc0,#1A2E6F);box-shadow:0 0 0 6px rgba(45,77,179,.22),0 12px 28px rgba(0,0,0,.4)}
.s2.on .fnode{background:radial-gradient(circle at 32% 28%,#ff8a5c,#e5561f);box-shadow:0 0 0 6px rgba(255,107,53,.22),0 12px 28px rgba(0,0,0,.4)}
.s3.on .fnode{background:radial-gradient(circle at 32% 28%,#5fd0f5,#0f97c4);box-shadow:0 0 0 6px rgba(19,181,234,.22),0 12px 28px rgba(0,0,0,.4)}
.fnode .fai{font-family:'Plus Jakarta Sans';font-weight:800;font-size:1.35rem;letter-spacing:.02em}
.fnode .fxm{font-family:'Plus Jakarta Sans';font-weight:900;font-size:1.7rem}
.fstation.on .fnode::after{content:"";position:absolute;inset:-7px;border-radius:50%;border:2px solid var(--sc);
  opacity:0;animation:fring 1.7s ease-out infinite}
.flow.thinking .s1.on .fnode::after{animation-duration:.95s}
@keyframes fring{0%{transform:scale(.86);opacity:.7}100%{transform:scale(1.38);opacity:0}}

.fhead{margin-top:.6rem}
.fhead b{display:block;font-size:.92rem;color:#fff}
.fhead span{display:block;font-size:.68rem;color:#9db4ea;margin-top:.05rem}

.fcard{margin-top:.75rem;background:#fff;color:var(--fe-text);border-radius:12px;padding:.7rem .75rem;text-align:left;
  box-shadow:0 14px 34px rgba(0,0,0,.32);min-height:158px;opacity:0;transform:translateY(12px);
  transition:opacity .4s var(--ease),transform .4s var(--ease)}
.fstation.on .fcard{opacity:1;transform:none}
.fcard-t{font-size:.6rem;font-weight:800;letter-spacing:.16em;color:var(--fe-accent);margin-bottom:.4rem}
.fcard-t .cur{display:inline-block;width:6px;height:9px;background:var(--fe-accent);margin-left:3px;animation:p-cur 1s steps(2) infinite;vertical-align:-1px}
@keyframes p-cur{50%{opacity:0}}

/* Station 0 — WhatsApp */
.fwa-doc{display:flex;align-items:center;gap:.5rem;background:rgba(11,127,111,.1);border-radius:8px;padding:.4rem .5rem}
.fwa-pg{width:26px;height:32px;background:#fff;border:1px solid #cbd5e1;border-radius:3px;position:relative;flex-shrink:0}
.fwa-pg::before{content:"";position:absolute;inset:5px 4px auto;height:2px;background:#94a3b8;box-shadow:0 5px 0 #cbd5e1,0 10px 0 #cbd5e1,0 15px 0 #cbd5e1}
.fwa-pg::after{content:"✍";position:absolute;right:-2px;bottom:-4px;font-size:.7rem}
.fwa-doc b{font-size:.78rem;line-height:1.2}.fwa-doc small{display:block;color:#6b7a8d;font-size:.62rem}
.fwa-meta{font-size:.72rem;color:#25324a;margin-top:.45rem}
.fwa-tick{color:#34b7f1;font-weight:700}
.fwa-reply{margin-top:.5rem;background:#dcf8c6;border-radius:8px;padding:.45rem .55rem;font-size:.72rem;line-height:1.35;
  opacity:0;transform:translateY(6px);transition:opacity .35s var(--ease),transform .35s var(--ease)}
.fwa-reply.show{opacity:1;transform:none}
.fwa-reply small{display:block;color:#6b7a8d;font-size:.6rem;margin-top:.15rem}

/* Station 1 — AI extraction */
.fex{margin:0;font-size:.68rem;line-height:1.55;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;white-space:pre;overflow:hidden;color:#25324a}

/* Station 2 — 3-way match */
.fmatch .mrow{display:flex;align-items:center;justify-content:center;gap:.45rem;font-weight:700;font-size:.78rem;margin-bottom:.5rem}
.fmatch .mrow .lk{color:var(--fe-accent);font-size:.95rem}
.fmatch .mrow .b{background:#eef2ff;color:var(--fe-primary-2);border-radius:6px;padding:.05rem .4rem}
.fmatch .mline{display:flex;justify-content:space-between;align-items:center;font-size:.72rem;padding:.2rem .1rem;
  border-bottom:1px dashed var(--fe-border);opacity:0;transform:translateX(-6px);transition:opacity .3s var(--ease),transform .3s var(--ease)}
.fmatch .mline.show{opacity:1;transform:none}
.fmatch .mline .ck{color:#15803d;font-weight:800}
.fmatch .mline.warn .ck{color:#b45309}
.fmatch .verdict{margin-top:.55rem}

/* Station 3 — Xero */
.fxero .xh{display:flex;align-items:center;gap:.45rem;font-weight:700;font-size:.8rem;margin-bottom:.5rem}
.fxero .xdot{width:18px;height:18px;border-radius:50%;background:#13B5EA;color:#fff;display:grid;place-items:center;font-size:.62rem;font-weight:900;flex-shrink:0}
.fxero .xrow{display:flex;justify-content:space-between;align-items:baseline;font-size:.78rem;padding:.15rem 0}
.fxero .xrow b{font-family:'Plus Jakarta Sans';font-size:.9rem}
.fxero .xrow.muted{color:var(--fe-muted);font-size:.66rem}
.fxero .xbadge{margin-top:.55rem}

.flow-caption{text-align:center;color:#9db4ea;font-size:.82rem;margin-top:1.3rem;min-height:1.3em;transition:opacity .3s}
.flow-caption.dim{opacity:0}

/* ---- Full journey strip ------------------------------------------------- */
.pitch-journey h2{margin-bottom:.35rem}
.pitch-journey .pj-sub{color:var(--fe-muted);font-size:.85rem;margin:0 0 1.1rem}
.pj{position:relative;display:grid;grid-template-columns:repeat(6,1fr);gap:.7rem}
.pj::before{content:"";position:absolute;left:3%;right:3%;top:21px;height:2px;background:var(--fe-border)}
.pj-fill{position:absolute;left:3%;top:21px;height:2px;width:0;background:linear-gradient(90deg,var(--fe-primary-2),var(--fe-accent));
  transition:width .9s var(--ease);max-width:94%}
.pj-step{position:relative;text-align:center;padding-top:48px;transition:transform .3s var(--ease)}
.pj-step .ic{position:absolute;top:0;left:50%;transform:translateX(-50%);width:44px;height:44px;border-radius:50%;
  background:#fff;border:2px solid var(--fe-border);display:grid;place-items:center;font-size:1.15rem;
  transition:border-color .3s,box-shadow .3s,background .3s}
.pj-step b{display:block;font-size:.8rem;line-height:1.3}
.pj-step span{display:block;color:var(--fe-muted);font-size:.68rem;line-height:1.35;margin-top:.1rem}
.pj-step.done .ic{border-color:#22C55E;background:#f0fdf4}
.pj-step.now{transform:translateY(-3px)}
.pj-step.now .ic{border-color:var(--fe-accent);box-shadow:0 0 0 5px rgba(255,107,53,.16),0 0 0 1px rgba(255,107,53,.3);animation:p-heart 1s infinite}
@keyframes p-heart{0%,100%{box-shadow:0 0 0 4px rgba(255,107,53,.18)}50%{box-shadow:0 0 0 9px rgba(255,107,53,.06)}}

@media (prefers-reduced-motion:reduce){
  .pitch-live i,.fstation.on .fnode::after,.pj-step.now .ic,.fpacket{animation:none;transition:none}
}
</style>

<div class="pitch-stage">
  <span class="pitch-live"><i></i> LIVE DEMO</span>
  <div class="pitch-kicker">Starship · Globe Engineering</div>
  <h1>From site photo to accounts — automatically</h1>
  <p class="pitch-sub">A signed delivery order is photographed on site. It flows straight through Starship — read by AI, matched to the PO, and booked into Xero — before the driver leaves the gate.</p>

  <div class="flow-wrap">
    <div class="flow" id="flow">
      <div class="frail"><div class="frail-fill" id="railFill"></div></div>
      <div class="fpacket" id="packet"><span>📄</span></div>

      <!-- Station 0 — WhatsApp -->
      <div class="fstation s0" id="st0">
        <div class="fnode">💬</div>
        <div class="fhead"><b>WhatsApp</b><span>signed DO arrives</span></div>
        <div class="fcard fwa">
          <div class="fwa-doc"><span class="fwa-pg"></span><div><b id="waDoc">DO 130536</b><small id="waSub">signed · photo</small></div></div>
          <div class="fwa-meta">📎 1 photo <span class="fwa-tick">✓✓</span></div>
          <div class="fwa-reply" id="waReply"><span id="waReplyTxt">✅ Matched</span><small>Starship · instant reply</small></div>
        </div>
      </div>

      <!-- Station 1 — AI reads -->
      <div class="fstation s1" id="st1">
        <div class="fnode"><span class="fai">AI</span></div>
        <div class="fhead"><b>AI reads it</b><span>OCR · every line</span></div>
        <div class="fcard">
          <div class="fcard-t"><span id="exTitle">READING…</span><span class="cur"></span></div>
          <pre class="fex" id="exLines"></pre>
        </div>
      </div>

      <!-- Station 2 — Starship matches -->
      <div class="fstation s2" id="st2">
        <div class="fnode">🚀</div>
        <div class="fhead"><b>Starship matches</b><span>3-way, line by line</span></div>
        <div class="fcard fmatch">
          <div class="mrow"><span class="b" id="mA">DO 130536</span><span class="lk">⇄</span><span class="b" id="mB">PO-0231</span></div>
          <div id="mLines"></div>
          <em class="badge ok verdict" id="verdict">MATCHED</em>
        </div>
      </div>

      <!-- Station 3 — Xero -->
      <div class="fstation s3" id="st3">
        <div class="fnode"><span class="fxm">X</span></div>
        <div class="fhead"><b>Booked to Xero</b><span>coded to project</span></div>
        <div class="fcard fxero">
          <div class="xh"><span class="xdot">X</span> Xero · Draft Bill</div>
          <div class="xrow"><span id="xName">Seng Choon Hardware</span><b id="xAmt">RM 4,820.00</b></div>
          <div class="xrow muted"><span id="xMeta">Project V50 · SST 6%</span></div>
          <em class="badge ok xbadge" id="xBadge">BILL READY</em>
        </div>
      </div>
    </div>
  </div>
  <div class="flow-caption" id="caption"></div>
</div>

<div class="card pitch-journey">
  <h2>⚡ The full journey</h2>
  <p class="pj-sub">Every step your team does on paper today — digitised, connected, and traceable end to end.</p>
  <div class="pj" id="pj">
    <div class="pj-fill" id="pjFill"></div>
    <div class="pj-step"><span class="ic">📝</span><b>MR raised on site</b><span>foreman lists materials, sets urgency</span></div>
    <div class="pj-step"><span class="ic">✅</span><b>PM approves</b><span>one click, full audit trail</span></div>
    <div class="pj-step"><span class="ic">📦</span><b>POs auto-split</b><span>one MR → a PO per supplier</span></div>
    <div class="pj-step"><span class="ic">📸</span><b>DO photographed</b><span>signed copy → WhatsApp hotline</span></div>
    <div class="pj-step"><span class="ic">🤖</span><b>AI reads &amp; matches</b><span>OCR + 3-way match, exceptions flagged</span></div>
    <div class="pj-step"><span class="ic">📊</span><b>Books stay current</b><span>Xero bill drafted, PO balances live</span></div>
  </div>
</div>

<script>
(function () {
  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var $ = function (id) { return document.getElementById(id); };
  var flow = $('flow'), packet = $('packet');
  var X = [130, 383, 636, 889];              // station centre x-coords on the rail
  var PCT = [0, 33.3, 66.6, 100];            // rail-fill % at each station

  var SCEN = [
    { wa: 'DO 130536 · Seng Choon', sub: 'signed · GI sheets',
      ex: ['Supplier : SENG CHOON', 'DO No    : 130536 · 18 Jul', 'PO Ref   : PO-0231 · V50',
           'GI Sheet 1.2mm … 40 ✓', 'Mech Coupling 2" … 24 ✓', 'U-Bolt 50mm … 100 ✓'],
      mA: 'DO 130536', mB: 'PO-0231',
      ml: [['GI Sheet  40/40', 1], ['Coupling  24/24', 1], ['U-Bolt  100/100', 1]],
      verdict: '12 LINES MATCHED', vTone: 'ok',
      xName: 'Seng Choon Hardware', xAmt: 'RM 4,820.00', xMeta: 'Project V50 · SST 6%',
      xBadge: 'BILL READY', xTone: 'ok',
      reply: '✅ DO 130536 matched to PO-0231 — 12 lines OK. Receipts posted.' },
    { wa: 'DO 8842 · Unique Fire', sub: 'signed · CO₂ cylinders',
      ex: ['Supplier : UNIQUE FIRE', 'DO No    : 8842 · 19 Jul', 'PO Ref   : PO-0244 · UOA',
           'CO₂ Cylinder 5kg … 10 ✓', 'Pressure Gauge … 6 ✓', 'Hose Reel 30m … 2 of 4 ⚠'],
      mA: 'DO 8842', mB: 'PO-0244',
      ml: [['CO₂ Cylinder  10/10', 1], ['Pressure Gauge  6/6', 1], ['Hose Reel  2/4', 0]],
      verdict: '1 LINE SHORT — FLAGGED', vTone: 'warn',
      xName: 'Unique Fire Industry', xAmt: '—', xMeta: 'held — awaiting full delivery',
      xBadge: 'HELD FOR REVIEW', xTone: 'warn',
      reply: '⚠️ DO 8842 — 1 line short-delivered. Flagged for review.' },
  ];

  var si = 0, timers = [];
  function at(ms, fn) { timers.push(setTimeout(fn, ms)); }
  function clear() { timers.forEach(clearTimeout); timers = []; }
  function caption(t) { var c = $('caption'); c.classList.add('dim');
    setTimeout(function () { c.textContent = t; c.classList.remove('dim'); }, 250); }

  function moveTo(i) { packet.classList.add('go'); packet.style.left = X[i] + 'px'; $('railFill').style.width = PCT[i] + '%'; }
  function lightUp(i) { $('st' + i).classList.add('on'); }

  function type(el, lines, i, done) {
    if (i >= lines.length) { done && done(); return; }
    el.textContent += (i ? '\n' : '') + lines[i];
    timers.push(setTimeout(function () { type(el, lines, i + 1, done); }, reduce ? 0 : 360));
  }

  function fillStatic(s) {
    $('waDoc').textContent = s.wa; $('waSub').textContent = s.sub;
    $('waReplyTxt').textContent = s.reply;
    $('exLines').textContent = ''; $('exTitle').textContent = 'READING…';
    $('mA').textContent = s.mA; $('mB').textContent = s.mB; $('mLines').innerHTML = '';
    var v = $('verdict'); v.textContent = s.verdict; v.className = 'badge ' + s.vTone + ' verdict';
    $('xName').textContent = s.xName; $('xAmt').textContent = s.xAmt; $('xMeta').textContent = s.xMeta;
    var xb = $('xBadge'); xb.textContent = s.xBadge; xb.className = 'badge ' + s.xTone + ' xbadge';
  }
  function paintMatchLines(s, animate) {
    $('mLines').innerHTML = '';
    s.ml.forEach(function (m, k) {
      var d = document.createElement('div');
      d.className = 'mline' + (m[1] ? '' : ' warn') + (animate ? '' : ' show');
      d.innerHTML = '<span>' + m[0] + '</span><span class="ck">' + (m[1] ? '✓' : '⚠') + '</span>';
      $('mLines').appendChild(d);
      if (animate) timers.push(setTimeout(function () { d.classList.add('show'); }, 250 * k));
    });
  }

  function run() {
    clear();
    var s = SCEN[si]; si = (si + 1) % SCEN.length;
    // reset
    [0, 1, 2, 3].forEach(function (i) { $('st' + i).classList.remove('on'); });
    flow.classList.remove('thinking');
    packet.classList.remove('go'); packet.style.left = X[0] + 'px'; $('railFill').style.width = '0%';
    $('waReply').classList.remove('show');
    fillStatic(s);
    caption('A signed delivery order is photographed on site and sent to the Starship WhatsApp hotline…');

    at(500,  function () { packet.classList.add('go'); lightUp(0); });
    at(1600, function () { moveTo(1);
      caption('Starship AI reads the photo — supplier, DO number, PO reference, every line.'); });
    at(2500, function () { lightUp(1); flow.classList.add('thinking');
      type($('exLines'), s.ex, 0, function () { $('exTitle').textContent = 'EXTRACTED ✓'; }); });
    at(5000, function () { flow.classList.remove('thinking'); moveTo(2);
      caption('It 3-way matches the delivery against the original purchase order — line by line.'); });
    at(5900, function () { lightUp(2); paintMatchLines(s, true); });
    at(7300, function () { moveTo(3);
      caption('The verified bill is drafted straight into Xero, coded to the project.'); });
    at(8200, function () { lightUp(3); });
    at(9200, function () { $('waReply').classList.add('show');
      caption('Seconds later, the site team gets the result back in WhatsApp — nothing re-keyed.'); });
    at(13800, run);
  }

  // Journey strip — its own gentle loop.
  var steps = Array.prototype.slice.call(document.querySelectorAll('#pj .pj-step'));
  var ji = 0;
  function journey() {
    steps.forEach(function (st, i) { st.classList.toggle('now', i === ji); st.classList.toggle('done', i < ji); });
    $('pjFill').style.width = (ji / (steps.length - 1) * 94) + '%';
    ji = (ji + 1) % (steps.length + 1);
    if (ji === 0) steps.forEach(function (st) { st.classList.add('done'); });
    setTimeout(journey, ji === 0 ? 2600 : 1900);
  }

  if (reduce) {
    var s0 = SCEN[0];
    fillStatic(s0); paintMatchLines(s0, false);
    $('exLines').textContent = s0.ex.join('\n'); $('exTitle').textContent = 'EXTRACTED ✓';
    [0, 1, 2, 3].forEach(lightUp);
    packet.classList.add('go'); packet.style.left = X[3] + 'px'; $('railFill').style.width = '100%';
    $('waReply').classList.add('show');
    steps.forEach(function (st) { st.classList.add('done'); }); $('pjFill').style.width = '94%';
  } else {
    run(); journey();
  }
})();
</script>
