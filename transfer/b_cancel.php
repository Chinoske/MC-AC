<?php
/**
 * b_cancel.php — Jugador: Cancelar su propia transferencia (solo si está pendiente)
 * Si el personaje YA fue importado (cGUID > 0) lo elimina de la DB.
 */
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/language.php';
require_once __DIR__ . '/dbfunctions.php';

$user = new User();
if (!$user->isLoggedIn()) {
    header('Location: ../index.php');
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
    Session::flash('error', 'Parámetros inválidos.');
    header('Location: ../dashboard.php');
    exit;
}

// Verificar que la transferencia pertenece al usuario
$row = DB::auth()->row(
    'SELECT `cAccount`, `cGUID`, `status` FROM `account_transfer` WHERE `id` = ? LIMIT 1',
    [$transferId]
);

if (!$row || (int) $row->cAccount !== $user->id()) {
    Session::flash('error', t('access_denied'));
    header('Location: ../dashboard.php');
    exit;
}

if ((int) $row->status !== 0) {
    Session::flash('error', t('transfer_not_pending'));
    header('Location: ../dashboard.php');
    exit;
}

$guid = (int) $row->cGUID;

// Si ya fue importado: verificar online y borrar personaje
if ($guid > 0) {
    if (isCharacterOnline($guid, $realmId)) {
        Session::flash('error', t('char_online_error'));
        header('Location: ../dashboard.php');
        exit;
    }
    cancelOrDenyTransfer($guid, $realmId);
}

updateTransferStatus($transferId, 3);

Session::flash('message', t('transfer_cancelled'));
header('Location: ../dashboard.php');
exit;
