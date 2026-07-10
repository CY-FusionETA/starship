<?php /** @var ?string $error */
use App\Csrf;
$base = rtrim(parse_url(cfg('app.base_url', ''), PHP_URL_PATH) ?? '', '/'); ?>
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
  </form>
</div>
</body>
</html>
