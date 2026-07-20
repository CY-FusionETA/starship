<?php
/**
 * "How it works" — animated client-pitch showcase.
 * Self-contained (own CSS + JS, no data queries): a scripted, looping story of
 * a signed DO travelling WhatsApp → AI OCR → 3-way match → Xero, plus the full
 * MR→PO journey strip. Built to run on a projector in front of a client.
 */
?>
<style>
/* ---- Pitch page (scoped) ------------------------------------------------ */
.pitch-stage{position:relative;overflow:hidden;border-radius:18px;color:#fff;
  background:radial-gradient(1200px 600px at 80% -10%,#24408f 0%,#16265c 45%,#101c46 100%);
  padding:2.2rem 2rem 2.4rem;margin-bottom:1.25rem;box-shadow:var(--shadow-lg)}
.pitch-stage::before{content:"";position:absolute;inset:0;pointer-events:none;opacity:.5;
  background-image:radial-gradient(rgba(255,255,255,.07) 1px,transparent 1px);background-size:26px 26px}
.pitch-kicker{font-size:.7rem;letter-spacing:.16em;text-transform:uppercase;font-weight:700;color:#8fa8ee;margin-bottom:.4rem}
.pitch-stage h1{color:#fff;font-size:1.9rem;margin:0 0 .25rem}
.pitch-stage .pitch-sub{color:#c3d0f5;margin:0;font-size:.95rem}
.pitch-live{position:absolute;top:1.4rem;right:1.6rem;display:inline-flex;align-items:center;gap:.45rem;
  font-size:.72rem;font-weight:700;letter-spacing:.08em;color:#9fe8b8;background:rgba(34,197,94,.12);
  border:1px solid rgba(34,197,94,.35);padding:.3rem .7rem;border-radius:999px}
.pitch-live i{width:8px;height:8px;border-radius:50%;background:#22C55E;animation:p-blink 1.4s infinite}
@keyframes p-blink{0%,100%{box-shadow:0 0 0 0 rgba(34,197,94,.55);opacity:1}50%{box-shadow:0 0 0 6px rgba(34,197,94,0);opacity:.75}}

/* Diagram canvas — fixed coordinate space, scrolls on small screens */
.pd-wrap{overflow-x:auto;margin-top:1.6rem}
.pd{position:relative;width:1000px;height:430px;margin:0 auto}
.pd-svg{position:absolute;inset:0;width:100%;height:100%;overflow:visible}
.pd-svg line{stroke:rgba(255,255,255,.28);stroke-width:2;stroke-dasharray:3 7;stroke-linecap:round}
.pd-dot{fill:#FF6B35;filter:drop-shadow(0 0 6px rgba(255,107,53,.9));opacity:0}
.pd-dot.go{opacity:1}
.pd-node{position:absolute;transition:opacity .4s var(--ease),transform .4s var(--ease)}

/* WhatsApp card (left) */
.pd-wa{left:0;top:60px;width:250px;border-radius:14px;overflow:hidden;background:#e7e0d4;
  box-shadow:0 14px 34px rgba(0,0,0,.35);font-size:.8rem}
.pd-wa-head{background:#0b7f6f;color:#fff;padding:.55rem .75rem;display:flex;align-items:center;gap:.55rem}
.pd-wa-head .av{width:26px;height:26px;border-radius:50%;background:#fff;color:#0b7f6f;font-weight:800;
  display:grid;place-items:center;font-size:.75rem;flex-shrink:0}
.pd-wa-head b{display:block;font-size:.78rem;line-height:1.2}
.pd-wa-head span{display:block;font-size:.62rem;opacity:.85}
.pd-wa-body{padding:.7rem .6rem .8rem;min-height:150px;display:flex;flex-direction:column;gap:.5rem}
.pd-msg{max-width:88%;border-radius:10px;padding:.5rem .6rem;line-height:1.35;color:#1C2B3A;box-shadow:0 1px 2px rgba(0,0,0,.12);
  opacity:0;transform:translateY(8px) scale(.96);transition:opacity .35s var(--ease),transform .35s var(--ease)}
.pd-msg.show{opacity:1;transform:none}
.pd-msg.out{background:#dcf8c6;align-self:flex-end}
.pd-msg.in{background:#fff;align-self:flex-start}
.pd-msg .doc{display:flex;align-items:center;gap:.5rem;background:rgba(11,127,111,.1);border-radius:8px;padding:.4rem .5rem;margin-bottom:.3rem}
.pd-msg .doc .pg{width:26px;height:32px;background:#fff;border:1px solid #cbd5e1;border-radius:3px;position:relative;flex-shrink:0}
.pd-msg .doc .pg::before{content:"";position:absolute;inset:5px 4px auto;height:2px;background:#94a3b8;box-shadow:0 5px 0 #cbd5e1,0 10px 0 #cbd5e1,0 15px 0 #cbd5e1}
.pd-msg .doc .pg::after{content:"✍";position:absolute;right:-2px;bottom:-4px;font-size:.7rem}
.pd-msg small{display:block;color:#6b7a8d;font-size:.62rem;margin-top:.15rem}
.pd-msg .tick{color:#34b7f1;font-size:.66rem;float:right;margin:.2rem 0 0 .4rem}

/* AI orb + extraction (centre) */
.pd-ai{left:380px;top:38px;width:240px;text-align:center}
.pd-orb{position:relative;width:110px;height:110px;margin:0 auto;border-radius:50%;display:grid;place-items:center;
  background:radial-gradient(circle at 32% 28%,#3d5dc0,#1A2E6F 70%);
  box-shadow:0 0 0 1px rgba(255,255,255,.14),0 16px 40px rgba(0,0,0,.45)}
.pd-orb b{font-family:'Plus Jakarta Sans';font-size:1.5rem;letter-spacing:.02em}
.pd-orb small{position:absolute;bottom:16px;left:0;right:0;font-size:.5rem;letter-spacing:.28em;color:#9db4ea;font-weight:700}
.pd-orb::before,.pd-orb::after{content:"";position:absolute;inset:-10px;border-radius:50%;border:1px solid rgba(143,168,238,.4);
  animation:p-ring 2.6s var(--ease) infinite;opacity:0}
.pd-orb::after{animation-delay:1.3s}
.pd.thinking .pd-orb::before,.pd.thinking .pd-orb::after{animation-duration:1.1s}
@keyframes p-ring{0%{transform:scale(.9);opacity:.9}100%{transform:scale(1.45);opacity:0}}
.pd-extract{margin-top:1.05rem;background:#fff;color:var(--fe-text);border-radius:12px;text-align:left;
  padding:.7rem .8rem;box-shadow:0 14px 34px rgba(0,0,0,.35);min-height:132px;
  opacity:0;transform:translateY(10px);transition:opacity .35s var(--ease),transform .35s var(--ease)}
.pd-extract.show{opacity:1;transform:none}
.pd-extract .ex-t{font-size:.62rem;font-weight:800;letter-spacing:.18em;color:var(--fe-accent);margin-bottom:.35rem}
.pd-extract .ex-t .cur{display:inline-block;width:6px;height:10px;background:var(--fe-accent);margin-left:4px;animation:p-cur 1s steps(2) infinite}
@keyframes p-cur{50%{opacity:0}}
.pd-extract pre{margin:0;font-size:.68rem;line-height:1.55;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;white-space:pre;overflow:hidden;color:#25324a}

/* Outcome cards (right) */
.pd-out{left:772px;width:228px;background:#fff;color:var(--fe-text);border-radius:12px;padding:.65rem .8rem;
  box-shadow:0 14px 34px rgba(0,0,0,.35);opacity:.32;transform:translateX(6px);font-size:.78rem}
.pd-out.on{opacity:1;transform:none;box-shadow:0 0 0 2px rgba(34,197,94,.5),0 14px 34px rgba(0,0,0,.35)}
.pd-out b{display:flex;align-items:center;gap:.4rem;font-size:.85rem}
.pd-out b i{width:8px;height:8px;border-radius:50%;background:#cbd5e1;flex-shrink:0}
.pd-out.on b i{background:#22C55E;animation:p-blink 1.2s infinite}
.pd-out span{display:block;color:var(--fe-muted);font-size:.7rem;margin-top:.15rem}
.pd-out .res{display:none;margin-top:.35rem}
.pd-out.on .res{display:inline-block;animation:p-pop .35s var(--ease)}
@keyframes p-pop{0%{transform:scale(.6);opacity:0}70%{transform:scale(1.08)}100%{transform:scale(1);opacity:1}}
.pd-out1{top:34px}.pd-out2{top:170px}.pd-out3{top:288px}

/* Caption under the diagram */
.pd-caption{text-align:center;color:#9db4ea;font-size:.8rem;margin-top:1.2rem;min-height:1.3em;transition:opacity .3s}
.pd-caption.dim{opacity:0}

/* ---- Full journey strip -------------------------------------------------- */
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
  .pd-orb::before,.pd-orb::after,.pitch-live i,.pj-step.now .ic{animation:none}
}
</style>

<div class="pitch-stage">
  <span class="pitch-live"><i></i> LIVE DEMO</span>
  <div class="pitch-kicker">Starship · Globe Engineering</div>
  <h1>From site photo to accounts — automatically</h1>
  <p class="pitch-sub">A signed delivery order is photographed on site. Starship reads it, matches it, and books it — before the driver leaves the gate.</p>

  <div class="pd-wrap">
    <div class="pd" id="pd">
      <svg class="pd-svg" viewBox="0 0 1000 430" preserveAspectRatio="none" aria-hidden="true">
        <line x1="252" y1="180" x2="390" y2="180"/>
        <line x1="610" y1="130" x2="770" y2="80"/>
        <line x1="610" y1="180" x2="770" y2="212"/>
        <line x1="610" y1="230" x2="770" y2="330"/>
        <circle class="pd-dot" id="dotL" r="5"><animateMotion id="amL" dur="0.9s" begin="indefinite" path="M252,180 L390,180"/></circle>
        <circle class="pd-dot" id="dotR1" r="5"><animateMotion id="amR1" dur="0.9s" begin="indefinite" path="M610,130 L770,80"/></circle>
        <circle class="pd-dot" id="dotR2" r="5"><animateMotion id="amR2" dur="0.9s" begin="indefinite" path="M610,180 L770,212"/></circle>
        <circle class="pd-dot" id="dotR3" r="5"><animateMotion id="amR3" dur="0.9s" begin="indefinite" path="M610,230 L770,330"/></circle>
      </svg>

      <div class="pd-node pd-wa">
        <div class="pd-wa-head"><span class="av">St</span><div><b>Starship Hotline</b><span>online · reading your DOs</span></div></div>
        <div class="pd-wa-body">
          <div class="pd-msg out" id="waPhoto">
            <div class="doc"><span class="pg"></span><div><b id="waDocName">Delivery Order</b><small id="waDocSub">signed · photo</small></div></div>
            📎 1 photo <span class="tick">✓✓</span>
          </div>
          <div class="pd-msg in" id="waReply"><span id="waReplyTxt">✅ Matched</span><small>Starship · instant reply</small></div>
        </div>
      </div>

      <div class="pd-node pd-ai">
        <div class="pd-orb"><b>AI</b><small>STARSHIP</small></div>
        <div class="pd-extract" id="extract">
          <div class="ex-t"><span id="exTitle">EXTRACTING…</span><span class="cur"></span></div>
          <pre id="exLines"></pre>
        </div>
      </div>

      <div class="pd-node pd-out pd-out1" id="oc1">
        <b><i></i> 3-Way Match</b>
        <span id="oc1Sub">MR · PO · DO — line by line</span>
        <em class="badge ok res" id="oc1Res">MATCHED</em>
      </div>
      <div class="pd-node pd-out pd-out2" id="oc2">
        <b><i></i> <span id="oc2Po">Purchase Order</span></b>
        <span id="oc2Sub">balance updated automatically</span>
        <em class="badge brand res">RECEIPTS POSTED</em>
      </div>
      <div class="pd-node pd-out pd-out3" id="oc3">
        <b><i></i> Xero</b>
        <span>supplier bill drafted, coded to project</span>
        <em class="badge ok res">BILL READY</em>
      </div>
    </div>
  </div>
  <div class="pd-caption" id="pdCaption"></div>
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
  var pd = $('pd');

  // Two rotating scenarios so a longer demo never looks canned.
  var SCENARIOS = [
    { doc: 'DO 130536 — Seng Choon', sub: 'signed · GI sheets', po: 'PO-0231 · Seng Choon',
      reply: '✅ DO 130536 matched to PO-0231 — 12 lines OK. Receipts posted.',
      lines: ['Supplier : SENG CHOON', 'DO No    : 130536 · 18 Jul',
              'PO Ref   : PO-0231 · V50', 'GI Sheet 1.2mm … 40 pcs ✓',
              'Mech Coupling 2" … 24 pcs ✓', 'U-Bolt 50mm … 100 pcs ✓'] },
    { doc: 'DO 8842 — Unique Fire', sub: 'signed · CO₂ cylinders', po: 'PO-0244 · Unique Fire',
      reply: '⚠️ DO 8842 — 1 line short-delivered. Flagged for review.',
      lines: ['Supplier : UNIQUE FIRE', 'DO No    : 8842 · 19 Jul',
              'PO Ref   : PO-0244 · UOA', 'CO₂ Cylinder 5kg … 10 ✓',
              'Pressure Gauge … 6 units ✓', 'Hose Reel 30m … 2 of 4 ⚠'] },
  ];
  var si = 0, timers = [];
  function at(ms, fn) { timers.push(setTimeout(fn, ms)); }
  function clear() { timers.forEach(clearTimeout); timers = []; }
  function fire(id) { var el = $(id); if (el && el.beginElement) { try { el.beginElement(); } catch (e) {} } }
  function shoot(dot, motion) {
    var d = $(dot); d.classList.add('go'); fire(motion);
    timers.push(setTimeout(function () { d.classList.remove('go'); }, 900));
  }
  function caption(t) { var c = $('pdCaption'); c.classList.add('dim');
    setTimeout(function () { c.textContent = t; c.classList.remove('dim'); }, 250); }

  function type(el, lines, i, done) {
    if (i >= lines.length) { done && done(); return; }
    el.textContent += (i ? '\n' : '') + lines[i];
    timers.push(setTimeout(function () { type(el, lines, i + 1, done); }, reduce ? 0 : 420));
  }

  function run() {
    clear();
    var s = SCENARIOS[si]; si = (si + 1) % SCENARIOS.length;
    // reset
    ['waPhoto', 'waReply', 'extract'].forEach(function (id) { $(id).classList.remove('show'); });
    ['oc1', 'oc2', 'oc3'].forEach(function (id) { $(id).classList.remove('on'); });
    pd.classList.remove('thinking');
    $('exLines').textContent = ''; $('exTitle').textContent = 'EXTRACTING…';
    $('waDocName').textContent = s.doc; $('waDocSub').textContent = s.sub;
    $('waReplyTxt').textContent = s.reply; $('oc2Po').textContent = s.po;
    caption('A signed DO is photographed on site and sent to the WhatsApp hotline…');

    at(600,  function () { $('waPhoto').classList.add('show'); });
    at(1500, function () { shoot('dotL', 'amL'); });
    at(2300, function () {
      pd.classList.add('thinking'); $('extract').classList.add('show');
      caption('Starship AI reads the photo — supplier, DO number, PO reference, every line…');
      type($('exLines'), s.lines, 0, function () { $('exTitle').textContent = 'EXTRACTED ✓'; });
    });
    at(5600, function () {
      pd.classList.remove('thinking');
      caption('…then 3-way matches it against the MR and PO, and updates everything downstream.');
      shoot('dotR1', 'amR1'); at(350, function () { $('oc1').classList.add('on'); });
    });
    at(6600, function () { shoot('dotR2', 'amR2'); at(350, function () { $('oc2').classList.add('on'); }); });
    at(7600, function () { shoot('dotR3', 'amR3'); at(350, function () { $('oc3').classList.add('on'); }); });
    at(9000, function () {
      $('waReply').classList.add('show');
      caption('The site team gets the verdict back in WhatsApp — seconds after sending the photo.');
    });
    at(13500, run);
  }

  // Journey strip — its own gentle loop.
  var steps = Array.prototype.slice.call(document.querySelectorAll('#pj .pj-step'));
  var ji = 0;
  function journey() {
    steps.forEach(function (st, i) {
      st.classList.toggle('now', i === ji);
      st.classList.toggle('done', i < ji);
    });
    $('pjFill').style.width = (ji / (steps.length - 1) * 94) + '%';
    ji = (ji + 1) % (steps.length + 1);   // one extra beat: all-done pause
    if (ji === 0) steps.forEach(function (st) { st.classList.add('done'); });
    setTimeout(journey, ji === 0 ? 2600 : 1900);
  }

  if (reduce) {
    // Static final state for reduced motion.
    ['waPhoto', 'waReply', 'extract'].forEach(function (id) { $(id).classList.add('show'); });
    ['oc1', 'oc2', 'oc3'].forEach(function (id) { $(id).classList.add('on'); });
    var s0 = SCENARIOS[0];
    $('waDocName').textContent = s0.doc; $('waDocSub').textContent = s0.sub;
    $('waReplyTxt').textContent = s0.reply; $('oc2Po').textContent = s0.po;
    $('exLines').textContent = s0.lines.join('\n'); $('exTitle').textContent = 'EXTRACTED ✓';
    steps.forEach(function (st) { st.classList.add('done'); });
    $('pjFill').style.width = '94%';
  } else {
    run(); journey();
  }
})();
</script>
