-- =====================================================================
--  GESTION DE CAVE : vues SQL
--  Fichier 2/3 : les vues utilisées par l'application et le tableau de bord
--  (une vue = une requête enregistrée que l'on interroge comme une table)
-- =====================================================================

USE gestion_cave;
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
--  v_commande_total : une ligne par commande, avec son total calculé
--  Le total n'est jamais stocké, donc il ne peut jamais être faux.
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW v_commande_total AS
SELECT  c.id                                        AS id_commande,
        c.id_client,
        c.date_commande,
        c.statut,
        COUNT(l.id)                                 AS nb_lignes,
        COALESCE(SUM(l.quantite), 0)                AS nb_bouteilles,
        COALESCE(SUM(l.quantite * l.prix_unitaire), 0) AS total
FROM    commande c
LEFT JOIN ligne_commande l ON l.id_commande = c.id
GROUP BY c.id, c.id_client, c.date_commande, c.statut;

-- ---------------------------------------------------------------------
--  v_ca_par_cuvee : chiffre d'affaires et bouteilles vendues par cuvée
--  Seules les commandes validées comptent (une commande annulée est ignorée).
--  LEFT JOIN : une cuvée jamais vendue apparaît quand même, avec 0.
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW v_ca_par_cuvee AS
SELECT  cu.id                                       AS id_cuvee,
        cu.nom,
        cu.millesime,
        cu.couleur,
        COALESCE(SUM(CASE WHEN co.statut = 'validee' THEN l.quantite END), 0) AS bouteilles_vendues,
        COALESCE(SUM(CASE WHEN co.statut = 'validee' THEN l.quantite * l.prix_unitaire END), 0) AS chiffre_affaires
FROM    cuvee cu
LEFT JOIN ligne_commande l ON l.id_cuvee    = cu.id
LEFT JOIN commande       co ON co.id        = l.id_commande
GROUP BY cu.id, cu.nom, cu.millesime, cu.couleur;

-- ---------------------------------------------------------------------
--  v_stock_bas : les cuvées dont le stock est à ou sous le seuil d'alerte
--  niveau = 'rupture' quand il ne reste plus rien, 'bas' sinon.
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW v_stock_bas AS
SELECT  cu.id                                       AS id_cuvee,
        cu.nom,
        cu.millesime,
        cu.couleur,
        s.quantite,
        s.seuil_alerte,
        CASE WHEN s.quantite = 0 THEN 'rupture' ELSE 'bas' END AS niveau
FROM    stock s
JOIN    cuvee cu ON cu.id = s.id_cuvee
WHERE   s.quantite <= s.seuil_alerte;

-- ---------------------------------------------------------------------
--  v_controle_stock : contrôle d'intégrité
--  Compare le stock enregistré à celui que l'on recalcule à partir du
--  journal des mouvements (entrées - sorties). L'écart doit toujours être 0.
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW v_controle_stock AS
SELECT  cu.id                                       AS id_cuvee,
        cu.nom,
        cu.millesime,
        s.quantite                                  AS stock_enregistre,
        COALESCE(SUM(CASE m.type_mouvement WHEN 'entree' THEN m.quantite
                                           WHEN 'sortie' THEN -m.quantite END), 0) AS stock_recalcule,
        s.quantite - COALESCE(SUM(CASE m.type_mouvement WHEN 'entree' THEN m.quantite
                                                        WHEN 'sortie' THEN -m.quantite END), 0) AS ecart
FROM    cuvee cu
JOIN    stock s ON s.id_cuvee = cu.id
LEFT JOIN mouvement_stock m ON m.id_cuvee = cu.id
GROUP BY cu.id, cu.nom, cu.millesime, s.quantite;
