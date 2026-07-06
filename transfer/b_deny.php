<?php
/**
 * b_deny.php — GM: Denegar una transferencia
 * Si el personaje YA fue importado (cGUID > 0) lo elimina de la DB.
 * Si aún está pendiente (cGUID = 0) solo cambia el estado.
 */
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/language.php';
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
$reason     = trim(Input::get('reason') ?? '');

if ($transferId <= 0 || $realmId <= 0) {
    Session::flash('error', t('invalid_params'));
    header('Location: ../dashboard.php');
    exit;
}

// Solo denegable si está en progreso (0)
if (getTransferStatus($transferId) !== 0) {
    Session::flash('error', t('transfer_not_pending'));
    header('Location: ../dashboard.php');
    exit;
}

// Obtener GUID real desde la tabla
$row  = DB::auth()->row('SELECT `cGUID` FROM `account_transfer` WHERE `id` = ? LIMIT 1', [$transferId]);
$guid = $row ? (int) $row->cGUID : 0;

// Si ya fue importado (guid > 0): verificar online y borrar
if ($guid > 0) {
    if (isCharacterOnline($guid, $realmId)) {
        Session::flash('error', t('char_online_error'));
        header('Location: ../dashboard.php');
        exit;
    }
    cancelOrDenyTransfer($guid, $realmId);
}

// Marcar como denegado (2) con motivo
updateTransferStatus($transferId, 2, $reason ?: t('no_reason_specified'));

Session::flash('message', t('transfer_denied'));
header('Location: ../dashboard.php');
exit;
