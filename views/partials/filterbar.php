<?php
/**
 * Shared search + filter bar for the list screens.
 *
 * Include after setting:
 *   $fbAction  string  form target, e.g. "/requisitions"
 *   $fbFilters array   current values keyed by field name (from the route)
 *   $fbFields  array   field defs: ['name'=>…, 'label'=>…, 'type'=>'select|date',
 *                                   'options'=>[value => label]  (select only),
 *                                   'all'=>'All statuses'        (select only)]
 *   $fbPlaceholder string  search box hint
 *   $fbShown   int     rows after filtering
 *   $fbTotal   int     rows in total
 *   $fbNoun    string  "requisition" / "purchase order" / "delivery order"
 *
 * Plain GET form: filters survive a refresh, the URL is shareable, and it all
 * works without JS (the selects just auto-submit when JS is on).
 */
$fbBase = rtrim(parse_url(cfg('app.base_url', ''), PHP_URL_PATH) ?? '', '/');
$fbActive = \App\Support\Filter::active($fbFilters);
$fbId = 'fb-' . preg_replace('/[^a-z]/', '', $fbAction);
?>
<form class="filterbar" id="<?= e($fbId) ?>" method="get" action="<?= e($fbBase . $fbAction) ?>">
  <div class="fb-search">
    <input type="search" name="q" value="<?= e($fbFilters['q'] ?? '') ?>"
           placeholder="<?= e($fbPlaceholder) ?>" autocomplete="off" aria-label="Search">
  </div>
  <?php foreach ($fbFields as $f): if ($f['type'] !== 'select') continue; $val = (string)($fbFilters[$f['name']] ?? ''); ?>
    <select name="<?= e($f['name']) ?>" aria-label="<?= e($f['label']) ?>" class="fb-select<?= $val === '' ? ' is-placeholder' : '' ?>">
      <option value=""><?= e($f['all'] ?? ('All ' . $f['label'])) ?></option>
      <?php foreach ($f['options'] as $v => $lbl): ?>
        <option value="<?= e((string)$v) ?>" <?= $val === (string)$v ? 'selected' : '' ?>><?= e($lbl) ?></option>
      <?php endforeach; ?>
    </select>
  <?php endforeach; ?>
  <?php $fbDates = array_values(array_filter($fbFields, fn($f) => $f['type'] === 'date')); ?>
  <?php if ($fbDates): ?>
    <span class="fb-daterange"><?php foreach ($fbDates as $f): $val = (string)($fbFilters[$f['name']] ?? ''); ?>
      <label class="fb-date">
        <span><?= e($f['label']) ?></span>
        <input type="date" name="<?= e($f['name']) ?>" value="<?= e($val) ?>"
               class="<?= $val === '' ? 'is-placeholder' : '' ?>">
      </label>
    <?php endforeach; ?></span>
  <?php endif; ?>
  <button class="btn sm secondary fb-go">Search</button>
  <?php if ($fbActive): ?>
    <a class="btn sm ghost" href="<?= e($fbBase . $fbAction) ?>">Clear</a>
  <?php endif; ?>
  <span class="fb-count muted small">
    <?php if ($fbActive): ?>
      <strong><?= (int)$fbShown ?></strong> of <?= (int)$fbTotal ?> <?= e($fbNoun) ?><?= $fbTotal === 1 ? '' : 's' ?>
    <?php else: ?>
      <?= (int)$fbTotal ?> <?= e($fbNoun) ?><?= $fbTotal === 1 ? '' : 's' ?>
    <?php endif; ?>
  </span>
</form>
<script>
// Changing a dropdown or date applies straight away; typing still needs Enter
// or the Search button, so the page doesn't reload on every keystroke.
(function(){
  const form = document.getElementById(<?= json_encode($fbId) ?>);
  form.querySelectorAll('select, input[type=date]').forEach(el => {
    el.addEventListener('change', () => form.submit());
  });
})();
</script>
