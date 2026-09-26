<?php
require_once __DIR__ . '/config.php';
require_once TRANSFER_PATH . '/language.php';

$user = new User();
if ($user->isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$lockedMinutes = RateLimiter::isBlocked();
if ($lockedMinutes !== null) {
    $error = t('login_rate_limited') . " ({$lockedMinutes} min)";
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && Input::exists()) {
    if (!Token::check(Input::get('token'))) {
        $error = t('token_error');
    } else {
        $v = (new Validation())->check($_POST, [
            'username' => ['required' => true, 'min' => 2, 'max' => 32],
            'password' => ['required' => true, 'min' => 1],
        ]);
        if (!$v->passed()) {
            $error = $v->firstError();
        } else {
            $u = new User();
            // getRaw: sanear la contraseña la cambiaba (htmlspecialchars +
            // strip_tags), asi que cualquiera con & < > " ' o espacios al borde
            // no podia entrar aunque funcionara en el juego.
            if ($u->login(Input::getRaw('username'), Input::getRaw('password'))) {
                RateLimiter::clear();
                Token::invalidate();
                // Location relativo: el Host lo elige el cliente, y antes se
                // interpolaba sin escapar en un <script> y en un href.
                header('Location: dashboard.php', true, 303);
                exit;
            } else {
                RateLimiter::recordFailure();
                $error = t('login_error');
            }
        }
    }
}

$token = Token::generate();
?>
<!DOCTYPE html>
<html lang="<?= currentLang() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('site_title') ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="login-body">
<div class="login-box">
    <div class="login-lang-bar"><?php renderLangSwitcher(); ?></div>
    <div class="login-logo">
        <div class="logo-icon">⚔</div>
        <h1><?= t('app_name') ?></h1>
        <p class="subtitle">AzerothCore WotLK 3.3.5a</p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($lockedMinutes === null): ?>
    <form method="POST" action="index.php" autocomplete="on">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
        <div class="form-group">
            <label for="username"><?= t('login_user') ?></label>
            <input type="text" id="username" name="username"
                   autocomplete="username"
                   value="<?= isset($_POST['username']) ? htmlspecialchars($_POST['username']) : '' ?>"
                   required maxlength="32" autofocus>
        </div>
        <div class="form-group">
            <label for="password"><?= t('login_pass') ?></label>
            <input type="password" id="password" name="password"
                   autocomplete="current-password" required>
        </div>
        <button type="submit" class="btn btn-primary btn-full">
            🔐 <?= t('login_btn') ?>
        </button>
    </form>
    <?php endif; ?>
    <p class="login-footer"><?= t('login_footer') ?></p>
</div>
</body>
</html>




