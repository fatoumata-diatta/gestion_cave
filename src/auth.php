<?php
declare(strict_types=1);

/**
 * Connexion simple : un seul compte (cahier des charges : « un seul compte suffit »).
 * L'identifiant et le hash du mot de passe sont dans config/config.php.
 * Désactivable avec 'auth' => ['enabled' => false].
 */

function auth_active(): bool
{
    return (bool) config('auth.enabled', true);
}

function est_connecte(): bool
{
    return !auth_active() || !empty($_SESSION['utilisateur']);
}

function utilisateur_courant(): string
{
    return auth_active() ? (string) ($_SESSION['utilisateur'] ?? '') : '';
}

/** Renvoie vers la page de connexion si personne n'est connecté. */
function exiger_connexion(): void
{
    if (!est_connecte()) {
        redirect('login.php');
    }
}

/**
 * Vérifie l'identifiant et le mot de passe. password_verify compare avec le hash bcrypt :
 * le mot de passe n'est jamais stocké en clair.
 */
function tenter_connexion(string $utilisateur, string $motDePasse): bool
{
    $identifiantOk = hash_equals((string) config('auth.user'), $utilisateur);
    $motDePasseOk  = password_verify($motDePasse, (string) config('auth.password_hash'));

    if ($identifiantOk && $motDePasseOk) {
        session_regenerate_id(true);   // nouvel identifiant de session : empêche la fixation de session
        $_SESSION['utilisateur'] = $utilisateur;
        return true;
    }

    usleep(400_000);                   // ralentit les essais répétés
    return false;
}

function deconnecter(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], (bool) $p['secure'], (bool) $p['httponly']);
    }
    session_destroy();
}
