<?php
declare(strict_types=1);

/**
 * F9 : export CSV des commandes, du détail des commandes, du stock, des clients, des cuvées
 * et du journal des mouvements.
 *
 * Chaque export appelle la fonction qui alimente déjà l'écran correspondant (commandes_liste,
 * stock_liste, clients_liste...) : le fichier contient donc exactement ce que l'écran affiche,
 * avec les mêmes filtres. Le format est celui d'Excel en français (voir src/csv.php).
 */

/**
 * Les exports proposés : libellé, description, page de l'écran correspondant et titres de colonnes.
 * Les titres des exports « clients » et « cuvées » sont ceux que l'import reconnaît : ces deux fichiers
 * peuvent être réimportés tels quels (les colonnes de calcul en plus sont simplement ignorées).
 */
function export_types(): array
{
    return [
        'commandes' => [
            'libelle'     => 'Commandes',
            'description' => 'Une ligne par commande, avec son total. Les commandes annulées sont incluses, avec leur statut.',
            'fichier'     => 'commandes',
            'page'        => 'commandes.php',
            'colonnes'    => ['N° commande', 'Date', 'Client', 'Type de client', 'Lignes', 'Bouteilles', 'Total (€)', 'Statut'],
        ],
        'lignes' => [
            'libelle'     => 'Détail des commandes',
            'description' => 'Une ligne par cuvée commandée, avec le prix payé au moment de la commande.',
            'fichier'     => 'detail_commandes',
            'page'        => 'commandes.php',
            'colonnes'    => ['N° commande', 'Date', 'Client', 'Statut', 'Cuvée', 'Millésime', 'Quantité', 'Prix unitaire (€)', 'Total ligne (€)'],
        ],
        'stock' => [
            'libelle'     => 'Stock',
            'description' => 'Une ligne par cuvée : bouteilles disponibles, seuil d\'alerte, état et valeur au prix de vente actuel.',
            'fichier'     => 'stock',
            'page'        => 'stock.php',
            'colonnes'    => ['Cuvée', 'Millésime', 'Couleur', 'Prix de vente (€)', 'Stock', 'Seuil d\'alerte', 'État', 'Valeur du stock (€)'],
        ],
        'clients' => [
            'libelle'     => 'Clients',
            'description' => 'Une ligne par client, avec son nombre de commandes validées et son chiffre d\'affaires.',
            'fichier'     => 'clients',
            'page'        => 'clients.php',
            'colonnes'    => ['Nom', 'Type de client', 'E-mail', 'Ville', 'Commandes validées', 'Chiffre d\'affaires (€)'],
        ],
        'cuvees' => [
            'libelle'     => 'Cuvées',
            'description' => 'Le catalogue : une ligne par cuvée, avec son prix, son stock et son seuil d\'alerte.',
            'fichier'     => 'cuvees',
            'page'        => 'cuvees.php',
            'colonnes'    => ['Nom', 'Millésime', 'Couleur', 'Degré d\'alcool', 'Prix de vente (€)', 'Stock', 'Seuil d\'alerte'],
        ],
        'mouvements' => [
            'libelle'     => 'Mouvements de stock',
            'description' => 'Le journal complet des entrées et des sorties, avec le numéro de la commande qui les a provoquées.',
            'fichier'     => 'mouvements_stock',
            'page'        => 'mouvements.php',
            'colonnes'    => ['Date', 'Cuvée', 'Millésime', 'Type', 'Quantité', 'Motif', 'N° commande'],
        ],
    ];
}

/** Adresse de téléchargement d'un export, avec les filtres de l'écran (mêmes noms de paramètres que l'écran). */
function export_url(string $type, array $filtres = []): string
{
    return url('export_csv.php', ['donnees' => $type] + $filtres);
}

/** Nom du fichier téléchargé : « commandes_2026-11-18.csv ». */
function export_nom_fichier(string $type): string
{
    return (export_types()[$type]['fichier'] ?? $type) . '_' . date('Y-m-d') . '.csv';
}

/**
 * Lit dans l'adresse (GET) les filtres de l'écran correspondant à l'export demandé.
 * Les paramètres sont ceux de l'écran : le bouton « Exporter » les recopie tels quels.
 */
function export_filtres_get(string $type): array
{
    return match ($type) {
        'commandes', 'lignes' => commandes_filtres_get(),
        'stock'               => ['recherche' => champ_get('q'), 'alertes' => champ_get('alertes') === '1'],
        'clients'             => ['recherche' => champ_get('q'), 'type' => champ_get('type')],
        'cuvees'              => ['recherche' => champ_get('q'), 'couleur' => champ_get('couleur')],
        'mouvements'          => ['id_cuvee' => entier_get('id_cuvee'), 'type' => champ_get('type')],
        default               => [],
    };
}

/**
 * Les données d'un export, déjà mises au format des cellules (voir csv_formater).
 *
 * @return array{entetes: list<string>, lignes: list<list<string>>}
 * @throws RegleGestionException si le type d'export n'existe pas
 */
function export_donnees(string $type, array $filtres = []): array
{
    $types = export_types();
    if (!isset($types[$type])) {
        throw new RegleGestionException(['Cet export n\'existe pas.']);
    }

    $lignes = match ($type) {
        'commandes'  => export_lignes_commandes($filtres),
        'lignes'     => export_lignes_detail($filtres),
        'stock'      => export_lignes_stock($filtres),
        'clients'    => export_lignes_clients($filtres),
        'cuvees'     => export_lignes_cuvees($filtres),
        'mouvements' => export_lignes_mouvements($filtres),
    };
    return ['entetes' => $types[$type]['colonnes'], 'lignes' => $lignes];
}

/** Commandes : la même liste, dans le même ordre, que l'écran « Commandes » (F8). */
function export_lignes_commandes(array $filtres): array
{
    $lignes = [];
    foreach (commandes_liste($filtres) as $c) {
        $lignes[] = [
            csv_formater($c['id'], 'entier'),
            csv_formater($c['date_commande']),
            csv_formater($c['client']),
            csv_formater(TYPES_CLIENT[$c['type_client']] ?? $c['type_client']),
            csv_formater($c['nb_lignes'], 'entier'),
            csv_formater($c['nb_bouteilles'], 'entier'),
            csv_formater($c['total'], 'decimal'),
            csv_formater(STATUTS_COMMANDE[$c['statut']] ?? $c['statut']),
        ];
    }
    return $lignes;
}

/**
 * Détail : toutes les lignes des commandes retenues par les filtres.
 * (Filtrer sur une cuvée retient les commandes qui la contiennent, avec toutes leurs lignes.)
 */
function export_lignes_detail(array $filtres): array
{
    [$conditions, $params] = commandes_filtre_sql($filtres);

    $lignes = [];
    $detail = requete_toutes(
        'SELECT t.id_commande AS id, t.date_commande, t.statut, cl.nom AS client,
                cu.nom AS cuvee, cu.millesime, l.quantite, l.prix_unitaire,
                l.quantite * l.prix_unitaire AS total_ligne
         FROM v_commande_total t
         JOIN client cl ON cl.id = t.id_client
         JOIN ligne_commande l ON l.id_commande = t.id_commande
         JOIN cuvee cu ON cu.id = l.id_cuvee
         WHERE 1 = 1' . $conditions . '
         ORDER BY t.date_commande DESC, t.id_commande DESC, cu.nom, cu.millesime',
        $params
    );
    foreach ($detail as $l) {
        $lignes[] = [
            csv_formater($l['id'], 'entier'),
            csv_formater($l['date_commande']),
            csv_formater($l['client']),
            csv_formater(STATUTS_COMMANDE[$l['statut']] ?? $l['statut']),
            csv_formater($l['cuvee']),
            csv_formater($l['millesime'], 'entier'),
            csv_formater($l['quantite'], 'entier'),
            csv_formater($l['prix_unitaire'], 'decimal'),
            csv_formater($l['total_ligne'], 'decimal'),
        ];
    }
    return $lignes;
}

/** Stock : la même liste que l'écran « Stock » (recherche et alertes seulement). */
function export_lignes_stock(array $filtres): array
{
    $etats = ['ok' => 'OK', 'bas' => 'Stock bas', 'rupture' => 'Rupture'];

    $lignes = [];
    foreach (stock_liste((string) ($filtres['recherche'] ?? ''), !empty($filtres['alertes'])) as $s) {
        $niveau = niveau_stock((int) $s['quantite'], (int) $s['seuil_alerte']);
        $lignes[] = [
            csv_formater($s['nom']),
            csv_formater($s['millesime'], 'entier'),
            csv_formater(libelle_couleur((string) $s['couleur'])),
            csv_formater($s['prix_vente'], 'decimal'),
            csv_formater($s['quantite'], 'entier'),
            csv_formater($s['seuil_alerte'], 'entier'),
            csv_formater($etats[$niveau]),
            csv_formater($s['valeur'], 'decimal'),
        ];
    }
    return $lignes;
}

/** Clients : la même liste que l'écran « Clients » (recherche et type). */
function export_lignes_clients(array $filtres): array
{
    $lignes = [];
    foreach (clients_liste((string) ($filtres['recherche'] ?? ''), (string) ($filtres['type'] ?? '')) as $c) {
        $lignes[] = [
            csv_formater($c['nom']),
            csv_formater(TYPES_CLIENT[$c['type_client']] ?? $c['type_client']),
            csv_formater($c['email']),
            csv_formater($c['ville']),
            csv_formater($c['nb_commandes'], 'entier'),
            csv_formater($c['ca'], 'decimal'),
        ];
    }
    return $lignes;
}

/** Cuvées : la même liste que l'écran « Cuvées » (recherche et couleur). */
function export_lignes_cuvees(array $filtres): array
{
    $lignes = [];
    foreach (cuvees_liste((string) ($filtres['recherche'] ?? ''), (string) ($filtres['couleur'] ?? '')) as $c) {
        $lignes[] = [
            csv_formater($c['nom']),
            csv_formater($c['millesime'], 'entier'),
            csv_formater(libelle_couleur((string) $c['couleur'])),
            csv_formater($c['degre'], 'degre'),
            csv_formater($c['prix_vente'], 'decimal'),
            csv_formater($c['stock'], 'entier'),
            csv_formater($c['seuil_alerte'], 'entier'),
        ];
    }
    return $lignes;
}

/** Mouvements : le même journal que l'écran « Mouvements », mais sans la limite d'affichage de 200 lignes. */
function export_lignes_mouvements(array $filtres): array
{
    $types = ['entree' => 'Entrée', 'sortie' => 'Sortie'];

    $lignes = [];
    foreach (mouvements_liste(['id_cuvee' => $filtres['id_cuvee'] ?? 0, 'type' => $filtres['type'] ?? ''], 1000000) as $m) {
        $lignes[] = [
            csv_formater($m['date_mouvement']),
            csv_formater($m['nom']),
            csv_formater($m['millesime'], 'entier'),
            csv_formater($types[$m['type_mouvement']] ?? $m['type_mouvement']),
            csv_formater($m['quantite'], 'entier'),
            csv_formater($m['motif']),
            csv_formater($m['id_commande'], 'entier'),
        ];
    }
    return $lignes;
}
