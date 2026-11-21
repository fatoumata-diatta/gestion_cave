<?php
/**
 * Point d'entrée commun de toutes les pages web.
 *
 * Ajoute au noyau (src/core.php) : en-têtes de sécurité, session, gestion des erreurs
 * et contrôle de connexion. Une page publique (login, verif) définit
 * PAGE_PUBLIQUE avant d'inclure ce fichier.
 */
declare(strict_types=1);

require_once __DIR__ . '/core.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/graphiques.php';

ini_set('display_errors', config('debug') ? '1' : '0');

// --- En-têtes de sécurité -------------------------------------------------
header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header(
    "Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; "
    . "script-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'"
);

// --- Session (cookie inaccessible au JavaScript, non envoyé depuis un site tiers) ---
session_name('cave_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']),
]);
session_start();

// --- Erreurs : message clair pour l'utilisateur, détail technique dans le journal PHP ---
set_exception_handler(static function (Throwable $erreur): void {
    error_log('[gestion-cave] ' . get_class($erreur) . ' : ' . $erreur->getMessage() . ' (' . $erreur->getFile() . ':' . $erreur->getLine() . ')');
    if (!headers_sent()) {
        http_response_code(500);
    }
    $detail = config('debug') ? ' Détail : ' . $erreur->getMessage() : '';

    if ($erreur instanceof ConnexionBaseException) {
        page_erreur_simple(
            'Base de données inaccessible',
            'Vérifiez que MySQL est démarré dans le panneau XAMPP, que la base « gestion_cave » a été importée '
            . 'et que config/config.php contient les bons identifiants.' . $detail,
            'Lancer la vérification d\'installation',
            'verif.php'
        );
    } else {
        page_erreur_simple('Une erreur est survenue', 'L\'opération n\'a pas pu être terminée. Aucune donnée n\'a été perdue.' . $detail);
    }
});

if (!defined('PAGE_PUBLIQUE')) {
    exiger_connexion();
}
