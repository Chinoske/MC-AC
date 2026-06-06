<?php
/**
 * dashboard.php — Panel principal: jugador y GM
 */
require_once __DIR__ . '/config.php';
require_once TRANSFER_PATH . '/language.php';
require_once TRANSFER_PATH . '/dbfunctions.php';

$user = new User();
if (!$user->isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$isGM      = $user->isGM();
$accountId = $user->id();
$transfers = getAccountTransfers($accountId, $isGM);
$realmList = getRealmList();
$flash     = Session::getFlash('message');
$flashErr  = Session::getFlash('error');

// ── Helpers de estado ──────────────────────────────────────────
$statusLabels  = ['pending','approved','denied','cancelled','resent'];
$statusIcons   = ['⏳','✅','❌','🚫','📨'];
?>
<!DOCTYPE html>
<html lang="<?= DEFAULT_LANG ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $isGM ? t('gm_panel') : t('player_panel') ?> — <?= t('site_title') ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<!-- ── Navbar ──────────────────────────────────────────────── -->
<nav class="navbar">
    <div class="nav-brand">⚔ Migrador AC</div>
    <div class="nav-right">
        <span class="nav-user">
            <?= t('welcome') ?>, <b><?= htmlspecialchars($user->name()) ?></b>
            <?php if ($isGM): ?>
                <span class="badge badge-gm">GM Lvl <?= $user->gmLevel() ?></span>
            <?php endif; ?>
        </span>
        <a href="logout.php" class="btn btn-sm btn-outline"><?= t('logout') ?></a>
    </div>
</nav>

<main class="container">

    <!-- ── Alertas flash ──────────────────────────────────── -->
    <?php if ($flash): ?>
        <div class="alert alert-success"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>
    <?php if ($flashErr): ?>
        <div class="alert alert-error"><?= htmlspecialchars($flashErr) ?></div>
    <?php endif; ?>

    <!-- ── Panel de transferencia (todos los usuarios) ─────── -->
    <section class="card">
        <div class="card-header">
            <h2>📦 <?= t('transfer_title') ?></h2>
        </div>
        <div class="card-body instructions-box">
            <h3>💡 <?= t('instructions_title') ?></h3>
            <div class="instructions-text"><?= t('instructions') ?></div>
            <a href="transfer/step1.php" class="btn btn-primary mt-2">
                📂 Iniciar transferencia
            </a>
        </div>
    </section>

    <!-- ── Tabla de transferencias ───────────────────────── -->
    <section class="card">
        <div class="card-header">
            <h2><?= $isGM ? '🛡 ' . t('gm_panel') : '📋 ' . t('player_panel') ?></h2>
            <?php if ($isGM): ?>
            <button class="btn btn-sm btn-outline" onclick="location.reload()">🔄 Actualizar</button>
            <?php endif; ?>
        </div>
        <div class="card-body">

        <?php if (empty($transfers)): ?>
            <p class="text-muted text-center">
                <?= t('no_transfers') ?>
            </p>
        <?php else: ?>
        <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Personaje</th>
                    <th>Realm</th>
                    <?php if ($isGM): ?>
                    <th>Cuenta</th>
                    <?php endif; ?>
                    <th>Estado</th>
                    <th>Fecha</th>
                    <?php if ($isGM): ?>
                    <th>Motivo</th>
                    <?php endif; ?>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($transfers as $tr): ?>
                <?php
                $st  = (int) $tr->status;
                $stLabel = t('transfer_status_' . $st);
                $stClass = $statusLabels[$st] ?? 'unknown';
                $stIcon  = $statusIcons[$st]  ?? '?';
                ?>
                <tr>
                    <td class="text-muted">#<?= (int) $tr->id ?></td>
                    <td><b><?= htmlspecialchars($tr->cName ?? '') ?></b></td>
                    <td><?= htmlspecialchars(getRealmName((int) ($tr->cRealmID ?? 1))) ?></td>
                    <?php if ($isGM): ?>
                    <td><code><?= (int) $tr->cAccount ?></code></td>
                    <?php endif; ?>
                    <td>
                        <span class="status status-<?= $stClass ?>">
                            <?= $stIcon ?> <?= $stLabel ?>
                        </span>
                    </td>
                    <td class="text-muted">
                        <small><?= htmlspecialchars($tr->created_at ?? $tr->date ?? '') ?></small>
                    </td>
                    <?php if ($isGM): ?>
                    <td>
                        <small class="text-muted">
                            <?= htmlspecialchars($tr->reason ?? '—') ?>
                        </small>
                    </td>
                    <?php endif; ?>

                    <!-- ── Acciones ──────────────────────── -->
                    <td class="actions">
                    <?php if ($isGM && $st === 0): ?>
                        <!-- GM: Aprobar — importa el personaje ahora -->
                        <form method="POST" action="transfer/b_approve.php"
                              class="inline-form"
                              onsubmit="return confirm('<?= t('confirm_approve') ?>')">
                            <input type="hidden" name="token"  value="<?= Token::generate() ?>">
                            <input type="hidden" name="id"     value="<?= (int) $tr->id ?>">
                            <input type="hidden" name="realm"  value="<?= (int) $tr->cRealmID ?>">
                            <button class="btn btn-xs btn-success">✅ <?= t('btn_approve') ?></button>
                        </form>

                        <!-- GM: Denegar (con motivo) -->
                        <form method="POST" action="transfer/b_deny.php"
                              class="inline-form deny-form"
                              data-id="<?= (int) $tr->id ?>"
                              data-realm="<?= (int) $tr->cRealmID ?>">
                            <input type="hidden" name="token"  value="<?= Token::generate() ?>">
                            <input type="hidden" name="id"     value="<?= (int) $tr->id ?>">
                            <input type="hidden" name="realm"  value="<?= (int) $tr->cRealmID ?>">
                            <input type="hidden" name="reason" class="deny-reason-input">
                            <button type="button" class="btn btn-xs btn-danger deny-btn">
                                ❌ <?= t('btn_deny') ?>
                            </button>
                        </form>

                        <?php if ((int) $tr->cGUID > 0): ?>
                        <!-- GM: Reenviar items (solo si ya fue importado) -->
                        <form method="POST" action="transfer/b_resend.php"
                              class="inline-form"
                              onsubmit="return confirm('¿Reenviar items?')">
                            <input type="hidden" name="token"  value="<?= Token::generate() ?>">
                            <input type="hidden" name="id"     value="<?= (int) $tr->id ?>">
                            <input type="hidden" name="realm"  value="<?= (int) $tr->cRealmID ?>">
                            <input type="hidden" name="guid"   value="<?= (int) $tr->cGUID ?>">
                            <button class="btn btn-xs btn-info">📨 <?= t('btn_resend') ?></button>
                        </form>
                        <?php endif; ?>

                    <?php elseif (!$isGM && $st === 0): ?>
                        <!-- Jugador: Cancelar -->
                        <form method="POST" action="transfer/b_cancel.php"
                              class="inline-form"
                              onsubmit="return confirm('<?= t('confirm_cancel') ?>')">
                            <input type="hidden" name="token"  value="<?= Token::generate() ?>">
                            <input type="hidden" name="id"     value="<?= (int) $tr->id ?>">
                            <input type="hidden" name="realm"  value="<?= (int) $tr->cRealmID ?>">
                            <button class="btn btn-xs btn-warning">🚫 <?= t('btn_cancel') ?></button>
                        </form>
                    <?php else: ?>
                        <span class="text-muted">—</span>
                    <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
        </div>
    </section>

</main>

<!-- ── Modal denegar (GM) ──────────────────────────────────── -->
<div id="denyModal" class="modal" style="display:none">
    <div class="modal-box">
        <h3>❌ <?= t('btn_deny') ?></h3>
        <p><?= t('deny_reason') ?></p>
        <textarea id="denyReasonText" rows="3" maxlength="255"
                  placeholder="Escribe el motivo aquí…"></textarea>
        <div class="modal-actions">
            <button class="btn btn-danger" id="denyConfirmBtn"><?= t('btn_confirm') ?></button>
            <button class="btn btn-outline" onclick="closeDenyModal()">Cancelar</button>
        </div>
    </div>
</div>

<script src="assets/js/app.js"></script>
</body>
</html>
