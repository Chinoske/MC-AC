<?php
/**
 * language.php — Strings de interfaz en múltiples idiomas
 * Idiomas disponibles: es | en | fr | de | ru
 */

$LANG = [
    // ── ESPAÑOL ─────────────────────────────────────────────────
    'es' => [
        'site_title'            => 'Migrador — AzerothCore',
        'login_title'           => 'Acceder',
        'login_user'            => 'Usuario',
        'login_pass'            => 'Contraseña',
        'login_btn'             => 'Entrar',
        'login_error'           => 'Usuario o contraseña incorrectos.',
        'login_locked'          => 'Esta cuenta está bloqueada.',
        'logout'                => 'Cerrar sesión',
        'welcome'               => 'Bienvenido',

        'transfer_title'        => 'Transferencia de Personaje',
        'transfer_step1'        => 'Paso 1 — Subir dump de personaje',
        'transfer_step2'        => 'Paso 2 — Confirmar nombre del personaje',
        'transfer_status_0'     => 'En progreso',
        'transfer_status_1'     => 'Aprobado',
        'transfer_status_2'     => 'Denegado',
        'transfer_status_3'     => 'Cancelado',
        'transfer_status_4'     => 'Reenviado',

        'btn_approve'           => 'Aprobar',
        'btn_deny'              => 'Denegar',
        'btn_resend'            => 'Reenviar',
        'btn_cancel'            => 'Cancelar',
        'btn_submit'            => 'Enviar',
        'btn_upload'            => 'Subir archivo',
        'btn_confirm'           => 'Confirmar',
        'btn_back'              => '← Volver',

        'gm_panel'              => 'Panel de Administración',
        'player_panel'          => 'Mis Transferencias',

        'char_online_error'     => 'El personaje debe estar desconectado.',
        'transfer_not_pending'  => 'Esta transferencia no está en estado pendiente.',
        'access_denied'         => 'Acceso denegado.',
        'server_offline'        => 'El servidor está offline en este momento.',
        'invalid_file'          => 'Archivo inválido. Solo se acepta chardump.lua generado por el addon.',
        'invalid_char_name'     => 'Nombre inválido (solo letras, 2-16 caracteres).',
        'name_taken'            => 'Ese nombre de personaje ya está en uso en este servidor.',
        'transfer_queued'       => '✔ Tu transferencia ha sido registrada y está en revisión. Un GM la procesará pronto.',
        'transfer_cancelled'    => 'Transferencia cancelada correctamente.',
        'transfer_approved'     => '✔ Transferencia aprobada. El personaje ha sido asignado a tu cuenta.',
        'transfer_denied'       => 'Transferencia denegada.',
        'achievement_fail'      => 'Tu personaje no cumple el mínimo de logros requerido (' . MIN_ACHIEVEMENTS . ').',
        'level_fail'            => 'El nivel del personaje supera el máximo permitido (' . MAX_LEVEL . ').',
        'blacklisted'           => 'Tu cuenta está en la lista negra de transferencias.',
        'invalid_dump'          => 'El dump de personaje no es válido o está corrupto.',
        'dump_apply_error'      => 'Error al aplicar el dump en la base de datos. Contacta a un administrador.',
        'reason_label'          => 'Motivo',
        'no_transfers'          => 'No hay transferencias registradas.',

        'instructions_title'    => 'Cómo transferir tu personaje',
        'instructions'          => '<ol>
            <li>En el servidor de <strong>origen</strong>, instala el addon <code>chardump</code>.</li>
            <li>Entra al juego y escribe <code>/chardump</code> — se generará el archivo.</li>
            <li>Localiza el archivo <code>chardump.lua</code> en tu carpeta WTF del cliente.</li>
            <li>Sube ese archivo aquí usando el botón de abajo.</li>
            <li>Un GM revisará tu solicitud en un plazo de 24-48 horas.</li>
            <li>Recibirás notificación cuando el personaje esté listo.</li>
        </ol>',

        'realm_select'          => 'Realm destino',
        'select_realm'          => '— Selecciona un realm —',
        'upload_label'          => 'Archivo chardump.lua',
        'char_name_label'       => 'Nombre del personaje en este servidor',
        'char_name_hint'        => 'Solo letras, 2-16 caracteres. Sin espacios ni números.',
        'confirm_cancel'        => '¿Confirmas la cancelación de esta transferencia?',
        'confirm_approve'       => '¿Aprobar esta transferencia?',
        'confirm_deny'          => '¿Denegar esta transferencia?',
        'deny_reason'           => 'Motivo de denegación',
        'items_sent'            => 'Los items han sido reenviados por correo en el juego.',
        'token_error'           => 'Error de seguridad. Recarga la página e inténtalo de nuevo.',
    ],

    // ── ENGLISH ─────────────────────────────────────────────────
    'en' => [
        'site_title'            => 'Migrator — AzerothCore',
        'login_title'           => 'Login',
        'login_user'            => 'Username',
        'login_pass'            => 'Password',
        'login_btn'             => 'Sign In',
        'login_error'           => 'Wrong username or password.',
        'login_locked'          => 'This account is locked.',
        'logout'                => 'Logout',
        'welcome'               => 'Welcome',

        'transfer_title'        => 'Character Transfer',
        'transfer_step1'        => 'Step 1 — Upload character dump',
        'transfer_step2'        => 'Step 2 — Confirm character name',
        'transfer_status_0'     => 'In Progress',
        'transfer_status_1'     => 'Approved',
        'transfer_status_2'     => 'Denied',
        'transfer_status_3'     => 'Cancelled',
        'transfer_status_4'     => 'Resent',

        'btn_approve'           => 'Approve',
        'btn_deny'              => 'Deny',
        'btn_resend'            => 'Resend',
        'btn_cancel'            => 'Cancel',
        'btn_submit'            => 'Submit',
        'btn_upload'            => 'Upload File',
        'btn_confirm'           => 'Confirm',
        'btn_back'              => '← Back',

        'gm_panel'              => 'Admin Panel',
        'player_panel'          => 'My Transfers',

        'char_online_error'     => 'Character must be offline.',
        'transfer_not_pending'  => 'Transfer is not in a pending state.',
        'access_denied'         => 'Access denied.',
        'server_offline'        => 'The server is currently offline.',
        'invalid_file'          => 'Invalid file. Only chardump.lua from the addon is accepted.',
        'invalid_char_name'     => 'Invalid name (letters only, 2-16 chars).',
        'name_taken'            => 'That character name is already taken on this server.',
        'transfer_queued'       => '✔ Your transfer has been queued. A GM will review it soon.',
        'transfer_cancelled'    => 'Transfer cancelled.',
        'transfer_approved'     => '✔ Transfer approved. Character assigned to your account.',
        'transfer_denied'       => 'Transfer denied.',
        'achievement_fail'      => 'Character does not meet the minimum achievement requirement (' . MIN_ACHIEVEMENTS . ').',
        'level_fail'            => 'Character level exceeds the allowed maximum (' . MAX_LEVEL . ').',
        'blacklisted'           => 'Your account is blacklisted from transfers.',
        'invalid_dump'          => 'Invalid or corrupted character dump.',
        'dump_apply_error'      => 'Failed to apply dump. Please contact an administrator.',
        'reason_label'          => 'Reason',
        'no_transfers'          => 'No transfers found.',

        'instructions_title'    => 'How to transfer your character',
        'instructions'          => '<ol>
            <li>On the <strong>source</strong> server, install the <code>chardump</code> addon.</li>
            <li>Log in and type <code>/chardump</code> — a file will be generated.</li>
            <li>Find <code>chardump.lua</code> in your WTF folder.</li>
            <li>Upload it here using the button below.</li>
            <li>A GM will review your request within 24-48 hours.</li>
            <li>You will be notified once the character is ready.</li>
        </ol>',

        'realm_select'          => 'Destination Realm',
        'select_realm'          => '— Select a realm —',
        'upload_label'          => 'chardump.lua file',
        'char_name_label'       => 'Character name on this server',
        'char_name_hint'        => 'Letters only, 2-16 characters. No spaces or numbers.',
        'confirm_cancel'        => 'Confirm cancellation of this transfer?',
        'confirm_approve'       => 'Approve this transfer?',
        'confirm_deny'          => 'Deny this transfer?',
        'deny_reason'           => 'Denial reason',
        'items_sent'            => 'Items have been resent via in-game mail.',
        'token_error'           => 'Security error. Reload the page and try again.',
    ],

    // ── FRANÇAIS ────────────────────────────────────────────────
    'fr' => [
        'site_title'            => 'Migrateur — AzerothCore',
        'login_title'           => 'Connexion',
        'login_user'            => 'Utilisateur',
        'login_pass'            => 'Mot de passe',
        'login_btn'             => 'Se connecter',
        'login_error'           => 'Nom d\'utilisateur ou mot de passe incorrect.',
        'login_locked'          => 'Ce compte est verrouillé.',
        'logout'                => 'Déconnexion',
        'welcome'               => 'Bienvenue',
        'transfer_title'        => 'Transfert de personnage',
        'transfer_step1'        => 'Étape 1 — Télécharger le dump',
        'transfer_step2'        => 'Étape 2 — Confirmer le nom',
        'transfer_status_0'     => 'En cours',
        'transfer_status_1'     => 'Approuvé',
        'transfer_status_2'     => 'Refusé',
        'transfer_status_3'     => 'Annulé',
        'transfer_status_4'     => 'Renvoyé',
        'btn_approve'           => 'Approuver',
        'btn_deny'              => 'Refuser',
        'btn_resend'            => 'Renvoyer',
        'btn_cancel'            => 'Annuler',
        'btn_submit'            => 'Envoyer',
        'btn_upload'            => 'Télécharger',
        'btn_confirm'           => 'Confirmer',
        'btn_back'              => '← Retour',
        'gm_panel'              => 'Panneau Admin',
        'player_panel'          => 'Mes transferts',
        'no_transfers'          => 'Aucun transfert trouvé.',
        'access_denied'         => 'Accès refusé.',
        'server_offline'        => 'Le serveur est hors ligne.',
        'invalid_file'          => 'Fichier invalide.',
        'invalid_char_name'     => 'Nom invalide (lettres uniquement, 2-16 chars).',
        'name_taken'            => 'Ce nom de personnage est déjà utilisé.',
        'transfer_queued'       => '✔ Votre transfert a été enregistré.',
        'transfer_cancelled'    => 'Transfert annulé.',
        'transfer_approved'     => '✔ Transfert approuvé.',
        'transfer_denied'       => 'Transfert refusé.',
        'achievement_fail'      => 'Succès insuffisants (' . MIN_ACHIEVEMENTS . ' requis).',
        'level_fail'            => 'Niveau trop élevé (max ' . MAX_LEVEL . ').',
        'blacklisted'           => 'Compte sur liste noire.',
        'invalid_dump'          => 'Dump invalide.',
        'dump_apply_error'      => 'Erreur lors de l\'application du dump.',
        'instructions_title'    => 'Comment transférer',
        'instructions'          => 'Installez l\'addon <code>chardump</code>, tapez <code>/chardump</code> en jeu, puis téléchargez le fichier ici.',
        'realm_select'          => 'Realm de destination',
        'select_realm'          => '— Choisir un realm —',
        'upload_label'          => 'Fichier chardump.lua',
        'char_name_label'       => 'Nom du personnage',
        'char_name_hint'        => 'Lettres uniquement, 2-16 caractères.',
        'confirm_cancel'        => 'Confirmer l\'annulation ?',
        'confirm_approve'       => 'Approuver ce transfert ?',
        'confirm_deny'          => 'Refuser ce transfert ?',
        'deny_reason'           => 'Motif',
        'items_sent'            => 'Objets renvoyés par courrier en jeu.',
        'token_error'           => 'Erreur de sécurité. Rechargez la page.',
        'char_online_error'     => 'Le personnage doit être hors ligne.',
        'transfer_not_pending'  => 'Ce transfert n\'est pas en attente.',
        'reason_label'          => 'Motif',
    ],
];

/**
 * Devuelve un string localizado por clave.
 * Fallback: inglés → clave literal.
 */
function t(string $key): string
{
    global $LANG;
    $lang = DEFAULT_LANG;
    return $LANG[$lang][$key]
        ?? $LANG['en'][$key]
        ?? $key;
}
