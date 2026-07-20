<?php /** @var ?array $token @var bool $connected @var bool $configured @var bool $enabled
 * @var string $client_id @var bool $has_secret @var string $redirect_uri @var string $scopes
 * @var bool $wz_configured @var bool $wz_enabled @var string $wz_api_key @var string $wz_channel
 * @var string $wz_number @var string $wz_webhook @var array $senders
 * @var ?string $autosync_last @var ?string $notice @var ?string $error */
use App\Csrf;
$base = rtrim(parse_url(cfg('app.base_url', ''), PHP_URL_PATH) ?? '', '/'); ?>

<div class="toolbar"><h1 style="margin:0">Settings</h1></div>

<?php if ($notice): ?><div class="notice"><?= e($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert"><?= e($error) ?></div><?php endif; ?>

<?php // Tabs, not columns: side by side these three cards were wider than the
      // viewport, so WhatsApp sat off the right edge with no way to reach it. ?>
<div class="settings-tabs" role="tablist">
  <button type="button" class="stab" role="tab" data-pane="xero" aria-selected="true">
    Xero <span class="conn-dot <?= $connected ? 'on' : 'off' ?>"></span>
  </button>
  <button type="button" class="stab" role="tab" data-pane="wazzup" aria-selected="false">
    WhatsApp <span class="conn-dot <?= ($wz_enabled && $wz_configured) ? 'on' : 'off' ?>"></span>
  </button>
  <button type="button" class="stab" role="tab" data-pane="users" aria-selected="false">Users</button>
</div>

<div class="settings-panes">
<section class="tab-pane" id="pane-xero" role="tabpanel">
<div class="card xero-card">
  <div class="xero-head">
    <div class="xero-title">
      <span class="xero-logo">X</span>
      <div>
        <h2 style="margin:0">Xero integration</h2>
        <span class="muted small">Auto-create a Purchase Order in Xero whenever one is raised in Starship.</span>
      </div>
    </div>
    <?php if ($connected): ?>
      <span class="conn-pill on">● Connected<?= !empty($token['tenant_name']) ? ' · ' . e($token['tenant_name']) : '' ?></span>
    <?php else: ?>
      <span class="conn-pill off">● Not connected</span>
    <?php endif; ?>
  </div>

  <?php if ($connected): ?>
    <div class="xero-connected">
      <div>
        <div class="muted small">Organisation</div>
        <strong><?= e($token['tenant_name'] ?: 'Xero organisation') ?></strong>
        <?php if (!empty($token['expires_at'])): ?><div class="muted small">Token auto-refreshes · last updated <?= e($token['updated_at'] ?? '') ?></div><?php endif; ?>
        <?php $as = $autosync_last ? json_decode($autosync_last, true) : null; if ($as): ?>
          <?php $sum = $as['summary'] ?? []; $bits = [];
            foreach (['contacts' => 'suppliers', 'projects' => 'projects', 'items' => 'products'] as $k => $lbl) {
              if (isset($sum[$k]['error'])) { $bits[] = $lbl . ': skipped'; }
              elseif (isset($sum[$k])) { $bits[] = $lbl . ': +' . (int)$sum[$k]['created'] . ' / ~' . (int)$sum[$k]['updated']; }
            } ?>
          <div class="muted small">Master data pulls from Xero every 15 min · last sync <?= e($as['at'] ?? '') ?><?= $bits ? ' (' . e(implode(', ', $bits)) . ')' : '' ?></div>
        <?php else: ?>
          <div class="muted small">Master data (suppliers, projects, products) pulls from Xero automatically every 15 min.</div>
        <?php endif; ?>
      </div>
      <div class="xero-conn-actions">
        <a class="btn secondary" href="<?= e($base) ?>/settings/xero/connect">Reconnect</a>
        <form method="post" action="<?= e($base) ?>/settings/xero/disconnect" onsubmit="return confirm('Disconnect Starship from Xero? New POs will stop syncing until reconnected.')">
          <?= Csrf::field() ?><button class="btn ghost-danger">Disconnect</button>
        </form>
      </div>
    </div>
  <?php endif; ?>

  <form method="post" action="<?= e($base) ?>/settings/save" class="xero-form">
    <?= Csrf::field() ?>
    <label class="switch-row">
      <input type="checkbox" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
      <span>Enable Xero auto-sync</span>
    </label>

    <div class="row">
      <div><label>Client ID</label><input name="client_id" value="<?= e($client_id) ?>" placeholder="from your Xero app" autocomplete="off"></div>
      <div><label>Client Secret</label><input name="client_secret" type="password" placeholder="<?= $has_secret ? '•••••• (leave blank to keep)' : 'from your Xero app' ?>" autocomplete="off"></div>
    </div>

    <label>Redirect URI <span class="muted small">— add this exact URL to your Xero app’s “Redirect URIs”</span></label>
    <div class="copy-field">
      <input name="redirect_uri" id="redir" value="<?= e($redirect_uri) ?>">
      <button type="button" class="btn sm secondary" onclick="copyRedir()">Copy</button>
    </div>

    <label>Supplier contact group <span class="muted small">— optional. Contacts in this Xero contact group are imported as suppliers even before their first PO. Clear to import only Xero-flagged suppliers.</span></label>
    <input name="supplier_group" value="<?= e($supplier_group) ?>" placeholder="Suppliers">

    <label>Scopes</label>
    <input name="scopes" value="<?= e($scopes) ?>">

    <div class="xero-actions">
      <button class="btn secondary">Save</button>
      <?php if ($configured): ?>
        <a class="btn" href="<?= e($base) ?>/settings/xero/connect"><?= $connected ? 'Reconnect to Xero' : 'Connect to Xero →' ?></a>
      <?php else: ?>
        <span class="muted small">Enter Client ID + Secret and Save, then Connect.</span>
      <?php endif; ?>
    </div>
  </form>

  <details class="xero-help">
    <summary>How to get your Xero credentials</summary>
    <ol class="muted small">
      <li>Go to <strong>developer.xero.com → My Apps → New app</strong> (Web app).</li>
      <li>Set the <strong>Redirect URI</strong> to the exact URL shown above.</li>
      <li>Copy the <strong>Client ID</strong> and generate a <strong>Client Secret</strong>; paste both here and Save.</li>
      <li>Click <strong>Connect to Xero</strong> and choose the organisation to link.</li>
    </ol>
  </details>
</div>
</section>

<section class="tab-pane" id="pane-wazzup" role="tabpanel" hidden>
<div class="card xero-card" id="wazzup">
  <div class="xero-head">
    <div class="xero-title">
      <span class="xero-logo wa">✆</span>
      <div>
        <h2 style="margin:0">WhatsApp hotline (Wazzup)</h2>
        <span class="muted small">Approved numbers WhatsApp a photo of a DO/invoice → auto-OCR into Starship → reply with the details.</span>
      </div>
    </div>
    <?php if ($wz_enabled && $wz_configured): ?>
      <span class="conn-pill on">● Active<?= $wz_number ? ' · ' . e($wz_number) : '' ?></span>
    <?php elseif ($wz_configured): ?>
      <span class="conn-pill off">● Configured (disabled)</span>
    <?php else: ?>
      <span class="conn-pill off">● Not configured</span>
    <?php endif; ?>
  </div>

  <form method="post" action="<?= e($base) ?>/settings/wazzup/save" class="xero-form">
    <?= Csrf::field() ?>
    <label class="switch-row">
      <input type="checkbox" name="enabled" value="1" <?= $wz_enabled ? 'checked' : '' ?>>
      <span>Enable WhatsApp intake</span>
    </label>
    <div class="row">
      <div><label>Wazzup API key</label><input name="api_key" type="password" placeholder="<?= $wz_api_key ? '•••••• (leave blank to keep)' : 'Wazzup API key' ?>" autocomplete="off"></div>
      <div><label>Channel ID</label><input name="channel_id" value="<?= e($wz_channel) ?>" autocomplete="off"></div>
    </div>
    <div class="row">
      <div><label>WhatsApp number (the Starship line)</label><input name="number" value="<?= e($wz_number) ?>" placeholder="60102300975"></div>
      <div></div>
    </div>
    <div class="xero-actions"><button class="btn secondary">Save</button></div>
  </form>

  <label style="margin-top:1rem">Webhook URL <span class="muted small">— register this in Wazzup so it forwards incoming messages here</span></label>
  <div class="copy-field">
    <input id="hook" value="<?= e($wz_webhook) ?>" readonly>
    <button type="button" class="btn sm secondary" onclick="copyEl('hook',this)">Copy</button>
  </div>
  <div class="xero-actions">
    <form method="post" action="<?= e($base) ?>/settings/wazzup/register-webhook">
      <?= Csrf::field() ?><button class="btn" <?= $wz_configured ? '' : 'disabled' ?>>Register webhook with Wazzup</button>
    </form>
    <span class="muted small">Or paste the URL into Wazzup → Integrations → Webhooks.</span>
  </div>

  <div class="senders">
    <h3 style="margin:1.4rem 0 .5rem">Approved sender numbers</h3>
    <p class="muted small" style="margin-top:0">Only these WhatsApp numbers may submit documents. Others are ignored.</p>
    <table>
      <thead><tr><th>Name</th><th>Number</th><th>Last used</th><th></th></tr></thead>
      <tbody>
      <?php if (!$senders): ?>
        <tr><td colspan="4" class="muted">No numbers yet — add one below.</td></tr>
      <?php else: foreach ($senders as $s): ?>
        <tr>
          <td><?= e($s['name'] ?: '—') ?></td>
          <td><span class="badge code">+<?= e($s['phone_e164']) ?></span> <?= $s['is_active'] ? '' : '<span class="badge muted">disabled</span>' ?></td>
          <td class="muted small"><?= e($s['last_seen_at'] ?: 'never') ?></td>
          <td style="text-align:right">
            <form method="post" action="<?= e($base) ?>/settings/senders/<?= (int)$s['id'] ?>/delete" onsubmit="return confirm('Remove +<?= e($s['phone_e164']) ?>?')" style="display:inline">
              <?= Csrf::field() ?><button class="btn sm ghost-danger">Remove</button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
    <form method="post" action="<?= e($base) ?>/settings/senders/add" class="sender-add">
      <?= Csrf::field() ?>
      <input name="name" placeholder="Name (e.g. Ahmad – driver)">
      <input name="phone" placeholder="60123456789" required>
      <button class="btn">+ Add number</button>
    </form>
  </div>
</div>

</section>

<!-- ------------------------------- Users ------------------------------- -->
<section class="tab-pane" id="pane-users" role="tabpanel" hidden>
<div class="card" id="users">
  <h2 style="margin-top:0">Users &amp; project access</h2>
  <p class="muted small" style="margin-top:0">
    A user sees requisitions, purchase orders and delivery orders <strong>only for the projects you assign them</strong>.
    Superadmin and Finance see every project.
  </p>

  <table class="users-table">
    <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Projects</th><th>Last sign-in</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): $isSelf = (int)$u['id'] === (int)\App\Auth::id(); ?>
      <tr class="<?= $u['is_active'] ? '' : 'is-off' ?>">
        <td><strong><?= e($u['name']) ?></strong><?= $isSelf ? ' <span class="badge muted">you</span>' : '' ?>
          <?= $u['is_active'] ? '' : ' <span class="badge muted">deactivated</span>' ?></td>
        <td class="muted small"><?= e($u['email']) ?></td>
        <td><span class="badge <?= $u['role'] === 'admin' ? 'brand' : 'muted' ?>"><?= e(\App\Perm::label($u['role'])) ?></span></td>
        <td class="small">
          <?php if (\App\Perm::seesAllProjects($u['role'])): ?>
            <span class="muted">all projects</span>
          <?php elseif (!$u['projects']): ?>
            <span class="badge warn">none — sees nothing</span>
          <?php else: ?>
            <?php foreach ($u['projects'] as $pr): ?><span class="badge brand"><?= e($pr['project_code']) ?></span> <?php endforeach; ?>
          <?php endif; ?>
        </td>
        <td class="muted small"><?= e($u['last_login_at'] ?: 'never') ?></td>
        <td style="text-align:right;white-space:nowrap">
          <a class="btn sm secondary" href="<?= e($base) ?>/settings?user=<?= (int)$u['id'] ?>#users">Edit</a>
          <?php if (!$isSelf): ?>
            <form method="post" action="<?= e($base) ?>/settings/users/<?= (int)$u['id'] ?>/active"
                  onsubmit="return confirm('<?= $u['is_active'] ? 'Deactivate' : 'Reactivate' ?> <?= e($u['name']) ?>?')" style="display:inline">
              <?= Csrf::field() ?>
              <input type="hidden" name="active" value="<?= $u['is_active'] ? '' : '1' ?>">
              <button class="btn sm <?= $u['is_active'] ? 'ghost-danger' : 'ghost' ?>"><?= $u['is_active'] ? 'Deactivate' : 'Reactivate' ?></button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <?php
    $ed = $editUser ?? null;
    $formAction = $ed ? "/settings/users/{$ed['id']}/save" : '/settings/users/add';
  ?>
  <h3 style="margin:1.4rem 0 .5rem"><?= $ed ? 'Edit ' . e($ed['name']) : 'Add a user' ?></h3>
  <form method="post" action="<?= e($base . $formAction) ?>" class="user-form">
    <?= Csrf::field() ?>
    <div class="row">
      <div><label>Name *</label><input name="name" required value="<?= e($ed['name'] ?? '') ?>" placeholder="Siti Rahman"></div>
      <div><label>Email *</label>
        <?php if ($ed): ?>
          <input value="<?= e($ed['email']) ?>" disabled title="Email can't be changed — deactivate and re-create instead.">
        <?php else: ?>
          <input name="email" type="email" required placeholder="siti@fusioneta.com">
        <?php endif; ?>
      </div>
      <div><label>Password <?= $ed ? '<span class="muted small">(blank = unchanged)</span>' : '*' ?></label>
        <input name="password" type="text" autocomplete="new-password" <?= $ed ? '' : 'required' ?>
               placeholder="<?= $ed ? 'leave blank to keep' : 'min 8 characters' ?>"></div>
    </div>
    <label>Role</label>
    <div class="role-grid">
      <?php foreach (\App\Perm::ROLES as $roleKey): $checked = ($ed['role'] ?? 'requester') === $roleKey; ?>
        <label class="role-opt<?= $checked ? ' on' : '' ?>">
          <input type="radio" name="role" value="<?= e($roleKey) ?>" <?= $checked ? 'checked' : '' ?>
                 onchange="syncRole()" data-all="<?= \App\Perm::seesAllProjects($roleKey) ? '1' : '' ?>">
          <span class="ro-name"><?= e(\App\Perm::LABELS[$roleKey]) ?></span>
          <span class="ro-desc"><?= e(\App\Perm::DESCRIPTIONS[$roleKey]) ?></span>
        </label>
      <?php endforeach; ?>
    </div>

    <div id="projPick">
      <label style="margin-top:1rem">Projects this user can see</label>
      <p class="muted small" style="margin:0 0 .4rem">Tick every project they work on — most people are on more than one.</p>
      <div class="proj-grid">
        <?php if (!$allProjects): ?>
          <p class="muted small">No projects yet — create one under Projects first.</p>
        <?php else: foreach ($allProjects as $pr): ?>
          <label class="proj-opt">
            <input type="checkbox" name="projects[]" value="<?= (int)$pr['id'] ?>"
                   <?= in_array((int)$pr['id'], $editProjects ?? [], true) ? 'checked' : '' ?>>
            <span><span class="badge brand"><?= e($pr['project_code']) ?></span> <?= e($pr['name']) ?></span>
          </label>
        <?php endforeach; endif; ?>
      </div>
    </div>
    <p class="muted small" id="projAllNote" hidden>This role sees every project, so there's nothing to assign.</p>

    <div style="margin-top:1rem;display:flex;gap:.6rem">
      <button class="btn"><?= $ed ? 'Save changes' : '+ Create user' ?></button>
      <?php if ($ed): ?><a class="btn secondary" href="<?= e($base) ?>/settings#users">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>
</section>
</div>

<script>
// Tab switching. Driven by the hash so the existing post-save redirects
// (/settings?ok=...#users, #wazzup) still land on the right tab.
function showTab(name, push){
  const panes = ['xero','wazzup','users'];
  if (!panes.includes(name)) name = 'xero';
  panes.forEach(p => document.getElementById('pane-' + p).hidden = (p !== name));
  document.querySelectorAll('.stab').forEach(b => {
    const on = b.dataset.pane === name;
    b.classList.toggle('on', on);
    b.setAttribute('aria-selected', on ? 'true' : 'false');
  });
  if (push) history.replaceState(null, '', '#' + name);
}
document.querySelectorAll('.stab').forEach(b =>
  b.addEventListener('click', () => showTab(b.dataset.pane, true)));
// #users and #wazzup are the anchors the server redirects to; anything else = Xero.
showTab((location.hash || '').replace('#',''), false);
window.addEventListener('hashchange', () => showTab((location.hash||'').replace('#',''), false));

function copyEl(id,btn){
  const el = document.getElementById(id); el.select(); el.setSelectionRange(0,99999);
  navigator.clipboard?.writeText(el.value); const t=btn.textContent; btn.textContent='Copied ✓';
  setTimeout(()=>btn.textContent=t,1200);
}
function copyRedir(){ copyEl('redir', event.target); }

// Superadmin/Finance see every project, so hide the picker for those roles
// rather than let someone tick boxes that would be ignored on save.
function syncRole(){
  const on = document.querySelector('.role-opt input:checked');
  const seesAll = on && on.dataset.all === '1';
  document.getElementById('projPick').hidden = !!seesAll;
  document.getElementById('projAllNote').hidden = !seesAll;
  document.querySelectorAll('.role-opt').forEach(l => l.classList.toggle('on', l.contains(on)));
}
syncRole();
</script>
