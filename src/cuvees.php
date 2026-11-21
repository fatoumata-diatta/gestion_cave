<?php
declare(strict_types=1);

/**
 * F1 : gestion des cuvées (ajouter, modifier, supprimer).
 */

/** Liste des cuvées avec leur stock, filtrable par texte (nom ou millésime) et par couleur. */
function cuvees_liste(string $recherche = '', string $couleur = ''): array
{
    $sql = 'SELECT cu.id, cu.nom, cu.millesime, cu.couleur, cu.degre, cu.prix_vente,
                   s.quantite AS stock, s.seuil_alerte
            FROM cuvee cu
            JOIN stock s ON s.id_cuvee = cu.id
            WHERE 1 = 1';
    $params = [];

    if ($recherche !== '') {
        $motif = '%' . echapper_like($recherche) . '%';
        $sql .= ' AND (cu.nom LIKE ? OR CAST(cu.millesime AS CHAR) LIKE ?)';
        array_push($params, $motif, $motif);
    }
    if ($couleur !== '' && isset(COULEURS[$couleur])) {
        $sql .= ' AND cu.couleur = ?';
        $params[] = $couleur;
    }

    return requete_toutes($sql . ' ORDER BY cu.nom, cu.millesime', $params);
}

function cuvee_trouver(int $id): ?array
{
    return requete_une(
        'SELECT cu.id, cu.nom, cu.millesime, cu.couleur, cu.degre, cu.prix_vente,
                s.quantite AS stock, s.seuil_alerte
         FROM cuvee cu JOIN stock s ON s.id_cuvee = cu.id
         WHERE cu.id = ?',
        [$id]
    );
}

/**
 * Valide les champs saisis pour une cuvée.
 *
 * @return array{0: array<string,mixed>, 1: array<string,string>} [données nettoyées, erreurs par champ]
 */
function cuvee_valider(array $saisie): array
{
    $erreurs = [];
    $lire = static fn(string $cle): string => is_string($saisie[$cle] ?? null) ? $saisie[$cle] : '';

    $nom = texte_propre($lire('nom'));
    if ($nom === '') {
        $erreurs['nom'] = 'Le nom est obligatoire.';
    } elseif (mb_strlen($nom) > 100) {
        $erreurs['nom'] = 'Le nom ne doit pas dépasser 100 caractères.';
    }

    $anneeMax = (int) date('Y') + 1;
    $millesime = lire_entier($lire('millesime'));
    if ($millesime === null || $millesime < 1900 || $millesime > $anneeMax) {
        $erreurs['millesime'] = "Le millésime doit être une année comprise entre 1900 et $anneeMax.";
    }

    $couleur = $lire('couleur');
    if (!isset(COULEURS[$couleur])) {
        $erreurs['couleur'] = 'Choisissez une couleur.';
    }

    $degre = lire_decimal($lire('degre'));
    if ($degre === null || $degre <= 0 || $degre > 25) {
        $erreurs['degre'] = 'Le degré d\'alcool doit être un nombre compris entre 0,1 et 25.';
    }

    $prix = lire_decimal($lire('prix_vente'));
    if ($prix === null || $prix <= 0 || $prix > 99999.99) {
        $erreurs['prix_vente'] = 'Le prix doit être un montant strictement positif (99 999,99 € maximum).';
    }

    $texteSeuil = $lire('seuil_alerte') === '' ? '24' : $lire('seuil_alerte');
    $seuil = lire_entier($texteSeuil);
    if ($seuil === null || $seuil > 100000) {
        $erreurs['seuil_alerte'] = 'Le seuil d\'alerte doit être un entier positif ou nul.';
    }

    $texteStock = $lire('stock_initial') === '' ? '0' : $lire('stock_initial');
    $stockInitial = lire_entier($texteStock);
    if ($stockInitial === null || $stockInitial > 100000) {
        $erreurs['stock_initial'] = 'Le stock initial doit être un entier positif ou nul.';
    }

    $donnees = [
        'nom'           => $nom,
        'millesime'     => $millesime ?? 0,
        'couleur'       => $couleur,
        'degre'         => $degre !== null ? round($degre, 1) : 0.0,
        'prix_vente'    => $prix !== null ? round($prix, 2) : 0.0,
        'seuil_alerte'  => $seuil ?? 24,
        'stock_initial' => $stockInitial ?? 0,
    ];

    return [$donnees, $erreurs];
}

/**
 * Crée une cuvée avec sa ligne de stock. Si un stock initial est donné, une entrée
 * « Mise en bouteille » est aussi enregistrée dans le journal des mouvements.
 * Tout se passe dans une seule transaction.
 *
 * @throws RegleGestionException si la cuvée existe déjà pour ce millésime
 */
function cuvee_creer(array $d): int
{
    try {
        return en_transaction(db(), static function (PDO $pdo) use ($d): int {
            $pdo->prepare('INSERT INTO cuvee (nom, millesime, couleur, degre, prix_vente) VALUES (?, ?, ?, ?, ?)')
                ->execute([$d['nom'], $d['millesime'], $d['couleur'], decimal_sql($d['degre'], 1), decimal_sql($d['prix_vente'])]);
            $id = (int) $pdo->lastInsertId();

            $pdo->prepare('INSERT INTO stock (id_cuvee, quantite, seuil_alerte) VALUES (?, ?, ?)')
                ->execute([$id, $d['stock_initial'], $d['seuil_alerte']]);

            if ($d['stock_initial'] > 0) {
                $pdo->prepare('INSERT INTO mouvement_stock (id_cuvee, quantite, type_mouvement, motif) VALUES (?, ?, \'entree\', ?)')
                    ->execute([$id, $d['stock_initial'], 'Mise en bouteille']);
            }
            return $id;
        });
    } catch (PDOException $erreur) {
        if (est_doublon($erreur)) {
            throw new RegleGestionException(['Cette cuvée existe déjà pour ce millésime.']);
        }
        throw $erreur;
    }
}

/**
 * Modifie une cuvée et son seuil d'alerte. Le stock n'est pas touché ici (voir stock_entree()).
 * Les commandes déjà passées gardent leur ancien prix : il est recopié dans chaque ligne.
 *
 * @throws RegleGestionException
 */
function cuvee_modifier(int $id, array $d): void
{
    try {
        en_transaction(db(), static function (PDO $pdo) use ($id, $d): void {
            $st = $pdo->prepare('UPDATE cuvee SET nom = ?, millesime = ?, couleur = ?, degre = ?, prix_vente = ? WHERE id = ?');
            $st->execute([$d['nom'], $d['millesime'], $d['couleur'], decimal_sql($d['degre'], 1), decimal_sql($d['prix_vente']), $id]);
            $pdo->prepare('UPDATE stock SET seuil_alerte = ? WHERE id_cuvee = ?')->execute([$d['seuil_alerte'], $id]);
        });
    } catch (PDOException $erreur) {
        if (est_doublon($erreur)) {
            throw new RegleGestionException(['Une autre cuvée porte déjà ce nom pour ce millésime.']);
        }
        throw $erreur;
    }
}

/**
 * Supprime une cuvée, mais seulement si elle n'a aucun historique.
 * Une cuvée déjà commandée ou déjà passée en stock reste en base : sinon l'historique
 * des commandes et des mouvements deviendrait faux.
 *
 * @throws RegleGestionException
 */
function cuvee_supprimer(int $id): void
{
    $cuvee = cuvee_trouver($id);
    if ($cuvee === null) {
        throw new RegleGestionException(['Cuvée introuvable.']);
    }

    $nbLignes = (int) requete_valeur('SELECT COUNT(*) FROM ligne_commande WHERE id_cuvee = ?', [$id]);
    $nbMouvements = (int) requete_valeur('SELECT COUNT(*) FROM mouvement_stock WHERE id_cuvee = ?', [$id]);
    if ($nbLignes > 0 || $nbMouvements > 0 || (int) $cuvee['stock'] > 0) {
        throw new RegleGestionException([sprintf(
            'Suppression impossible : « %s %d » a un historique (%s de commande, %s de stock). '
            . 'L\'historique doit rester intact.',
            $cuvee['nom'],
            $cuvee['millesime'],
            pluriel($nbLignes, 'ligne'),
            pluriel($nbMouvements, 'mouvement')
        )]);
    }

    try {
        // La ligne de stock est supprimée automatiquement (ON DELETE CASCADE).
        requete_executer('DELETE FROM cuvee WHERE id = ?', [$id]);
    } catch (PDOException $erreur) {
        if (est_cle_etrangere($erreur)) {
            throw new RegleGestionException(['Suppression impossible : cette cuvée vient d\'être utilisée.']);
        }
        throw $erreur;
    }
}
