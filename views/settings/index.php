<?php /** @var ?array $token @var bool $connected @var bool $configured @var bool $enabled
 * @var string $client_id @var bool $has_secret @var string $redirect_uri @var string $scopes
 * @var bool $wz_configured @var bool $wz_enabled @var string $wz_api_key @var string $wz_channel
 * @var string $wz_number @var string $wz_webhook @var array $senders
 * @var ?string $notice @var ?string $error */
use App\Csrf;
$base = rtrim(parse_url(cfg('app.base_url', ''), PHP_URL_PATH) ?? '', '/'); ?>

<div class="toolbar"><h1 style="margin:0">Settings</h1></div>

<?php if ($notice): ?><div class="notice"><?= e($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert"><?= e($error) ?></div><?php endif; ?>

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

<script>
function copyEl(id,btn){
  const el = document.getElementById(id); el.select(); el.setSelectionRange(0,99999);
  navigator.clipboard?.writeText(el.value); const t=btn.textContent; btn.textContent='Copied ✓';
  setTimeout(()=>btn.textContent=t,1200);
}
function copyRedir(){ copyEl('redir', event.target); }
</script>
