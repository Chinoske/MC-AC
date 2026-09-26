<?php
/**
 * b_approve.php — GM: Aprobar una transferencia de personaje
 * Aquí se importa REALMENTE el personaje a la DB de personajes del realm.
 */
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/language.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/dbfunctions.php';

$user = new User();
if (!$user->isLoggedIn() || !$user->isGM()) {
    Session::flash('error', t('access_denied'));
    header('Location: ../dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../dashboard.php');
    exit;
}

if (!Token::check(Input::get('token'))) {
    Session::flash('error', t('token_error'));
    header('Location: ../dashboard.php');
    exit;
}

$transferId = (int) Input::get('id');
$realmId    = (int) Input::get('realm');

if ($transferId <= 0 || $realmId <= 0) {
    Session::flash('error', t('invalid_params'));
    header('Location: ../dashboard.php');
    exit;
}

// ── El worldserver del realm tiene que estar apagado ──────────
//
// El import reserva GUIDs con MAX(guid)+1, pero el core los reparte desde
// memoria: ObjectMgr::SetHighestGuids() se ejecuta una sola vez al arrancar.
// Con el servidor arriba nos quedamos con GUIDs que ya tiene reservados y el
// siguiente personaje creado en el juego, o cada item looteado, choca contra la
// PK de characters / item_instance. Con mail.id pasa lo mismo.
//
// defined(): config.php ya no viene en el repo, asi que una instalacion vieja
// puede no tener la opcion.
$allowOnline = defined('ALLOW_IMPORT_WHILE_ONLINE') && ALLOW_IMPORT_WHILE_ONLINE;
if (!$allowOnline && Soap::isOnline($realmId)) {
    Session::flash('error', sprintf(t('import_blocked_online'), getRealmName($realmId)));
    header('Location: ../dashboard.php');
    exit;
}

// La transferencia debe estar en estado pendiente (0)
if (getTransferStatus($transferId) !== 0) {
    Session::flash('error', t('transfer_not_pending'));
    header('Location: ../dashboard.php');
    exit;
}

// Obtener datos completos de la transferencia (incluye el dump)
$row = DB::auth()->row(
    'SELECT `cAccount`, `cName`, `cDump`, `cGUID` FROM `account_transfer` WHERE `id` = ? LIMIT 1',
    [$transferId]
);
if (!$row) {
    Session::flash('error', t('transfer_not_found'));
    header('Location: ../dashboard.php');
    exit;
}

$targetAccountId = (int) $row->cAccount;
$charName        = $row->cName;
$dumpData        = $row->cDump ?? '';
$existingGuid    = (int) $row->cGUID;

// Si ya fue importado (cGUID > 0), solo actualizar estado
if ($existingGuid > 0) {
    // El personaje ya existe — solo marcar como aprobado
    updateTransferStatus($transferId, 1, '');
    Session::flash('message', t('transfer_approved') . " [{$charName}]");
    header('Location: ../dashboard.php');
    exit;
}

// Dump no disponible → error
if (empty($dumpData)) {
    Session::flash('error', t('dump_unavailable'));
    header('Location: ../dashboard.php');
    exit;
}

// ── Importar el personaje en la DB del realm ──────────────────
$guid = applyCharacterDump($realmId, $dumpData, $targetAccountId);

if ($guid <= 0) {
    Session::flash('error', t('dump_apply_error') . ' — ' . t('check_server_logs'));
    header('Location: ../dashboard.php');
    exit;
}

// Actualizar GUID en el registro de transferencia
DB::auth()->update(
    'account_transfer',
    ['cGUID' => $guid],
    '`id` = ?',
    [$transferId]
);

// Marcar como aprobado
updateTransferStatus($transferId, 1, '');

Session::flash('message', t('transfer_approved') . " [{$charName}]");
header('Location: ../dashboard.php');
exit;
