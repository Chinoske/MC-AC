<?php
require_once __DIR__ . '/config.php';
require_once TRANSFER_PATH . '/language.php';

$user = new User();
if ($user->isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && Input::exists()) {
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
            if ($u->login(Input::get('username'), Input::get('password'))) {
                Token::invalidate();
                $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
                      . '://' . $_SERVER['HTTP_HOST']
                      . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
                $dest = $base . '/dashboard.php';
                header("Location: $dest");
                echo '<!DOCTYPE html><html><head>';
                echo "<meta http-equiv=\"refresh\" content=\"0;url={$dest}\">";
                echo '</head><body>';
                echo "<script>window.location.href=\"{$dest}\";</script>";
                echo "<p>Redirigiendo... <a href=\"{$dest}\">Clic aquí si no redirige</a></p>";
                echo '</body></html>';
                exit;
            } else {
                $error = t('login_error');
            }
        }
    }
}

$token = Token::generate();
?>
<!DOCTYPE html>
<html lang="<?= DEFAULT_LANG ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('site_title') ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="login-body">
<div class="login-box">
    <div class="login-logo">
        <div class="logo-icon">⚔</div>
        <h1>Migrador AC</h1>
        <p class="subtitle">AzerothCore WotLK 3.3.5a</p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

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
    <p class="login-footer">Usa las mismas credenciales del juego.</p>
</div>
</body>
</html>
