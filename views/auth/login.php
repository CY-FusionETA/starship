<?php /** @var ?string $error */
use App\Csrf;
$base = rtrim(parse_url(cfg('app.base_url', ''), PHP_URL_PATH) ?? '', '/');
// One-click demo logins. These credentials are public to anyone who opens the
// page — keep the accounts on demo data only.
$demoUsers = [
    // Labels track the account's real role — see App\Perm::LABELS.
    ['email' => 'simon@fusioneta.com',  'name' => 'Simon',  'role' => 'Superadmin',  'initials' => 'SC'],
    ['email' => 'carmen@globe.com',     'name' => 'Carmen', 'role' => 'Superadmin',  'initials' => 'CA'],
    ['email' => 'procurement@fusioneta.com', 'name' => 'Procurement', 'role' => 'Procurement', 'initials' => 'PR'],
    ['email' => 'mr@fusioneta.com',     'name' => 'MR',     'role' => 'Requester',   'initials' => 'MR'],
];
$demoPassword = 'demo123'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign in | Starship</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e($base) ?>/public/css/app.css">
</head>
<body>
<div class="login-wrap">
  <form class="login-card" method="post" action="<?= e($base) ?>/login">
    <div class="brand">Starship</div>
    <p class="muted small">Material Requisition &amp; Purchase Order system</p>
    <?php if ($error): ?><div class="alert"><?= e($error) ?></div><?php endif; ?>
    <?= Csrf::field() ?>
    <label for="email">Email</label>
    <input id="email" name="email" type="email" autocomplete="username" required autofocus>
    <label for="password">Password</label>
    <input id="password" name="password" type="password" autocomplete="current-password" required>
    <div style="margin-top:1.2rem"><button class="btn" style="width:100%">Sign in</button></div>

    <div class="demo-sep"><span>or sign in as</span></div>
    <div class="demo-grid">
      <?php foreach ($demoUsers as $u): ?>
        <button type="button" class="demo-btn"
                onclick="demoLogin('<?= e($u['email']) ?>')"
                title="<?= e($u['email']) ?>">
          <span class="demo-av"><?= e($u['initials']) ?></span>
          <span class="demo-who">
            <span class="demo-name"><?= e($u['name']) ?></span>
            <span class="demo-role"><?= e($u['role']) ?></span>
          </span>
        </button>
      <?php endforeach; ?>
    </div>
  </form>
</div>
<script>
// Fill the real fields and submit the real form, so the demo path goes through
// exactly the same POST /login (and CSRF check) as typing the details by hand.
const DEMO_PASSWORD = <?= json_encode($demoPassword) ?>;
function demoLogin(email){
  document.getElementById('email').value = email;
  document.getElementById('password').value = DEMO_PASSWORD;
  document.querySelector('.login-card').submit();
}
</script>
</body>
</html>
