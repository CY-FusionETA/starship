<?php /** @var array $aliases */ ?>
<h1>Supplier aliases</h1>
<p class="muted">How each supplier words a part vs the Globe catalogue item it maps to. This table powers fuzzy line matching and grows automatically each time a delivery-order match is confirmed.</p>
<div class="card">
  <table>
    <thead><tr><th>Supplier</th><th>Supplier part code</th><th>Supplier description</th><th>→ Catalogue item</th><th>Confirmed×</th></tr></thead>
    <tbody>
    <?php if (!$aliases): ?><tr><td colspan="5" class="muted">No aliases yet.</td></tr>
    <?php else: foreach ($aliases as $a): ?>
      <tr>
        <td><?= e($a['supplier_name']) ?></td>
        <td><span class="badge muted"><?= e($a['supplier_part_code'] ?: '—') ?></span></td>
        <td><?= e($a['supplier_desc']) ?></td>
        <td><span class="badge brand"><?= e($a['item_code']) ?></span> <?= e($a['item_name']) ?></td>
        <td><?= (int)$a['times_confirmed'] ?></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
