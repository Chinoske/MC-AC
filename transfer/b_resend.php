<?php
/**
 * b_resend.php — GM: Reenviar los items de una transferencia aprobada por correo en el juego
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
$guid       = (int) Input::get('guid');

if ($transferId <= 0 || $realmId <= 0 || $guid <= 0) {
    Session::flash('error', t('invalid_params'));
    header('Location: ../dashboard.php');
    exit;
}

// El personaje debe estar desconectado
if (isCharacterOnline($guid, $realmId)) {
    Session::flash('error', t('char_online_error'));
    header('Location: ../dashboard.php');
    exit;
}

// Solo re-enviable si está en progreso (0) o ya aprobado (1)
$status = getTransferStatus($transferId);
if ($status !== 0 && $status !== 1) {
    Session::flash('error', t('transfer_not_pending'));
    header('Location: ../dashboard.php');
    exit;
}

// Obtener el nombre del personaje
$charName = getCharacterName($guid, $realmId);
if (!$charName) {
    Session::flash('error', t('character_not_found'));
    header('Location: ../dashboard.php');
    exit;
}

// Obtener items del inventario del personaje para reenviarlos
try {
    $items = DB::chars($realmId)->rows(
        'SELECT `item_entry`, `count`
           FROM `character_inventory` ci
           JOIN `item_instance` ii ON ii.`guid` = ci.`item`
          WHERE ci.`guid` = ?',
        [$guid]
    );

    $itemArray = [];
    foreach ($items as $item) {
        $entry = (int) $item->item_entry;
        if (!isBlockedItem($entry)) {
            $itemArray[$entry] = sanitizeItemCount((int) $item->count);
        }
    }

    if (!empty($itemArray)) {
        sendItemsByMail($realmId, $charName, $itemArray, t('mail_subject_transfer_items'));
    }
} catch (Throwable $e) {
    error_log('[Migrador] Error obteniendo items para reenvío: ' . $e->getMessage());
}

// Actualizar estado a Reenviado (4)
updateTransferStatus($transferId, 4);

Session::flash('message', t('items_sent') . " [{$charName}]");
header('Location: ../dashboard.php');
exit;
