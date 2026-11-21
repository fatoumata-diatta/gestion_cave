<?php
declare(strict_types=1);

/**
 * F2 : gestion du stock (consulter, saisir une entrée, alerte sous le seuil).
 *
 * Règle d'or : le stock ne change JAMAIS sans qu'un mouvement soit écrit dans le journal,
 * dans la même transaction. Ainsi stock = somme des mouvements, toujours (vue v_controle_stock).
 */

/** Niveau d'un stock : « rupture » (0), « bas » (à ou sous le seuil) ou « ok ». */
function niveau_stock(int $quantite, int $seuil): string
{
    if ($quantite <= 0) {
        return 'rupture';
    }
    return $quantite <= $seuil ? 'bas' : 'ok';
}

/** Stock de toutes les cuvées, avec sa valeur au prix de vente actuel. */
function stock_liste(string $recherche = '', bool $alertesSeulement = false): array
{
    $sql = 'SELECT cu.id, cu.nom, cu.millesime, cu.couleur, cu.prix_vente,
                   s.quantite, s.seuil_alerte, s.quantite * cu.prix_vente AS valeur
            FROM stock s
            JOIN cuvee cu ON cu.id = s.id_cuvee
            WHERE 1 = 1';
    $params = [];

    if ($recherche !== '') {
        $motif = '%' . echapper_like($recherche) . '%';
        $sql .= ' AND (cu.nom LIKE ? OR CAST(cu.millesime AS CHAR) LIKE ?)';
        array_push($params, $motif, $motif);
    }
    if ($alertesSeulement) {
        $sql .= ' AND s.quantite <= s.seuil_alerte';
    }

    return requete_toutes($sql . ' ORDER BY cu.nom, cu.millesime', $params);
}

/**
 * Enregistre une entrée de stock (mise en bouteille) : stock + journal, en une transaction.
 *
 * @throws RegleGestionException si la quantité ou le motif est invalide, ou si la cuvée n'existe pas
 */
function stock_entree(int $idCuvee, string $quantiteSaisie, string $motifSaisi): int
{
    $erreurs = [];

    $quantite = lire_entier($quantiteSaisie);
    if ($quantite === null || $quantite < 1 || $quantite > 100000) {
        $erreurs[] = 'La quantité doit être un nombre entier compris entre 1 et 100 000.';
    }

    $motif = texte_propre($motifSaisi);
    if ($motif === '') {
        $motif = 'Mise en bouteille';
    } elseif (mb_strlen($motif) > 120) {
        $erreurs[] = 'Le motif ne doit pas dépasser 120 caractères.';
    }

    if ($erreurs) {
        throw new RegleGestionException($erreurs);
    }

    return en_transaction(db(), static function (PDO $pdo) use ($idCuvee, $quantite, $motif): int {
        // Verrou sur la ligne de stock : personne d'autre ne peut la modifier avant la fin de la transaction.
        $st = $pdo->prepare('SELECT quantite FROM stock WHERE id_cuvee = ? FOR UPDATE');
        $st->execute([$idCuvee]);
        if ($st->fetchColumn() === false) {
            throw new RegleGestionException(['Cuvée introuvable.']);
        }

        $pdo->prepare('UPDATE stock SET quantite = quantite + ? WHERE id_cuvee = ?')->execute([$quantite, $idCuvee]);
        $pdo->prepare('INSERT INTO mouvement_stock (id_cuvee, quantite, type_mouvement, motif) VALUES (?, ?, \'entree\', ?)')
            ->execute([$idCuvee, $quantite, $motif]);

        $st = $pdo->prepare('SELECT quantite FROM stock WHERE id_cuvee = ?');
        $st->execute([$idCuvee]);
        return (int) $st->fetchColumn();
    });
}

/**
 * Journal des mouvements de stock, du plus récent au plus ancien.
 *
 * @param array{id_cuvee?: int, type?: string, id_commande?: int} $filtres
 */
function mouvements_liste(array $filtres = [], int $limite = 200): array
{
    $sql = 'SELECT m.id, m.date_mouvement, m.type_mouvement, m.quantite, m.motif, m.id_commande,
                   cu.id AS id_cuvee, cu.nom, cu.millesime
            FROM mouvement_stock m
            JOIN cuvee cu ON cu.id = m.id_cuvee
            WHERE 1 = 1';
    $params = [];

    if (!empty($filtres['id_cuvee'])) {
        $sql .= ' AND m.id_cuvee = ?';
        $params[] = (int) $filtres['id_cuvee'];
    }
    if (!empty($filtres['type']) && in_array($filtres['type'], ['entree', 'sortie'], true)) {
        $sql .= ' AND m.type_mouvement = ?';
        $params[] = $filtres['type'];
    }
    if (!empty($filtres['id_commande'])) {
        $sql .= ' AND m.id_commande = ?';
        $params[] = (int) $filtres['id_commande'];
    }

    // LIMIT est un entier maîtrisé par le code (jamais une saisie utilisateur).
    $sql .= ' ORDER BY m.date_mouvement DESC, m.id DESC LIMIT ' . max(1, $limite);
    return requete_toutes($sql, $params);
}

/** Cuvées dont le stock enregistré diffère de la somme des mouvements (doit toujours être vide). */
function controle_stock_ecarts(): array
{
    return requete_toutes('SELECT * FROM v_controle_stock WHERE ecart <> 0 ORDER BY nom');
}
