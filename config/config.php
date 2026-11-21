<?php
/**
 * Configuration de l'application.
 *
 * Les valeurs par défaut correspondent à une installation XAMPP standard :
 * MariaDB/MySQL sur le port 3306, utilisateur « root » sans mot de passe.
 *
 * Pour changer une valeur SANS modifier ce fichier (utile avec Git), créez
 * config/config.local.php qui retourne seulement les clés à remplacer, par exemple :
 *
 *     <?php
 *     return ['db' => ['port' => 3307, 'pass' => 'mon_mot_de_passe']];
 */

$config = [
    'app_name' => 'Cave & Domaine',

    // true = affiche le détail des erreurs techniques (à réserver au développement)
    'debug' => false,

    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'gestion_cave',
        'user'    => 'root',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],

    // Un seul compte suffit pour le prototype.
    // Identifiants de démonstration : admin / cave2026
    // Pour désactiver la connexion : 'enabled' => false
    'auth' => [
        'enabled'       => true,
        'indice'        => 'Compte de démonstration : admin / cave2026',   // affiché sous le formulaire (vide = rien)
        'user'          => 'admin',
        'password_hash' => '$2y$10$U/Q94Mq1EvILjalhRejHLevqEkj1jaoTiB8zlpVbJFQrfENtMuEFa',
    ],

    // Limites de l'import CSV (bonus F7)
    'import' => [
        'taille_max_octets' => 2 * 1024 * 1024,
        'lignes_max'        => 5000,
    ],
];

$fichierLocal = __DIR__ . '/config.local.php';
if (is_file($fichierLocal)) {
    $config = array_replace_recursive($config, require $fichierLocal);
}

// Les tests (php tests/run.php) travaillent sur une base séparée, « gestion_cave_test » :
// ils la désignent par cette variable d'environnement, sans jamais toucher à la base de démonstration.
$baseDemandee = getenv('CAVE_DB_NAME');
if (is_string($baseDemandee) && $baseDemandee !== '') {
    $config['db']['name'] = $baseDemandee;
}

return $config;
