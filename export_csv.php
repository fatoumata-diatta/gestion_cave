<?php
declare(strict_types=1);

/**
 * F9 : télécharge un export CSV. Le jeu de données se choisit par ?donnees=commandes|lignes|stock|clients|cuvees|mouvements ;
 * les autres paramètres sont ceux de l'écran correspondant (filtres). Lecture seule : aucune donnée n'est modifiée.
 */
require __DIR__ . '/src/bootstrap.php';

$type = champ_get('donnees');
if (!isset(export_types()[$type])) {
    http_response_code(404);
    page_erreur_simple('Export introuvable', 'Cet export n\'existe pas. Choisissez-en un dans la liste.', 'Voir les exports', 'export.php');
    exit;
}

$donnees = export_donnees($type, export_filtres_get($type));
csv_envoyer(export_nom_fichier($type), csv_document($donnees['entetes'], $donnees['lignes']));
