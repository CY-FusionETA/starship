<?php /** @var array $stats */
$base = rtrim(parse_url(cfg('app.base_url', ''), PHP_URL_PATH) ?? '', '/'); ?>
<h1>Dashboard</h1>
<p class="muted">Procurement backbone for Globe Engineering — catalogue, requisitions and purchase orders, with AI delivery-order matching to follow.</p>
<div class="grid cols-3" style="margin-top:1rem">
  <a class="stat" href="<?= e($base) ?>/catalogue" style="text-decoration:none">
    <div class="num"><?= (int)$stats['catalogue'] ?></div><div class="lbl">Catalogue items</div>
  </a>
  <a class="stat" href="<?= e($base) ?>/suppliers" style="text-decoration:none">
    <div class="num"><?= (int)$stats['suppliers'] ?></div><div class="lbl">Suppliers</div>
  </a>
  <a class="stat" href="<?= e($base) ?>/projects" style="text-decoration:none">
    <div class="num"><?= (int)$stats['projects'] ?></div><div class="lbl">Projects / sites</div>
  </a>
</div>
<div class="card" style="margin-top:1.25rem">
  <h2>Next up</h2>
  <p class="muted small">Phase 2 — Material Requisition → Purchase Order. Phase 3 — delivery-order capture &amp; 3-way matching. Phase 4 — Gemini OCR. Phase 5 — WhatsApp hotline. Phase 6 — Xero.</p>
</div>
