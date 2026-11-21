<?php
declare(strict_types=1);

/** Annulation d'une commande (POST uniquement, jeton CSRF obligatoire) : le stock est remis. */
require __DIR__ . '/src/bootstrap.php';

if (!est_post()) {
    redirect('commandes.php');
}
csrf_verifier();

$id = lire_entier(champ_post('id')) ?? 0;

try {
    commande_annuler($id);
    flash('succes', 'La commande n°' . $id . ' est annulée : les bouteilles sont remises en stock.');
} catch (RegleGestionException $erreur) {
    flash('erreur', implode(' ', $erreur->erreurs()));
}

redirect($id > 0 ? 'commande_voir.php' : 'commandes.php', $id > 0 ? ['id' => $id] : []);
