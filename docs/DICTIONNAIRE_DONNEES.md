# Dictionnaire de données

Ce document décrit chaque table et chaque colonne de la base `gestion_cave`. Les types, valeurs par défaut et clés sont lus dans la base elle-même (`information_schema`) ; ils correspondent à `database/01_schema.sql`.

Légende : **PK** = clé primaire, **FK** = clé étrangère (la cible est indiquée), **UQ** = valeur unique. « Oblig. » = la colonne ne peut pas rester vide.

| Table | Rôle | Lignes de démonstration |
|---|---|---|
| [`cuvee`](#cuvee) | Le catalogue des vins du domaine : une ligne par vin et par millésime. | 20 |
| [`stock`](#stock) | Le nombre de bouteilles disponibles pour chaque cuvée (relation 1-1 avec CUVEE). | 20 |
| [`client`](#client) | Les clients du domaine : particuliers et professionnels (restaurants, cavistes, hôtels). | 15 |
| [`commande`](#commande) | L'en-tête d'une commande : qui, quand, et dans quel état. Le détail est dans LIGNE_COMMANDE. | 50 |
| [`ligne_commande`](#ligne_commande) | Une ligne = une cuvée commandée, en quantité, à un prix. Table d'association entre COMMANDE et CUVEE. | 147 |
| [`mouvement_stock`](#mouvement_stock) | Le journal de toutes les variations de stock : mises en bouteille, ventes, annulations. | 184 |

## cuvee

**CUVEE.** Le catalogue des vins du domaine : une ligne par vin et par millésime.

| Colonne | Type | Oblig. | Défaut | Clé | Description | Règle |
|---|---|---|---|---|---|---|
| `id` | `INT UNSIGNED` | oui | auto | PK | Identifiant de la cuvée (numéro automatique). | — |
| `nom` | `VARCHAR(100)` | oui | — | — | Nom commercial de la cuvée. | — |
| `millesime` | `SMALLINT UNSIGNED` | oui | — | — | Année de récolte. | Entre 1900 et 2100 (`ck_cuvee_millesime`). |
| `couleur` | `ENUM('rouge','blanc','rose','effervescent')` | oui | — | — | Couleur du vin. | Une valeur parmi : rouge, blanc, rose, effervescent. |
| `degre` | `DECIMAL(3,1)` | oui | — | — | Degré d'alcool, en % vol. | Strictement positif et 25 au plus (`ck_cuvee_degre`). |
| `prix_vente` | `DECIMAL(8,2)` | oui | — | — | Prix de vente actuel d'une bouteille, en euros. | Strictement positif (`ck_cuvee_prix`). Ce prix est **recopié** dans chaque ligne de commande. |

- `UNIQUE (nom, millesime)` : une même cuvée ne peut pas exister deux fois pour le même millésime (le même nom peut exister dans plusieurs millésimes).

## stock

**STOCK.** Le nombre de bouteilles disponibles pour chaque cuvée (relation 1-1 avec CUVEE).

| Colonne | Type | Oblig. | Défaut | Clé | Description | Règle |
|---|---|---|---|---|---|---|
| `id` | `INT UNSIGNED` | oui | auto | PK | Identifiant de la ligne de stock. | — |
| `id_cuvee` | `INT UNSIGNED` | oui | — | FK → `cuvee.id`, UQ | La cuvée concernée. | Clé étrangère vers `cuvee`, **unique** : une cuvée a un seul stock. Supprimée avec la cuvée (`ON DELETE CASCADE`). |
| `quantite` | `INT` | oui | `0` | — | Bouteilles disponibles. | Jamais négative (`ck_stock_quantite`) : le SGBD lui-même interdit un stock négatif. |
| `seuil_alerte` | `INT` | oui | `24` | — | Seuil d'alerte, en bouteilles. | Positif ou nul (`ck_stock_seuil`). Il y a alerte quand `quantite <= seuil_alerte` ; rupture quand `quantite = 0`. |

- `UNIQUE (id_cuvee)` : traduit la cardinalité 1-1 entre CUVEE et STOCK.
- La valeur de `quantite` est toujours égale à la somme des mouvements (entrées moins sorties) : la vue `v_controle_stock` le vérifie.

## client

**CLIENT.** Les clients du domaine : particuliers et professionnels (restaurants, cavistes, hôtels).

| Colonne | Type | Oblig. | Défaut | Clé | Description | Règle |
|---|---|---|---|---|---|---|
| `id` | `INT UNSIGNED` | oui | auto | PK | Identifiant du client. | — |
| `nom` | `VARCHAR(120)` | oui | — | — | Nom de la personne ou raison sociale. | — |
| `type_client` | `ENUM('particulier','pro')` | oui | `'particulier'` | — | Particulier ou professionnel. | Une valeur parmi : particulier, pro. Vaut `particulier` par défaut. |
| `email` | `VARCHAR(190)` | oui | — | UQ | Adresse e-mail. | **Unique** (`uq_client_email`) : sert à repérer un doublon. |
| `ville` | `VARCHAR(100)` | oui | — | — | Ville du client. | — |

## commande

**COMMANDE.** L'en-tête d'une commande : qui, quand, et dans quel état. Le détail est dans LIGNE_COMMANDE.

| Colonne | Type | Oblig. | Défaut | Clé | Description | Règle |
|---|---|---|---|---|---|---|
| `id` | `INT UNSIGNED` | oui | auto | PK | Numéro de commande. | Numéro automatique. Le stock est vérifié avant toute écriture : une commande refusée ne consomme pas de numéro. |
| `id_client` | `INT UNSIGNED` | oui | — | FK → `client.id` | Le client qui commande. | Clé étrangère vers `client` : impossible de supprimer un client qui a des commandes. |
| `date_commande` | `DATETIME` | oui | `CURRENT_TIMESTAMP` | — | Date et heure de la validation. | Remplie automatiquement par le SGBD (`CURRENT_TIMESTAMP`). |
| `statut` | `ENUM('validee','annulee')` | oui | `'validee'` | — | État de la commande. | Une valeur parmi : validee, annulee. Une commande n'est jamais supprimée : elle est annulée. |

- Le **total** n'est pas stocké : il se calcule (vue `v_commande_total`), donc il ne peut jamais être faux.
- Index sur `date_commande` et `statut` : accélèrent les filtres et le tableau de bord.

## ligne_commande

**LIGNE_COMMANDE.** Une ligne = une cuvée commandée, en quantité, à un prix. Table d'association entre COMMANDE et CUVEE.

| Colonne | Type | Oblig. | Défaut | Clé | Description | Règle |
|---|---|---|---|---|---|---|
| `id` | `INT UNSIGNED` | oui | auto | PK | Identifiant de la ligne. | — |
| `id_commande` | `INT UNSIGNED` | oui | — | FK → `commande.id` | La commande à laquelle appartient la ligne. | Clé étrangère vers `commande` (`ON DELETE CASCADE`). |
| `id_cuvee` | `INT UNSIGNED` | oui | — | FK → `cuvee.id` | La cuvée commandée. | Clé étrangère vers `cuvee` : impossible de supprimer une cuvée déjà vendue. |
| `quantite` | `INT` | oui | — | — | Nombre de bouteilles commandées. | Strictement positive (`ck_ligne_quantite`). |
| `prix_unitaire` | `DECIMAL(8,2)` | oui | — | — | Prix d'une bouteille **au moment de la commande**, en euros. | Strictement positif (`ck_ligne_prix`). Recopié depuis `cuvee.prix_vente` : un changement de tarif ne modifie pas l'historique. |

- `UNIQUE (id_commande, id_cuvee)` : une seule ligne par cuvée dans une commande (une cuvée saisie deux fois est fusionnée).
- Total d'une ligne = `quantite × prix_unitaire` (calculé, jamais stocké).

## mouvement_stock

**MOUVEMENT_STOCK.** Le journal de toutes les variations de stock : mises en bouteille, ventes, annulations.

| Colonne | Type | Oblig. | Défaut | Clé | Description | Règle |
|---|---|---|---|---|---|---|
| `id` | `INT UNSIGNED` | oui | auto | PK | Identifiant du mouvement. | — |
| `id_cuvee` | `INT UNSIGNED` | oui | — | FK → `cuvee.id` | La cuvée dont le stock varie. | Clé étrangère vers `cuvee`. |
| `id_commande` | `INT UNSIGNED` | non | — | FK → `commande.id` | La commande qui a provoqué le mouvement. | Clé étrangère vers `commande`. **NULL** pour une entrée manuelle (mise en bouteille). |
| `date_mouvement` | `DATETIME` | oui | `CURRENT_TIMESTAMP` | — | Date et heure du mouvement. | Remplie automatiquement par le SGBD. |
| `quantite` | `INT` | oui | — | — | Nombre de bouteilles qui entrent ou sortent. | Toujours strictement positive (`ck_mouvement_quantite`) : le sens est donné par `type_mouvement`. |
| `type_mouvement` | `ENUM('entree','sortie')` | oui | — | — | Sens du mouvement. | Une valeur parmi : entree, sortie. |
| `motif` | `VARCHAR(120)` | oui | — | — | Raison du mouvement, en clair. | Exemples : « Mise en bouteille », « Vente commande n°51 », « Annulation commande n°51 ». |

- Une vente écrit une **sortie** par cuvée ; une annulation de commande écrit les **entrées** correspondantes ; une mise en bouteille écrit une **entrée** sans commande.
- Index sur `date_mouvement` : accélère l'affichage du journal.

## Les vues

Une vue est une requête enregistrée que l'on interroge comme une table. Elle ne stocke aucune donnée : elle est recalculée à chaque lecture, donc toujours à jour.

### v_commande_total

Une ligne par commande, avec son nombre de lignes, son nombre de bouteilles et son **total calculé**.

Colonnes : `id_commande`, `id_client`, `date_commande`, `statut`, `nb_lignes`, `nb_bouteilles`, `total`.

Utilisée par : Tableau de bord, liste et fiche des commandes, requête R4.

### v_ca_par_cuvee

Bouteilles vendues et chiffre d'affaires de chaque cuvée, commandes **validées** uniquement. Une cuvée jamais vendue apparaît avec 0.

Colonnes : `id_cuvee`, `nom`, `millesime`, `couleur`, `bouteilles_vendues`, `chiffre_affaires`.

Utilisée par : Tableau de bord (classement par cuvée), requêtes R1 et R6.

### v_stock_bas

Les cuvées dont le stock est à ou sous le seuil d'alerte ; `niveau` vaut `rupture` à 0, `bas` sinon.

Colonnes : `id_cuvee`, `nom`, `millesime`, `couleur`, `quantite`, `seuil_alerte`, `niveau`.

Utilisée par : Tableau de bord (cuvées à réapprovisionner), requête R6.

### v_controle_stock

Compare le stock enregistré au stock recalculé à partir du journal des mouvements ; `ecart` doit être 0 partout.

Colonnes : `id_cuvee`, `nom`, `millesime`, `stock_enregistre`, `stock_recalcule`, `ecart`.

Utilisée par : Bandeau de contrôle du tableau de bord, page `verif.php`, requête R9.

## Intégrité référentielle : ce que la base interdit elle-même

| Action | Résultat | Pourquoi |
|---|---|---|
| Supprimer un client qui a des commandes | Refusé | `commande.id_client` → `client` sans cascade |
| Supprimer une cuvée qui a une ligne de commande ou un mouvement de stock | Refusé | `ligne_commande.id_cuvee` et `mouvement_stock.id_cuvee` → `cuvee` sans cascade |
| Supprimer une cuvée sans aucun historique | Accepté ; sa ligne de stock disparaît avec elle | `stock.id_cuvee` → `cuvee` en `ON DELETE CASCADE` |
| Écrire un stock négatif, une quantité ≤ 0 ou un prix ≤ 0 | Refusé | contraintes `CHECK` (`ck_*`) |
| Créer deux cuvées de même nom et même millésime, deux clients de même e-mail | Refusé | contraintes `UNIQUE` |

L'application vérifie aussi ces règles avant d'écrire et affiche un message clair (elle refuse en plus de supprimer une cuvée dont le stock n'est pas nul) ; les contraintes de la base sont le dernier filet de sécurité, au cas où une erreur de code laisserait passer une valeur incorrecte.

