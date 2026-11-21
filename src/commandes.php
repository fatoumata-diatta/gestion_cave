<?php
declare(strict_types=1);

/**
 * F4 : saisie d'une commande     F5 : mise à jour du stock
 *
 * C'est le cœur de l'application. Valider une commande, c'est faire QUATRE choses qui
 * doivent réussir ENSEMBLE ou échouer ENSEMBLE :
 *   1. créer la commande ;  2. créer ses lignes (avec le prix recopié) ;
 *   3. baisser le stock ;   4. écrire les mouvements de sortie.
 * D'où la transaction : au moindre refus, tout est annulé et la base reste identique.
 */

const MAX_LIGNES_PAR_COMMANDE = 100;
const MAX_QUANTITE_PAR_LIGNE  = 100000;

/** Cuvées proposées dans le formulaire de commande, avec prix et stock disponible. */
function cuvees_pour_commande(): array
{
    return requete_toutes(
        'SELECT cu.id, cu.nom, cu.millesime, cu.couleur, cu.prix_vente, s.quantite AS stock
         FROM cuvee cu
         JOIN stock s ON s.id_cuvee = cu.id
         ORDER BY cu.nom, cu.millesime'
    );
}

/**
 * Lit dans l'adresse (GET) les filtres de la liste des commandes. Partagé par l'écran « Commandes »
 * et par l'export CSV : les deux comprennent donc exactement les mêmes paramètres.
 *
 * @return array{du: string, au: string, id_client: int, id_cuvee: int, statut: string}
 */
function commandes_filtres_get(): array
{
    return [
        'du'        => lire_date(champ_get('du')) ?? '',
        'au'        => lire_date(champ_get('au')) ?? '',
        'id_client' => entier_get('client'),
        'id_cuvee'  => entier_get('cuvee'),
        'statut'    => isset(STATUTS_COMMANDE[champ_get('statut')]) ? champ_get('statut') : '',
    ];
}

/**
 * Conditions SQL (F8) d'un filtre de commandes, à ajouter après « WHERE 1 = 1 » d'une requête qui
 * porte la vue v_commande_total sous l'alias « t ». Partagé par la liste et par l'export CSV.
 *
 * @param array{du?: string, au?: string, id_client?: int, id_cuvee?: int, statut?: string} $f
 * @return array{0: string, 1: list<mixed>} [fragment SQL commençant par « AND », paramètres]
 */
function commandes_filtre_sql(array $f): array
{
    $sql = '';
    $params = [];

    if (!empty($f['du']) && lire_date((string) $f['du']) !== null) {
        $sql .= ' AND t.date_commande >= ?';
        $params[] = $f['du'] . ' 00:00:00';
    }
    if (!empty($f['au']) && lire_date((string) $f['au']) !== null) {
        $sql .= ' AND t.date_commande <= ?';
        $params[] = $f['au'] . ' 23:59:59';
    }
    if (!empty($f['id_client'])) {
        $sql .= ' AND t.id_client = ?';
        $params[] = (int) $f['id_client'];
    }
    if (!empty($f['id_cuvee'])) {
        $sql .= ' AND EXISTS (SELECT 1 FROM ligne_commande lf WHERE lf.id_commande = t.id_commande AND lf.id_cuvee = ?)';
        $params[] = (int) $f['id_cuvee'];
    }
    if (!empty($f['statut']) && isset(STATUTS_COMMANDE[$f['statut']])) {
        $sql .= ' AND t.statut = ?';
        $params[] = $f['statut'];
    }

    return [$sql, $params];
}

/**
 * Liste des commandes avec leur total, filtrable (F8).
 *
 * @param array{du?: string, au?: string, id_client?: int, id_cuvee?: int, statut?: string} $f
 */
function commandes_liste(array $f = []): array
{
    [$conditions, $params] = commandes_filtre_sql($f);

    return requete_toutes(
        'SELECT t.id_commande AS id, t.date_commande, t.statut, t.nb_lignes, t.nb_bouteilles, t.total,
                cl.id AS id_client, cl.nom AS client, cl.type_client
         FROM v_commande_total t
         JOIN client cl ON cl.id = t.id_client
         WHERE 1 = 1' . $conditions . '
         ORDER BY t.date_commande DESC, t.id_commande DESC',
        $params
    );
}

/** Une commande complète : en-tête, lignes et mouvements de stock associés. */
function commande_detail(int $id): ?array
{
    $commande = requete_une(
        'SELECT t.id_commande AS id, t.date_commande, t.statut, t.nb_lignes, t.nb_bouteilles, t.total,
                cl.id AS id_client, cl.nom AS client, cl.type_client, cl.email, cl.ville
         FROM v_commande_total t
         JOIN client cl ON cl.id = t.id_client
         WHERE t.id_commande = ?',
        [$id]
    );
    if ($commande === null) {
        return null;
    }

    $commande['lignes'] = requete_toutes(
        'SELECT l.id, l.id_cuvee, cu.nom, cu.millesime, cu.couleur,
                l.quantite, l.prix_unitaire, cu.prix_vente AS prix_actuel,
                l.quantite * l.prix_unitaire AS total_ligne
         FROM ligne_commande l
         JOIN cuvee cu ON cu.id = l.id_cuvee
         WHERE l.id_commande = ?
         ORDER BY cu.nom, cu.millesime',
        [$id]
    );

    $commande['mouvements'] = requete_toutes(
        'SELECT m.id, m.date_mouvement, m.type_mouvement, m.quantite, m.motif, cu.nom, cu.millesime
         FROM mouvement_stock m
         JOIN cuvee cu ON cu.id = m.id_cuvee
         WHERE m.id_commande = ?
         ORDER BY m.date_mouvement, m.id',
        [$id]
    );

    return $commande;
}

/**
 * Valide et enregistre une commande.
 *
 * @param int   $idClient       client choisi
 * @param array $lignesSaisies  lignes telles que saisies : [['id_cuvee' => '3', 'quantite' => '12'], ...]
 * @return int  numéro de la commande créée
 * @throws RegleGestionException si une règle de gestion est violée : AUCUNE écriture n'a eu lieu
 */
function commande_creer(int $idClient, array $lignesSaisies): int
{
    $erreurs = [];

    if ($idClient <= 0 || (int) requete_valeur('SELECT COUNT(*) FROM client WHERE id = ?', [$idClient]) === 0) {
        $erreurs[] = 'Choisissez un client.';
    }
    if (count($lignesSaisies) > MAX_LIGNES_PAR_COMMANDE) {
        throw new RegleGestionException(['Une commande ne peut pas dépasser ' . MAX_LIGNES_PAR_COMMANDE . ' lignes.']);
    }

    // --- 1. Lecture des lignes saisies. Une même cuvée saisie deux fois est fusionnée en une ligne. ---
    $demande = [];            // id de la cuvée => quantité totale demandée
    $lignesRenseignees = 0;
    $numero = 0;
    foreach ($lignesSaisies as $ligne) {
        $numero++;
        // Une ligne mal formée (ce que ne peut pas envoyer notre formulaire, mais un client malveillant le peut) est refusée.
        if (!is_array($ligne) || !is_string($ligne['id_cuvee'] ?? '') || !is_string($ligne['quantite'] ?? '')) {
            $lignesRenseignees++;
            $erreurs[] = "Ligne $numero : saisie invalide.";
            continue;
        }
        $texteCuvee = trim($ligne['id_cuvee'] ?? '');
        $texteQte   = trim($ligne['quantite'] ?? '');
        if ($texteCuvee === '' && $texteQte === '') {
            continue;          // ligne laissée vide : ignorée
        }
        $lignesRenseignees++;

        $idCuvee  = lire_entier($texteCuvee);
        $quantite = lire_entier($texteQte);
        if ($idCuvee === null || $idCuvee <= 0) {
            $erreurs[] = "Ligne $numero : choisissez une cuvée.";
            continue;
        }
        if ($quantite === null || $quantite <= 0) {
            $erreurs[] = "Ligne $numero : la quantité doit être un nombre entier strictement positif.";
            continue;
        }
        if ($quantite > MAX_QUANTITE_PAR_LIGNE) {
            $erreurs[] = "Ligne $numero : la quantité ne peut pas dépasser " . nombre(MAX_QUANTITE_PAR_LIGNE) . '.';
            continue;
        }
        $demande[$idCuvee] = ($demande[$idCuvee] ?? 0) + $quantite;
    }
    if ($lignesRenseignees === 0) {
        $erreurs[] = 'Une commande doit contenir au moins une ligne.';
    }
    if ($erreurs) {
        throw new RegleGestionException($erreurs);
    }

    // Toujours verrouiller les cuvées dans le même ordre : deux commandes simultanées
    // ne peuvent alors pas s'attendre l'une l'autre (pas d'interblocage).
    $ids = array_keys($demande);
    sort($ids);

    return en_transaction(db(), static function (PDO $pdo) use ($idClient, $demande, $ids): int {
        // --- 2. Lecture des cuvées AVEC verrou (FOR UPDATE) : le stock lu ne peut plus changer
        //        avant la fin de la transaction, donc deux commandes ne peuvent pas se partager
        //        la même dernière bouteille. ---
        $marques = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare(
            "SELECT cu.id, cu.nom, cu.millesime, cu.prix_vente, s.quantite AS stock
             FROM stock s
             JOIN cuvee cu ON cu.id = s.id_cuvee
             WHERE s.id_cuvee IN ($marques)
             ORDER BY s.id_cuvee
             FOR UPDATE"
        );
        $st->execute($ids);
        $cuvees = [];
        foreach ($st->fetchAll() as $ligne) {
            $cuvees[(int) $ligne['id']] = $ligne;
        }

        // --- 3. Règle : une commande ne peut pas dépasser le stock disponible. ---
        $erreurs = [];
        foreach ($ids as $idCuvee) {
            if (!isset($cuvees[$idCuvee])) {
                $erreurs[] = "La cuvée n°$idCuvee n'existe pas.";
                continue;
            }
            $disponible = (int) $cuvees[$idCuvee]['stock'];
            if ($demande[$idCuvee] > $disponible) {
                $erreurs[] = sprintf(
                    'Stock insuffisant pour « %s %d » : %s demandée%s, %d disponible%s.',
                    $cuvees[$idCuvee]['nom'],
                    $cuvees[$idCuvee]['millesime'],
                    pluriel($demande[$idCuvee], 'bouteille'),
                    accord($demande[$idCuvee]),
                    $disponible,
                    accord($disponible)
                );
            }
        }
        if ($erreurs) {
            throw new RegleGestionException($erreurs);   // => ROLLBACK : rien n'a été écrit
        }

        // --- 4. Écriture : commande, lignes (prix recopié), baisse du stock, mouvements. ---
        $pdo->prepare('INSERT INTO commande (id_client) VALUES (?)')->execute([$idClient]);
        $idCommande = (int) $pdo->lastInsertId();

        $insererLigne = $pdo->prepare(
            'INSERT INTO ligne_commande (id_commande, id_cuvee, quantite, prix_unitaire) VALUES (?, ?, ?, ?)'
        );
        $baisserStock = $pdo->prepare('UPDATE stock SET quantite = quantite - ? WHERE id_cuvee = ? AND quantite >= ?');
        $insererMouvement = $pdo->prepare(
            'INSERT INTO mouvement_stock (id_cuvee, id_commande, quantite, type_mouvement, motif)
             VALUES (?, ?, ?, \'sortie\', ?)'
        );

        foreach ($ids as $idCuvee) {
            $quantite = $demande[$idCuvee];
            $insererLigne->execute([$idCommande, $idCuvee, $quantite, $cuvees[$idCuvee]['prix_vente']]);

            // Garde-fou : la condition « quantite >= ? » rend impossible un stock négatif,
            // même si le code ci-dessus contenait une erreur.
            $baisserStock->execute([$quantite, $idCuvee, $quantite]);
            if ($baisserStock->rowCount() !== 1) {
                throw new RuntimeException('Mise à jour du stock impossible (incohérence détectée).');
            }
            $insererMouvement->execute([$idCuvee, $idCommande, $quantite, 'Vente commande n°' . $idCommande]);
        }

        return $idCommande;
    });
}

/**
 * Annule une commande validée : le stock est remis (mouvements d'entrée) et le statut passe à « annulée ».
 * La commande n'est jamais supprimée : elle reste dans l'historique.
 *
 * @throws RegleGestionException si la commande n'existe pas ou est déjà annulée
 */
function commande_annuler(int $id): void
{
    en_transaction(db(), static function (PDO $pdo) use ($id): void {
        // Verrou sur la commande : deux annulations simultanées ne peuvent pas remettre le stock deux fois.
        $st = $pdo->prepare('SELECT statut FROM commande WHERE id = ? FOR UPDATE');
        $st->execute([$id]);
        $statut = $st->fetchColumn();

        if ($statut === false) {
            throw new RegleGestionException(['Commande introuvable.']);
        }
        if ($statut !== 'validee') {
            throw new RegleGestionException(["La commande n°$id est déjà annulée."]);
        }

        $st = $pdo->prepare('SELECT id_cuvee, quantite FROM ligne_commande WHERE id_commande = ? ORDER BY id_cuvee');
        $st->execute([$id]);
        $lignes = $st->fetchAll();

        $verrouiller = $pdo->prepare('SELECT quantite FROM stock WHERE id_cuvee = ? FOR UPDATE');
        $remettre = $pdo->prepare('UPDATE stock SET quantite = quantite + ? WHERE id_cuvee = ?');
        $journal = $pdo->prepare(
            'INSERT INTO mouvement_stock (id_cuvee, id_commande, quantite, type_mouvement, motif)
             VALUES (?, ?, ?, \'entree\', ?)'
        );
        foreach ($lignes as $ligne) {
            $verrouiller->execute([$ligne['id_cuvee']]);
            $remettre->execute([$ligne['quantite'], $ligne['id_cuvee']]);
            $journal->execute([$ligne['id_cuvee'], $id, $ligne['quantite'], 'Annulation commande n°' . $id]);
        }

        $pdo->prepare('UPDATE commande SET statut = \'annulee\' WHERE id = ?')->execute([$id]);
    });
}
