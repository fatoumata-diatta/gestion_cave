<?php
/**
 * Noyau de l'application : configuration, connexion à la base et logique métier.
 *
 * Ce fichier ne dépend ni de la session ni du navigateur : il peut donc être
 * chargé en ligne de commande (c'est ce que font les tests dans tests/).
 * Les pages web chargent src/bootstrap.php, qui ajoute la session, la sécurité
 * et la connexion.
 */
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

$GLOBALS['config'] = require APP_ROOT . '/config/config.php';

mb_internal_encoding('UTF-8');
date_default_timezone_set('Europe/Paris');
error_reporting(E_ALL);
ini_set('log_errors', '1');

require_once __DIR__ . '/exceptions.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/cuvees.php';
require_once __DIR__ . '/stock.php';
require_once __DIR__ . '/clients.php';
require_once __DIR__ . '/commandes.php';
require_once __DIR__ . '/dashboard.php';
require_once __DIR__ . '/csv.php';
require_once __DIR__ . '/import.php';
require_once __DIR__ . '/export.php';
