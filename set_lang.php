<?php
/**
 * set_lang.php — Cambia el idioma activo (guardado en sesión) y vuelve a
 * la página desde la que se llamó.
 */
require_once __DIR__ . '/config.php';
require_once TRANSFER_PATH . '/language.php';

$lang = Input::get('lang', 'get');
if (isset(AVAILABLE_LANGS[$lang])) {
    $_SESSION['lang'] = $lang;
    session_write_close();
}

// El "return" debe ser una ruta interna (empieza con "/", sin esquema ni
// host) para evitar un open redirect a un sitio externo.
$return = (string) ($_GET['return'] ?? '/index.php');
if ($return === '' || $return === '/') {
    $return = '/index.php';
}
if (!str_starts_with($return, '/') || str_starts_with($return, '//') || str_contains($return, '://')) {
    $return = '/index.php';
}

header('Cache-Control: no-store');
header('Location: ' . $return, true, 303);
exit;
