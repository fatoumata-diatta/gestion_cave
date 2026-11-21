<?php
declare(strict_types=1);

/**
 * Protection CSRF : empêche un site tiers de faire envoyer un formulaire à l'insu de l'utilisateur
 * connecté (par exemple annuler une commande en cliquant sur un lien piégé).
 *
 * Chaque formulaire contient un jeton secret lié à la session ; il est vérifié à la réception.
 * Toutes les actions qui modifient des données passent par POST, jamais par un lien GET.
 */

function csrf_token(): string
{
    if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/** Champ caché à placer dans chaque formulaire POST. */
function csrf_champ(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

/** Vérifie le jeton reçu ; arrête la page avec une erreur 419 s'il est absent ou faux. */
function csrf_verifier(): void
{
    $recu = $_POST['_csrf'] ?? '';
    $attendu = $_SESSION['csrf'] ?? '';
    if (is_string($recu) && is_string($attendu) && $attendu !== '' && hash_equals($attendu, $recu)) {
        return;
    }

    http_response_code(419);
    page_erreur_simple(
        'Session expirée',
        'Le formulaire n\'a pas pu être vérifié (jeton de sécurité absent ou périmé). '
        . 'Rechargez la page, puis recommencez.'
    );
    exit;
}
