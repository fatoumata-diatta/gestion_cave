<?php
declare(strict_types=1);

/**
 * F3 : gestion des clients (ajouter, modifier, rechercher).
 * Un client n'est jamais supprimé : il garde son historique de commandes.
 */

/** Liste des clients avec leur nombre de commandes validées et leur chiffre d'affaires. */
function clients_liste(string $recherche = '', string $type = ''): array
{
    $sql = 'SELECT cl.id, cl.nom, cl.type_client, cl.email, cl.ville,
                   COALESCE(st.nb_commandes, 0) AS nb_commandes,
                   COALESCE(st.ca, 0)           AS ca
            FROM client cl
            LEFT JOIN (SELECT id_client, COUNT(*) AS nb_commandes, SUM(total) AS ca
                       FROM v_commande_total
                       WHERE statut = \'validee\'
                       GROUP BY id_client) st ON st.id_client = cl.id
            WHERE 1 = 1';
    $params = [];

    if ($recherche !== '') {
        $motif = '%' . echapper_like($recherche) . '%';
        $sql .= ' AND (cl.nom LIKE ? OR cl.email LIKE ? OR cl.ville LIKE ?)';
        array_push($params, $motif, $motif, $motif);
    }
    if ($type !== '' && isset(TYPES_CLIENT[$type])) {
        $sql .= ' AND cl.type_client = ?';
        $params[] = $type;
    }

    return requete_toutes($sql . ' ORDER BY cl.nom', $params);
}

function client_trouver(int $id): ?array
{
    return requete_une('SELECT id, nom, type_client, email, ville FROM client WHERE id = ?', [$id]);
}

/** Tous les clients (id, nom, type, ville) pour les listes déroulantes. */
function clients_pour_liste(): array
{
    return requete_toutes('SELECT id, nom, type_client, ville FROM client ORDER BY nom');
}

/**
 * Valide les champs saisis pour un client.
 *
 * @return array{0: array<string,string>, 1: array<string,string>} [données nettoyées, erreurs par champ]
 */
function client_valider(array $saisie): array
{
    $erreurs = [];
    $lire = static fn(string $cle): string => is_string($saisie[$cle] ?? null) ? $saisie[$cle] : '';

    $nom = texte_propre($lire('nom'));
    if ($nom === '') {
        $erreurs['nom'] = 'Le nom est obligatoire.';
    } elseif (mb_strlen($nom) > 120) {
        $erreurs['nom'] = 'Le nom ne doit pas dépasser 120 caractères.';
    }

    $type = $lire('type_client');
    if (!isset(TYPES_CLIENT[$type])) {
        $erreurs['type_client'] = 'Choisissez le type de client.';
    }

    $email = mb_strtolower(texte_propre($lire('email')));
    if ($email === '') {
        $erreurs['email'] = 'L\'adresse e-mail est obligatoire.';
    } elseif (mb_strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $erreurs['email'] = 'Cette adresse e-mail n\'est pas valide.';
    }

    $ville = texte_propre($lire('ville'));
    if ($ville === '') {
        $erreurs['ville'] = 'La ville est obligatoire.';
    } elseif (mb_strlen($ville) > 100) {
        $erreurs['ville'] = 'La ville ne doit pas dépasser 100 caractères.';
    }

    return [['nom' => $nom, 'type_client' => $type, 'email' => $email, 'ville' => $ville], $erreurs];
}

/** @throws RegleGestionException si l'adresse e-mail est déjà utilisée */
function client_creer(array $d): int
{
    try {
        requete_executer(
            'INSERT INTO client (nom, type_client, email, ville) VALUES (?, ?, ?, ?)',
            [$d['nom'], $d['type_client'], $d['email'], $d['ville']]
        );
        return (int) db()->lastInsertId();
    } catch (PDOException $erreur) {
        if (est_doublon($erreur)) {
            throw new RegleGestionException(['Un client avec cette adresse e-mail existe déjà.']);
        }
        throw $erreur;
    }
}

/** @throws RegleGestionException si l'adresse e-mail est déjà utilisée par un autre client */
function client_modifier(int $id, array $d): void
{
    try {
        requete_executer(
            'UPDATE client SET nom = ?, type_client = ?, email = ?, ville = ? WHERE id = ?',
            [$d['nom'], $d['type_client'], $d['email'], $d['ville'], $id]
        );
    } catch (PDOException $erreur) {
        if (est_doublon($erreur)) {
            throw new RegleGestionException(['Un autre client utilise déjà cette adresse e-mail.']);
        }
        throw $erreur;
    }
}
