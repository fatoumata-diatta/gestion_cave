<?php
declare(strict_types=1);

/**
 * Connexion unique à la base de données (PDO).
 *
 * - ERRMODE_EXCEPTION : toute erreur SQL lève une exception (jamais d'échec silencieux).
 * - EMULATE_PREPARES = false : les requêtes préparées sont exécutées par le serveur
 *   MySQL lui-même, donc les valeurs ne sont JAMAIS recopiées dans le texte SQL :
 *   c'est la vraie protection contre les injections SQL.
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $c = config('db');
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $c['host'],
            $c['port'],
            $c['name'],
            $c['charset']
        );
        try {
            $pdo = new PDO($dsn, (string) $c['user'], (string) $c['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $erreur) {
            throw new ConnexionBaseException($erreur->getMessage(), 0, $erreur);
        }
    }

    return $pdo;
}

/** Exécute une requête préparée et retourne toutes les lignes. */
function requete_toutes(string $sql, array $params = []): array
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/** Exécute une requête préparée et retourne la première ligne (ou null). */
function requete_une(string $sql, array $params = []): ?array
{
    $st = db()->prepare($sql);
    $st->execute($params);
    $ligne = $st->fetch();
    return $ligne === false ? null : $ligne;
}

/** Exécute une requête d'écriture (INSERT, UPDATE, DELETE) et retourne le nombre de lignes touchées. */
function requete_executer(string $sql, array $params = []): int
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->rowCount();
}

/** Exécute une requête préparée et retourne la première colonne de la première ligne. */
function requete_valeur(string $sql, array $params = []): mixed
{
    $st = db()->prepare($sql);
    $st->execute($params);
    $valeur = $st->fetchColumn();
    return $valeur === false ? null : $valeur;
}
