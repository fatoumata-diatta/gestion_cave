<?php
declare(strict_types=1);

/**
 * Processus « client » lancé par tests/run.php pour simuler plusieurs utilisateurs qui commandent
 * EN MÊME TEMPS (test de concurrence). Ne pas lancer à la main.
 *
 * Usage : php worker_commande.php <id_client> <id_cuvee> <quantite> <instant_de_depart>
 * Tous les processus attendent le même instant de départ, puis valident leur commande ensemble.
 * Résultat : une ligne JSON sur la sortie standard.
 */
if (PHP_SAPI !== 'cli' || $argc < 5) {
    exit(1);
}

putenv('CAVE_DB_NAME=gestion_cave_test');   // jamais la base de démonstration
require dirname(__DIR__) . '/src/core.php';

[, $idClient, $idCuvee, $quantite, $depart] = $argv;

db();   // la connexion est ouverte avant le top départ, pour que la course soit réellement simultanée
while (microtime(true) < (float) $depart) {
    usleep(200);
}

try {
    $id = commande_creer((int) $idClient, [['id_cuvee' => $idCuvee, 'quantite' => $quantite]]);
    echo json_encode(['ok' => true, 'id' => $id]);
} catch (RegleGestionException $refus) {
    echo json_encode(['ok' => false, 'refus' => $refus->erreurs()]);
} catch (Throwable $erreur) {
    echo json_encode(['ok' => false, 'technique' => $erreur->getMessage()]);
}
