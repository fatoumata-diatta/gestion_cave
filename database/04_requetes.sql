-- =====================================================================
--  GESTION DE CAVE : requêtes d'analyse (jointures + agrégats)
--  À exécuter à la main dans phpMyAdmin (onglet SQL) pendant la démo.
--  Chaque requête est expliquée dans docs/EXPLICATIONS.md.
-- =====================================================================

USE gestion_cave;

-- ---------------------------------------------------------------------
-- R1. Chiffre d'affaires et bouteilles vendues par cuvée     (VUE SQL)
--     Tables : cuvee, ligne_commande, commande (via la vue v_ca_par_cuvee)
--     Jointures : 2 LEFT JOIN     Agrégats : SUM + CASE WHEN
--     Seules les commandes « validee » comptent.
-- ---------------------------------------------------------------------
SELECT  nom, millesime, couleur, bouteilles_vendues, chiffre_affaires
FROM    v_ca_par_cuvee
ORDER BY chiffre_affaires DESC;

-- ---------------------------------------------------------------------
-- R2. Les 5 meilleurs clients (chiffre d'affaires des commandes validées)
--     Tables : client, commande, ligne_commande
--     Jointures : 2 JOIN     Agrégats : COUNT(DISTINCT), SUM     Tri + LIMIT
-- ---------------------------------------------------------------------
SELECT  cl.nom,
        cl.type_client,
        cl.ville,
        COUNT(DISTINCT co.id)             AS nb_commandes,
        SUM(l.quantite)                   AS nb_bouteilles,
        SUM(l.quantite * l.prix_unitaire) AS chiffre_affaires
FROM    client cl
JOIN    commande co       ON co.id_client = cl.id AND co.statut = 'validee'
JOIN    ligne_commande l  ON l.id_commande = co.id
GROUP BY cl.id, cl.nom, cl.type_client, cl.ville
ORDER BY chiffre_affaires DESC
LIMIT 5;

-- ---------------------------------------------------------------------
-- R3. Chiffre d'affaires par mois
--     Tables : commande, ligne_commande
--     Jointure : 1 JOIN     Agrégats : COUNT(DISTINCT), SUM     Regroupement sur la date
-- ---------------------------------------------------------------------
SELECT  DATE_FORMAT(co.date_commande, '%Y-%m') AS mois,
        COUNT(DISTINCT co.id)                  AS nb_commandes,
        SUM(l.quantite)                        AS nb_bouteilles,
        SUM(l.quantite * l.prix_unitaire)      AS chiffre_affaires
FROM    commande co
JOIN    ligne_commande l ON l.id_commande = co.id
WHERE   co.statut = 'validee'
GROUP BY DATE_FORMAT(co.date_commande, '%Y-%m')
ORDER BY mois;

-- ---------------------------------------------------------------------
-- R4. Panier moyen : particuliers contre professionnels
--     Tables : v_commande_total (vue), client
--     Jointure : 1 JOIN     Agrégats : COUNT, AVG, SUM
-- ---------------------------------------------------------------------
SELECT  cl.type_client,
        COUNT(*)               AS nb_commandes,
        ROUND(AVG(t.total), 2) AS panier_moyen,
        SUM(t.total)           AS chiffre_affaires
FROM    v_commande_total t
JOIN    client cl ON cl.id = t.id_client
WHERE   t.statut = 'validee'
GROUP BY cl.type_client;

-- ---------------------------------------------------------------------
-- R5. Valeur du stock par couleur (au prix de vente actuel)
--     Tables : stock, cuvee
--     Jointure : 1 JOIN     Agrégats : COUNT, SUM, SUM d'un produit
-- ---------------------------------------------------------------------
SELECT  cu.couleur,
        COUNT(*)                     AS nb_cuvees,
        SUM(s.quantite)              AS bouteilles_en_stock,
        SUM(s.quantite * cu.prix_vente) AS valeur_du_stock
FROM    stock s
JOIN    cuvee cu ON cu.id = s.id_cuvee
GROUP BY cu.couleur
ORDER BY valeur_du_stock DESC;

-- ---------------------------------------------------------------------
-- R6. Cuvées à réapprovisionner : stock bas ou rupture, avec les ventes
--     Tables : v_stock_bas (vue), v_ca_par_cuvee (vue)
--     Jointure : 1 JOIN     Tri : les ruptures d'abord
-- ---------------------------------------------------------------------
SELECT  b.nom, b.millesime, b.quantite, b.seuil_alerte, b.niveau,
        v.bouteilles_vendues
FROM    v_stock_bas b
JOIN    v_ca_par_cuvee v ON v.id_cuvee = b.id_cuvee
ORDER BY b.quantite, b.nom;

-- ---------------------------------------------------------------------
-- R7. Détail d'une commande (exemple : la commande n° 1)
--     Tables : commande, client, ligne_commande, cuvee
--     Jointures : 3 JOIN     Calcul : total de chaque ligne (quantité x prix)
--     Remarque : prix_unitaire est le prix de l'époque, prix_vente le prix actuel.
-- ---------------------------------------------------------------------
SELECT  co.id AS commande, cl.nom AS client, cu.nom AS cuvee, cu.millesime,
        l.quantite, l.prix_unitaire, cu.prix_vente AS prix_actuel,
        l.quantite * l.prix_unitaire AS total_ligne
FROM    commande co
JOIN    client cl          ON cl.id = co.id_client
JOIN    ligne_commande l   ON l.id_commande = co.id
JOIN    cuvee cu           ON cu.id = l.id_cuvee
WHERE   co.id = 1
ORDER BY cu.nom;

-- ---------------------------------------------------------------------
-- R8. Cuvées jamais vendues (aucune commande validée)
--     Tables : cuvee, stock, ligne_commande, commande
--     Jointures : JOIN + 2 LEFT JOIN (anti-jointure : on garde les cuvées SANS vente)
--     Agrégat : COUNT filtré par HAVING
--     Le filtre « commande validée » est écrit dans le ON, pas dans un WHERE : un WHERE
--     éliminerait justement les cuvées sans vente, que l'on cherche à garder.
-- ---------------------------------------------------------------------
SELECT  cu.nom, cu.millesime, s.quantite AS stock, COUNT(co.id) AS nb_ventes
FROM    cuvee cu
JOIN    stock s              ON s.id_cuvee = cu.id
LEFT JOIN ligne_commande l   ON l.id_cuvee = cu.id
LEFT JOIN commande co        ON co.id = l.id_commande AND co.statut = 'validee'
GROUP BY cu.id, cu.nom, cu.millesime, s.quantite
HAVING  COUNT(co.id) = 0
ORDER BY cu.nom;

-- ---------------------------------------------------------------------
-- R9. Contrôle d'intégrité : le stock doit égaler la somme des mouvements
--     Table : v_controle_stock (vue sur stock + mouvement_stock)
--     Résultat attendu : AUCUNE ligne (écart nul pour toutes les cuvées).
-- ---------------------------------------------------------------------
SELECT  *
FROM    v_controle_stock
WHERE   ecart <> 0;

-- ---------------------------------------------------------------------
-- R10. Preuve que l'historique des prix est conservé :
--      lignes de commande dont le prix payé diffère du prix actuel
--      Tables : ligne_commande, cuvee     Jointure : 1 JOIN
-- ---------------------------------------------------------------------
SELECT  cu.nom, cu.millesime,
        l.prix_unitaire AS prix_paye, cu.prix_vente AS prix_actuel,
        COUNT(*)        AS nb_lignes,
        SUM(l.quantite) AS nb_bouteilles
FROM    ligne_commande l
JOIN    cuvee cu ON cu.id = l.id_cuvee
WHERE   l.prix_unitaire <> cu.prix_vente
GROUP BY cu.id, cu.nom, cu.millesime, l.prix_unitaire, cu.prix_vente
ORDER BY cu.nom;
