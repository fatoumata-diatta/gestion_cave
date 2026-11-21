<?php
declare(strict_types=1);

/** F1 : suppression d'une cuvée (POST uniquement). Refusée si la cuvée a un historique. */
require __DIR__ . '/src/bootstrap.php';

if (!est_post()) {
    redirect('cuvees.php');
}
csrf_verifier();

$id = lire_entier(champ_post('id')) ?? 0;
$cuvee = $id > 0 ? cuvee_trouver($id) : null;

try {
    cuvee_supprimer($id);
    flash('succes', 'La cuvée « ' . ($cuvee['nom'] ?? '') . ' ' . ($cuvee['millesime'] ?? '') . ' » a été supprimée.');
} catch (RegleGestionException $erreur) {
    flash('erreur', implode(' ', $erreur->erreurs()));
}
redirect('cuvees.php');
