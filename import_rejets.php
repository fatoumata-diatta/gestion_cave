<?php
declare(strict_types=1);

/**
 * Télécharge, en CSV, les lignes refusées par le dernier import (rapport gardé dans la session).
 * Le fichier reprend les colonnes d'origine plus une colonne « motif_du_rejet » : on le corrige, puis on le réimporte.
 */
require __DIR__ . '/src/bootstrap.php';

$rapport = $_SESSION['import_rapport'] ?? null;
$resultat = is_array($rapport) ? ($rapport['resultat'] ?? null) : null;

if (!is_array($resultat) || empty($resultat['rejets'])) {
    flash('erreur', 'Il n\'y a aucune ligne refusée à télécharger.');
    redirect('import.php');
}

[$entetes, $lignes] = import_rejets_pour_csv($resultat);
csv_envoyer('lignes_refusees_' . $resultat['type'] . '_' . date('Y-m-d') . '.csv', csv_document($entetes, $lignes));
