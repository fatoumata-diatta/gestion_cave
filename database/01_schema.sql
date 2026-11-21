-- =====================================================================
--  GESTION DE CAVE : schéma de la base de données (MariaDB / MySQL)
--  Fichier 1/3 : création de la base, des 6 tables et des contraintes
--
--  ATTENTION : ce script SUPPRIME puis recrée les tables. Le relancer
--  remet la base à zéro (pratique pour réinitialiser la démo).
-- =====================================================================

CREATE DATABASE IF NOT EXISTS gestion_cave
    DEFAULT CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE gestion_cave;
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
--  Nettoyage (ordre inverse des dépendances entre tables)
-- ---------------------------------------------------------------------
DROP VIEW  IF EXISTS v_controle_stock;
DROP VIEW  IF EXISTS v_stock_bas;
DROP VIEW  IF EXISTS v_ca_par_cuvee;
DROP VIEW  IF EXISTS v_commande_total;

DROP TABLE IF EXISTS mouvement_stock;
DROP TABLE IF EXISTS ligne_commande;
DROP TABLE IF EXISTS stock;
DROP TABLE IF EXISTS commande;
DROP TABLE IF EXISTS client;
DROP TABLE IF EXISTS cuvee;

-- ---------------------------------------------------------------------
--  CUVEE : le catalogue des vins du domaine
--  Un même nom peut exister dans plusieurs millésimes, mais pas deux fois
--  le même couple (nom, millésime).
-- ---------------------------------------------------------------------
CREATE TABLE cuvee (
    id          INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    nom         VARCHAR(100)      NOT NULL,
    millesime   SMALLINT UNSIGNED NOT NULL,
    couleur     ENUM('rouge','blanc','rose','effervescent') NOT NULL,
    degre       DECIMAL(3,1)      NOT NULL COMMENT 'Degré d''alcool, en % vol',
    prix_vente  DECIMAL(8,2)      NOT NULL COMMENT 'Prix de vente unitaire actuel, en euros',
    PRIMARY KEY (id),
    UNIQUE KEY uq_cuvee_nom_millesime (nom, millesime),
    CONSTRAINT ck_cuvee_millesime CHECK (millesime BETWEEN 1900 AND 2100),
    CONSTRAINT ck_cuvee_degre     CHECK (degre > 0 AND degre <= 25),
    CONSTRAINT ck_cuvee_prix      CHECK (prix_vente > 0)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
--  STOCK : quantité en bouteilles disponible pour chaque cuvée
--  Relation 1-1 avec CUVEE (UNIQUE sur id_cuvee). Le stock peut tomber à 0
--  mais jamais en dessous (CHECK). Sa valeur est toujours égale à la somme
--  des mouvements : voir la vue v_controle_stock.
-- ---------------------------------------------------------------------
CREATE TABLE stock (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_cuvee      INT UNSIGNED NOT NULL,
    quantite      INT          NOT NULL DEFAULT 0  COMMENT 'Bouteilles disponibles',
    seuil_alerte  INT          NOT NULL DEFAULT 24 COMMENT 'Alerte quand quantite <= seuil',
    PRIMARY KEY (id),
    UNIQUE KEY uq_stock_cuvee (id_cuvee),
    CONSTRAINT fk_stock_cuvee    FOREIGN KEY (id_cuvee) REFERENCES cuvee (id) ON DELETE CASCADE,
    CONSTRAINT ck_stock_quantite CHECK (quantite >= 0),
    CONSTRAINT ck_stock_seuil    CHECK (seuil_alerte >= 0)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
--  CLIENT : particuliers et professionnels
-- ---------------------------------------------------------------------
CREATE TABLE client (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nom          VARCHAR(120) NOT NULL,
    type_client  ENUM('particulier','pro') NOT NULL DEFAULT 'particulier',
    email        VARCHAR(190) NOT NULL,
    ville        VARCHAR(100) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_client_email (email)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
--  COMMANDE : l'en-tête d'une commande (le détail est dans ligne_commande)
--  Le total n'est PAS stocké : il se calcule (vue v_commande_total).
--  Une commande n'est jamais supprimée : elle est annulée.
-- ---------------------------------------------------------------------
CREATE TABLE commande (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_client      INT UNSIGNED NOT NULL,
    date_commande  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    statut         ENUM('validee','annulee') NOT NULL DEFAULT 'validee',
    PRIMARY KEY (id),
    KEY idx_commande_date   (date_commande),
    KEY idx_commande_statut (statut),
    CONSTRAINT fk_commande_client FOREIGN KEY (id_client) REFERENCES client (id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
--  LIGNE_COMMANDE : table d'association entre COMMANDE et CUVEE
--  prix_unitaire est RECOPIÉ depuis cuvee.prix_vente au moment de la
--  commande : si le prix change plus tard, l'historique reste juste.
--  UNIQUE (id_commande, id_cuvee) : une seule ligne par cuvée et par commande.
-- ---------------------------------------------------------------------
CREATE TABLE ligne_commande (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_commande    INT UNSIGNED NOT NULL,
    id_cuvee       INT UNSIGNED NOT NULL,
    quantite       INT          NOT NULL,
    prix_unitaire  DECIMAL(8,2) NOT NULL COMMENT 'Prix au moment de la commande',
    PRIMARY KEY (id),
    UNIQUE KEY uq_ligne_commande_cuvee (id_commande, id_cuvee),
    CONSTRAINT fk_ligne_commande FOREIGN KEY (id_commande) REFERENCES commande (id) ON DELETE CASCADE,
    CONSTRAINT fk_ligne_cuvee    FOREIGN KEY (id_cuvee)    REFERENCES cuvee (id),
    CONSTRAINT ck_ligne_quantite CHECK (quantite > 0),
    CONSTRAINT ck_ligne_prix     CHECK (prix_unitaire > 0)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
--  MOUVEMENT_STOCK : le journal de toutes les variations de stock
--  Une entrée (mise en bouteille, annulation) ajoute des bouteilles, une
--  sortie (vente) en retire. id_commande relie le mouvement à la commande
--  qui l'a provoqué (NULL pour une mise en bouteille).
-- ---------------------------------------------------------------------
CREATE TABLE mouvement_stock (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_cuvee        INT UNSIGNED NOT NULL,
    id_commande     INT UNSIGNED NULL,
    date_mouvement  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    quantite        INT          NOT NULL COMMENT 'Toujours positive ; le sens est donné par type_mouvement',
    type_mouvement  ENUM('entree','sortie') NOT NULL,
    motif           VARCHAR(120) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_mouvement_date (date_mouvement),
    CONSTRAINT fk_mouvement_cuvee    FOREIGN KEY (id_cuvee)    REFERENCES cuvee (id),
    CONSTRAINT fk_mouvement_commande FOREIGN KEY (id_commande) REFERENCES commande (id),
    CONSTRAINT ck_mouvement_quantite CHECK (quantite > 0)
) ENGINE=InnoDB;
