<?php
/**
 * step1.php — Paso 1: subir el archivo chardump.lua
 */
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/language.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/dbfunctions.php';

$user = new User();
if (!$user->isLoggedIn()) {
    header('Location: ../index.php');
    exit;
}

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ── Verificar token CSRF ──────────────────────────────────
    if (!Token::check(Input::get('token'))) {
        $error = t('token_error');
    }
    // ── Verificar blacklist ──────────────────────────────────
    elseif (isAccountBlacklisted($user->id())) {
        $error = t('blacklisted');
    }
    // ── Validar realm seleccionado ───────────────────────────
    else {
        $realmId = (int) Input::get('realm');
        if (!isset(REALMS[$realmId])) {
            $error = t('invalid_realm');
        }
        // ── Validar archivo subido ───────────────────────────
        elseif (empty($_FILES['dump_file']['tmp_name']) || $_FILES['dump_file']['error'] !== UPLOAD_ERR_OK) {
            $error = t('invalid_file');
        } else {
            $file      = $_FILES['dump_file'];
            $maxSize   = 5 * 1024 * 1024; // 5 MB máximo

            // Verificar nombre y tamaño del archivo
            if (!str_ends_with(strtolower($file['name']), '.lua')) {
                $error = t('invalid_file');
            } elseif ($file['size'] > $maxSize) {
                $error = t('file_too_large');
            } else {
                $rawContent = file_get_contents($file['tmp_name']);

                // Detectar formato SavedVariables de WoW (addon chardump v2)
                // El archivo .lua tiene: ChardumpDB = { ["last"] = { ["data"] = "BASE64..." } }
                $encryptedData = $rawContent;
                if (str_contains($rawContent, 'ChardumpDB')) {
                    // Extraer el campo ["data"] del SavedVariables
                    if (preg_match('/\["data"\]\s*=\s*"([^"]+)"/', $rawContent, $m)) {
                        $encryptedData = $m[1];
                    }
                }

                $dump = decryptDump($encryptedData);
                if (empty($dump)) {
                    $dump = $rawContent; // Último fallback: contenido en bruto
                }

                // ── Validar estructura del dump ──────────────
                $charName = validateDump($dump);
                if ($charName === false) {
                    $error = t('invalid_dump');
                } else {
                    $charLevel = extractLevelFromDump($dump);

                    // Verificar nivel si el dump lo incluye
                    if ($charLevel > 0 && !checkLevel($charLevel)) {
                        $error = t('level_fail');
                    } else {
                        // Aplicar conversiones y filtros de items
                        applyItemConversions($dump);
                        removeBlockedItems($dump);

                        // Guardar en sesión para paso 2
                        $_SESSION['transfer_dump']    = $dump;
                        $_SESSION['transfer_realm']   = $realmId;
                        $_SESSION['transfer_charname']= $charName;

                        header('Location: step2.php');
                        exit;
                    }
                }
            }
        }
    }
}

$realmList = getRealmList();
$token     = Token::generate();
?>
<!DOCTYPE html>
<html lang="<?= currentLang() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('transfer_step1') ?> — <?= t('site_title') ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
<nav class="navbar">
    <div class="nav-brand">⚔ <?= t('app_name') ?></div>
    <div class="nav-right">
        <?php renderLangSwitcher(); ?>
        <span class="nav-user"><?= htmlspecialchars($user->name()) ?></span>
        <a href="../dashboard.php" class="btn btn-sm btn-outline"><?= t('btn_back') ?></a>
        <a href="../logout.php" class="btn btn-sm btn-outline"><?= t('logout') ?></a>
    </div>
</nav>

<main class="container">
    <div class="card card-narrow">
        <h2>📂 <?= t('transfer_step1') ?></h2>

        <!-- Instrucciones -->
        <div class="instructions-box">
            <h3>💡 <?= t('instructions_title') ?></h3>
            <div><?= t('instructions') ?></div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="token" value="<?= $token ?>">

            <div class="form-group">
                <label for="realm"><?= t('realm_select') ?></label>
                <select name="realm" id="realm" required>
                    <option value=""><?= t('select_realm') ?></option>
                    <?php foreach ($realmList as $r): ?>
                        <option value="<?= (int) $r['id'] ?>"
                            <?= isset($_POST['realm']) && (int) $_POST['realm'] === $r['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($r['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="dump_file"><?= t('upload_label') ?></label>
                <div class="file-drop" id="fileDrop">
                    <input type="file" name="dump_file" id="dump_file"
                           accept=".lua" required style="display:none">
                    <label for="dump_file" class="file-drop-label">
                        📄 <span id="fileName"><?= t('file_drop_prompt') ?></span>
                    </label>
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-full">
                ⬆ <?= t('btn_upload') ?>
            </button>
        </form>
    </div>
</main>
<script>window.MIGRADOR_I18N = <?= json_encode(['deny_reason_required' => t('js_deny_reason_required'), 'lua_only' => t('js_lua_only')], JSON_UNESCAPED_UNICODE) ?>;</script>
<script src="../assets/js/app.js"></script>
</body>
</html>



