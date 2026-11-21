<?php
declare(strict_types=1);

/**
 * F6 : tableau de bord. Chaque fonction correspond à une requête SQL
 * documentée dans database/04_requetes.sql et expliquée dans docs/EXPLICATIONS.md.
 */

/** Indicateurs clés : chiffre d'affaires, commandes, panier moyen, stock. */
function dashboard_kpis(): array
{
    $ventes = requete_une(
        "SELECT COUNT(*)                       AS nb_commandes,
                COALESCE(SUM(total), 0)        AS ca,
                COALESCE(SUM(nb_bouteilles), 0) AS bouteilles,
                COALESCE(AVG(total), 0)        AS panier_moyen
         FROM v_commande_total
         WHERE statut = 'validee'"
    ) ?? [];

    $stock = requete_une(
        'SELECT COALESCE(SUM(s.quantite), 0)                AS bouteilles,
                COALESCE(SUM(s.quantite * cu.prix_vente), 0) AS valeur
         FROM stock s
         JOIN cuvee cu ON cu.id = s.id_cuvee'
    ) ?? [];

    $alertes = requete_une(
        "SELECT COUNT(*) AS nb, COALESCE(SUM(niveau = 'rupture'), 0) AS ruptures FROM v_stock_bas"
    ) ?? [];

    return [
        'ca'              => (float) ($ventes['ca'] ?? 0),
        'nb_commandes'    => (int) ($ventes['nb_commandes'] ?? 0),
        'bouteilles'      => (int) ($ventes['bouteilles'] ?? 0),
        'panier_moyen'    => (float) ($ventes['panier_moyen'] ?? 0),
        'nb_annulees'     => (int) requete_valeur("SELECT COUNT(*) FROM commande WHERE statut = 'annulee'"),
        'stock_bouteilles' => (int) ($stock['bouteilles'] ?? 0),
        'stock_valeur'    => (float) ($stock['valeur'] ?? 0),
        'nb_alertes'      => (int) ($alertes['nb'] ?? 0),
        'nb_ruptures'     => (int) ($alertes['ruptures'] ?? 0),
    ];
}

/** Chiffre d'affaires par cuvée (vue v_ca_par_cuvee), du plus élevé au plus faible. */
function dashboard_ca_par_cuvee(): array
{
    return requete_toutes(
        'SELECT id_cuvee, nom, millesime, couleur, bouteilles_vendues, chiffre_affaires
         FROM v_ca_par_cuvee
         ORDER BY chiffre_affaires DESC, nom'
    );
}

/** Les meilleurs clients (commandes validées uniquement). */
function dashboard_meilleurs_clients(int $limite = 5): array
{
    return requete_toutes(
        'SELECT cl.id, cl.nom, cl.type_client, cl.ville,
                COUNT(DISTINCT co.id)             AS nb_commandes,
                SUM(l.quantite)                   AS nb_bouteilles,
                SUM(l.quantite * l.prix_unitaire) AS chiffre_affaires
         FROM client cl
         JOIN commande co      ON co.id_client = cl.id AND co.statut = \'validee\'
         JOIN ligne_commande l ON l.id_commande = co.id
         GROUP BY cl.id, cl.nom, cl.type_client, cl.ville
         ORDER BY chiffre_affaires DESC, cl.nom
         LIMIT ' . max(1, $limite)
    );
}

/** Cuvées à ou sous leur seuil d'alerte (vue v_stock_bas), les ruptures d'abord. */
function dashboard_stock_bas(): array
{
    return requete_toutes(
        'SELECT id_cuvee, nom, millesime, couleur, quantite, seuil_alerte, niveau
         FROM v_stock_bas
         ORDER BY quantite, nom'
    );
}

/** Chiffre d'affaires par mois, sur les 12 derniers mois d'activité. */
function dashboard_ca_mensuel(): array
{
    $lignes = requete_toutes(
        "SELECT DATE_FORMAT(co.date_commande, '%Y-%m') AS mois,
                COUNT(DISTINCT co.id)                  AS nb_commandes,
                SUM(l.quantite * l.prix_unitaire)      AS chiffre_affaires
         FROM commande co
         JOIN ligne_commande l ON l.id_commande = co.id
         WHERE co.statut = 'validee'
         GROUP BY DATE_FORMAT(co.date_commande, '%Y-%m')
         ORDER BY mois DESC
         LIMIT 12"
    );
    return array_reverse($lignes);
}

/** Panier moyen et chiffre d'affaires selon le type de client. */
function dashboard_par_type_client(): array
{
    return requete_toutes(
        "SELECT cl.type_client,
                COUNT(*)               AS nb_commandes,
                AVG(t.total)           AS panier_moyen,
                SUM(t.total)           AS chiffre_affaires
         FROM v_commande_total t
         JOIN client cl ON cl.id = t.id_client
         WHERE t.statut = 'validee'
         GROUP BY cl.type_client
         ORDER BY chiffre_affaires DESC"
    );
}

/** Contrôle d'intégrité : nombre de cuvées contrôlées et nombre d'écarts (doit être 0). */
function dashboard_integrite(): array
{
    $r = requete_une(
        'SELECT COUNT(*) AS nb_cuvees, COALESCE(SUM(ecart <> 0), 0) AS nb_ecarts FROM v_controle_stock'
    ) ?? [];
    return ['nb_cuvees' => (int) ($r['nb_cuvees'] ?? 0), 'nb_ecarts' => (int) ($r['nb_ecarts'] ?? 0)];
}
