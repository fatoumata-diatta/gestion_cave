# Explications pour l'oral

Ce document permet d'expliquer **chaque table, chaque vue et chaque requête**, et de justifier les choix techniques. Les chiffres cités sont ceux des données de démonstration (`database/03_donnees_test.sql`).

Sommaire : [1. Le projet en 30 secondes](#1-le-projet-en-30-secondes) · [2. L'architecture](#2-larchitecture) · [3. Les 6 tables](#3-les-6-tables) · [4. Les 4 vues](#4-les-4-vues) · [5. Les 10 requêtes](#5-les-10-requêtes) · [6. Valider une commande, pas à pas](#6-valider-une-commande-pas-à-pas) · [7. Import et export CSV](#7-import-et-export-csv-f7-et-f9) · [8. Sécurité](#8-sécurité) · [9. Questions probables](#9-questions-probables) · [10. Limites et évolutions](#10-limites-et-évolutions-vers-un-erp-complet)

## 1. Le projet en 30 secondes

> Cette application permet à un domaine viticole de suivre ses cuvées, son stock, ses clients et ses commandes. Quand une commande est validée, le stock baisse automatiquement. Si le stock est insuffisant, la commande est refusée **et rien n'est modifié**. Un tableau de bord montre le chiffre d'affaires, les meilleurs clients et les cuvées à réapprovisionner. Pour échanger avec un autre système, les clients et les cuvées s'importent depuis un fichier Excel (avec le motif de chaque ligne refusée) et les données s'exportent en CSV.

Les chiffres à connaître : **6 tables, 4 vues, 10 requêtes d'analyse, 8 contraintes `CHECK`, 293 tests automatiques.** Données de démonstration : 20 cuvées, 15 clients, 50 commandes (47 validées, 3 annulées), 147 lignes de commande, 184 mouvements de stock.

## 2. L'architecture

```
Navigateur ──► page PHP (par exemple commande_nouvelle.php)
                 │  1. vérifie la connexion et le jeton de sécurité (CSRF)
                 │  2. appelle une fonction du dossier src/ (par exemple commande_creer)
                 ▼
              src/*.php : règles de gestion + requêtes SQL préparées
                 ▼
              MariaDB / MySQL : 6 tables, 4 vues, contraintes (dernier filet de sécurité)
```

- **Les pages** (`cuvees.php`, `commande_nouvelle.php`, `index.php`…) affichent et reçoivent les formulaires. Elles ne contiennent aucune requête SQL (seule exception : `verif.php`, la page de contrôle de l'installation).
- **Le dossier `src/`** contient la logique : un fichier par domaine (`cuvees.php`, `stock.php`, `clients.php`, `commandes.php`, `dashboard.php`) et un pour les échanges de fichiers (`csv.php`, `import.php`, `export.php`). Toutes les requêtes y sont écrites.
- **La base** garantit en dernier recours l'intégrité des données, même si le code avait un défaut.
- **Aucune bibliothèque externe, aucun service en ligne** : tout fonctionne hors connexion, y compris les polices et les graphiques.

## 3. Les 6 tables

### CUVEE : le catalogue (20 lignes)

Une ligne par vin et par millésime : `nom`, `millesime`, `couleur`, `degre`, `prix_vente`.

- Le couple (`nom`, `millesime`) est unique : le même vin peut exister en 2019 et en 2020, pas deux fois en 2019.
- `prix_vente` est le prix **actuel**. Il est recopié dans les lignes de commande au moment de la vente.
- **À dire :** « CUVEE est le catalogue : une ligne par vin et par millésime, avec son prix de vente actuel. »

### STOCK : combien il en reste (20 lignes)

Une ligne par cuvée : `quantite` (bouteilles disponibles) et `seuil_alerte`.

- Relation 1-1 avec CUVEE, traduite par une clé étrangère **unique** (`id_cuvee`).
- `quantite` ne peut pas être négative : c'est une contrainte `CHECK` de la base.
- Il y a *alerte* quand `quantite <= seuil_alerte`, et *rupture* quand `quantite = 0`.
- **À dire :** « STOCK dit combien de bouteilles il reste de chaque cuvée et à partir de quel seuil on alerte. Une cuvée a un seul stock, d'où la clé unique. »

### CLIENT : particuliers et professionnels (15 lignes)

`nom`, `type_client` (`particulier` ou `pro`), `email` (unique), `ville`. Données de démonstration : 9 particuliers et 6 professionnels, tous fictifs.

- **À dire :** « CLIENT regroupe particuliers et professionnels, distingués par un type. L'e-mail est unique pour repérer les doublons. »

### COMMANDE : l'en-tête (50 lignes)

`id_client`, `date_commande`, `statut` (`validee` ou `annulee`).

- Le **total n'est pas stocké** : il se calcule à partir des lignes (vue `v_commande_total`). Un total stocké peut devenir faux ; un total calculé, non.
- Une commande **n'est jamais supprimée** : elle est annulée, et son stock est remis. On garde ainsi l'historique complet.
- **À dire :** « COMMANDE est l'en-tête : qui, quand, dans quel état. Le détail est dans les lignes, et le total est calculé. »

### LIGNE_COMMANDE : le détail (147 lignes)

`id_commande`, `id_cuvee`, `quantite`, `prix_unitaire`.

- C'est la table d'association entre COMMANDE et CUVEE : une commande contient plusieurs cuvées, une cuvée figure dans plusieurs commandes (relation plusieurs-à-plusieurs). On la transforme en deux relations un-à-plusieurs.
- `prix_unitaire` est le prix **au jour de la commande**, recopié depuis `cuvee.prix_vente`. Si le prix change ensuite, l'historique reste juste.
- Le couple (`id_commande`, `id_cuvee`) est unique : une seule ligne par cuvée dans une commande.
- **À dire :** « LIGNE_COMMANDE relie une commande à une cuvée, avec la quantité et le prix du jour. »

### MOUVEMENT_STOCK : le journal (184 lignes)

`id_cuvee`, `id_commande` (vide pour une mise en bouteille), `date_mouvement`, `quantite` (toujours positive), `type_mouvement` (`entree` ou `sortie`), `motif`.

- Chaque bouteille qui entre ou sort y est écrite : mise en bouteille (26 entrées), vente (147 sorties, une par ligne de commande), annulation (11 entrées).
- Le sens est porté par `type_mouvement`, et la quantité reste positive : c'est plus lisible et la contrainte `quantite > 0` reste simple.
- **À dire :** « MOUVEMENT_STOCK est la mémoire du stock : on y retrouve pourquoi chaque bouteille est entrée ou sortie, et pour quelle commande. »

## 4. Les 4 vues

Une vue est une requête enregistrée que l'on interroge comme une table. Elle ne stocke rien : elle est recalculée à chaque lecture, donc toujours à jour. Elles sont dans `database/02_vues.sql`.

| Vue | Ce qu'elle donne | Technique |
|---|---|---|
| `v_commande_total` | Une ligne par commande : nombre de lignes, nombre de bouteilles, **total** | `LEFT JOIN` + `SUM(quantite × prix_unitaire)` + `GROUP BY` |
| `v_ca_par_cuvee` | Bouteilles vendues et chiffre d'affaires par cuvée, commandes validées seulement | 2 `LEFT JOIN` + `SUM(CASE WHEN statut = 'validee' …)` |
| `v_stock_bas` | Les cuvées à ou sous leur seuil ; `niveau` = `rupture` ou `bas` | `JOIN` + `WHERE quantite <= seuil_alerte` + `CASE` |
| `v_controle_stock` | Stock enregistré, stock recalculé depuis le journal, **écart** (doit être 0) | `LEFT JOIN` + `SUM(CASE type_mouvement …)` |

**À dire :** « Les vues évitent de réécrire la même requête partout : le tableau de bord, la liste des commandes et les requêtes d'analyse lisent les mêmes définitions. »

## 5. Les 10 requêtes

Elles sont dans `database/04_requetes.sql`, à coller dans l'onglet SQL de phpMyAdmin (base `gestion_cave`). Le cahier des charges en demande 5, dont une vue ; il y en a 10 et 4 vues. Les requêtes R1 à R6 alimentent le tableau de bord.

### R1. Chiffre d'affaires par cuvée (vue `v_ca_par_cuvee`)

- **Question :** quelles cuvées rapportent le plus ?
- **Technique :** `cuvee` → `LEFT JOIN ligne_commande` → `LEFT JOIN commande`. L'agrégat est `SUM(CASE WHEN statut = 'validee' THEN quantite × prix_unitaire END)`.
- **À savoir :** le `LEFT JOIN` garde les cuvées jamais vendues (avec 0). Le filtre « validée » est dans un `CASE` et non dans un `WHERE` : un `WHERE` sur la commande ferait disparaître les cuvées sans vente.
- **Résultat :** Les Hauts de Combe 2020 en tête (210 bouteilles, 4 158,00 €), puis Brut Nature 2021 (3 902,50 €) et Le Grand Chêne 2020 (3 332,00 €). Clos de la Source 2022 et Brut Rosé 2022 sont à 0.

### R2. Les 5 meilleurs clients

- **Question :** qui achète le plus ?
- **Technique :** `client` → `JOIN commande` (validées) → `JOIN ligne_commande`, `GROUP BY client`, `ORDER BY chiffre_affaires DESC LIMIT 5`.
- **À savoir :** on écrit `COUNT(DISTINCT commande.id)`. Sans `DISTINCT`, une commande de 3 lignes serait comptée 3 fois, car la jointure avec les lignes multiplie les lignes de commande.
- **Résultat :** Cave des Halles (Paris) 10 668,00 € sur 9 commandes ; Brasserie Le Marais 7 307,40 € ; Hôtel du Parc 5 300,40 € ; Épicerie Fine Les Saveurs 4 131,00 € ; Restaurant Le Bouchon Gourmand 3 850,80 €.

### R3. Chiffre d'affaires par mois

- **Question :** comment évoluent les ventes ?
- **Technique :** `commande` → `JOIN ligne_commande`, regroupement sur `DATE_FORMAT(date_commande, '%Y-%m')`.
- **Résultat :** 11 mois de janvier à novembre 2026. Les mois forts sont mai (6 134,40 €) et octobre (6 321,90 €). Novembre (4 105,20 €) est un mois en cours.

### R4. Panier moyen : particuliers contre professionnels (vue `v_commande_total`)

- **Question :** un professionnel commande-t-il plus qu'un particulier ?
- **Technique :** `v_commande_total` → `JOIN client`, `GROUP BY type_client`, `AVG(total)`.
- **À savoir :** on moyenne le **total de chaque commande**, pas les lignes. Un `AVG(quantite × prix)` directement sur les lignes donnerait le prix moyen d'une ligne, pas d'une commande. La vue calcule d'abord le total par commande.
- **Résultat :** professionnels 32 commandes, panier moyen 1 002,90 € ; particuliers 15 commandes, 172,03 €.

### R5. Valeur du stock par couleur

- **Question :** combien vaut le stock, et où est-il concentré ?
- **Technique :** `stock` → `JOIN cuvee`, `GROUP BY couleur`, `SUM(quantite × prix_vente)`. La somme porte sur un produit, calculé ligne par ligne avant d'être additionné.
- **Résultat :** rouge 20 977,00 € (8 cuvées, 1 320 bouteilles) ; blanc 12 648,00 € ; rosé 4 496,60 € ; effervescent 2 970,30 €. Le total, 41 091,90 €, est la valeur affichée sur le tableau de bord.

### R6. Cuvées à réapprovisionner (vues `v_stock_bas` et `v_ca_par_cuvee`)

- **Question :** que faut-il racheter ou remettre en bouteille en priorité ?
- **Technique :** jointure de **deux vues** sur `id_cuvee`, tri par quantité croissante (les ruptures d'abord).
- **À savoir :** croiser le stock et les ventes distingue une cuvée en rupture qui se vend bien d'une cuvée qui dort.
- **Résultat :** Le Grand Chêne 2020 est en rupture (0 bouteille) alors que c'est la 3e cuvée en chiffre d'affaires (3 332,00 €, 136 bouteilles vendues) : priorité absolue. Viennent ensuite Cuvée Étincelle 2020 (9), Clos des Alouettes 2019 (18), Pierre Dorée 2022 (30) et Rosé des Garrigues 2024 (44).

### R7. Détail d'une commande (exemple : la n°1)

- **Question :** que contient exactement cette commande, et à quel prix ?
- **Technique :** `commande` → `JOIN client` → `JOIN ligne_commande` → `JOIN cuvee` (4 tables, 3 jointures) ; le total de ligne est calculé (`quantite × prix_unitaire`).
- **À savoir :** la requête affiche `prix_unitaire` (prix de l'époque) à côté de `prix_actuel`. Pour Fleur de Sable 2023, 8,90 € payé contre 9,50 € aujourd'hui : c'est la preuve visible de la recopie du prix.
- **Résultat :** commande n°1 du Restaurant Le Bouchon Gourmand : 12 Cuvée des Maîtres (504,00 €), 6 Fleur de Sable (53,40 €), 12 Pierre Dorée (178,80 €), soit 736,20 €.

### R8. Cuvées jamais vendues

- **Question :** quelles cuvées n'ont connu aucune vente ?
- **Technique :** anti-jointure. `cuvee` → `LEFT JOIN ligne_commande` → `LEFT JOIN commande`, puis `HAVING COUNT(commande.id) = 0` : on garde les cuvées pour lesquelles la jointure ne trouve rien.
- **À savoir :** la condition `statut = 'validee'` est dans le `ON` de la jointure et non dans un `WHERE`. Dans un `WHERE`, elle éliminerait justement les cuvées sans vente. Cette requête donne le même résultat que les lignes à 0 de R1.
- **Résultat :** Brut Rosé 2022 (72 bouteilles, jamais commandée) et Clos de la Source 2022 (120 bouteilles ; sa seule commande, la n°10, a été annulée).

### R9. Contrôle d'intégrité (vue `v_controle_stock`)

- **Question :** le stock enregistré est-il égal à la somme des mouvements ?
- **Technique :** `SELECT * FROM v_controle_stock WHERE ecart <> 0`.
- **Résultat attendu : aucune ligne.** C'est la requête qui prouve que le stock et le journal ne se contredisent jamais. Le tableau de bord affiche la même vérification sous forme d'un bandeau vert.

### R10. Preuve que l'historique des prix est conservé

- **Question :** des ventes ont-elles été faites à un prix différent du prix actuel ?
- **Technique :** `ligne_commande` → `JOIN cuvee`, `WHERE prix_unitaire <> prix_vente`, `GROUP BY`.
- **Résultat :** 5 cuvées dont le prix a augmenté depuis certaines ventes, par exemple Clos des Alouettes 2019 (3 lignes, 72 bouteilles vendues à 26,00 € alors que le prix est maintenant de 28,50 €) ou Fleur de Sable 2023 (4 lignes, 8,90 € contre 9,50 €).

## 6. Valider une commande, pas à pas

C'est le cœur de l'application (`commande_creer()` dans `src/commandes.php`). Valider une commande, c'est faire **quatre écritures qui doivent réussir ensemble ou échouer ensemble** : créer la commande, créer ses lignes, baisser le stock, écrire les mouvements. D'où la **transaction**.

1. **Contrôle des saisies**, sans toucher à la base : le client existe, la commande a au moins une ligne, chaque quantité est un entier strictement positif, les lignes vides sont ignorées, une même cuvée saisie deux fois est fusionnée.
2. **Début de la transaction.**
3. **Lecture des stocks avec verrou** : `SELECT … FOR UPDATE`, dans l'ordre des numéros de cuvée. Le verrou empêche toute autre commande de modifier ces stocks avant la fin de celle-ci.
4. **Contrôle du stock** : pour chaque cuvée, quantité demandée ≤ quantité disponible. Sinon, la commande est refusée avec un message qui cite la cuvée, la quantité demandée et la quantité disponible. **Aucune écriture n'a eu lieu**, la transaction est annulée, le stock et les commandes sont inchangés. Aucun numéro de commande n'est consommé.
5. **Écritures** : `INSERT` de la commande ; pour chaque cuvée, `INSERT` de la ligne (avec le prix lu dans la base **sous verrou**, jamais celui du navigateur), `UPDATE` du stock, `INSERT` du mouvement de sortie.
6. **Validation de la transaction** (`COMMIT`).

**Pourquoi ces précautions :**

| Précaution | Problème évité |
|---|---|
| Transaction | Une commande à moitié enregistrée (par exemple des lignes sans baisse de stock) |
| `FOR UPDATE` | Deux commerciaux qui vendent en même temps la dernière bouteille : le second attend, relit le vrai stock, puis est refusé |
| Verrous pris dans l'ordre des numéros de cuvée | Interblocage (deadlock) : deux commandes qui s'attendent l'une l'autre |
| `UPDATE … WHERE quantite >= ?` suivi d'un contrôle du nombre de lignes modifiées | Un stock négatif, même si le contrôle de l'étape 4 contenait une erreur |
| Contrainte `CHECK (quantite >= 0)` dans la base | Dernier filet de sécurité, indépendant du code PHP |
| Prix relu en base sous verrou | Un prix falsifié dans le formulaire, ou un prix changé entre l'affichage et la validation |

**La preuve :** les tests lancent des commandes réellement simultanées (plusieurs processus). Deux commandes de 6 bouteilles sur un stock de 10 : une seule est acceptée, le stock final est de 4. Huit acheteurs d'une bouteille sur un stock de 5 : exactement 5 sont acceptés, le stock final est 0. En retirant volontairement le verrou, ce test échoue : il détecte donc bien le problème qu'il est censé détecter.

**Annulation** (`commande_annuler()`) : la commande est verrouillée, son statut passe à `annulee`, et chaque ligne remet ses bouteilles en stock par un mouvement d'entrée « Annulation commande n°N ». Deux clics simultanés ne peuvent pas remettre le stock deux fois.

## 7. Import et export CSV (F7 et F9)

Avant de se parler par API, deux systèmes échangent des fichiers. L'import charge des clients ou des cuvées depuis un fichier Excel ; l'export sort les données vers un autre outil. Le code est dans `src/csv.php` (lecture et écriture des fichiers), `src/import.php` et `src/export.php` ; les pages sont `import.php`, `import_rejets.php`, `export.php` et `export_csv.php`.

### L'import, pas à pas (`import_executer()`)

1. **Lecture du fichier.** L'encodage est reconnu (UTF-8 avec ou sans BOM, Windows-1252, qui est celui d'Excel sous Windows, ou UTF-16) ainsi que le séparateur (`;`, `,` ou tabulation). Un fichier binaire (une image, un `.xlsx` renommé) est refusé avec un message clair. La taille est limitée à 2 Mo et le nombre de lignes à 5 000.
2. **Reconnaissance des colonnes** par leur titre, dans n'importe quel ordre, sans tenir compte des majuscules ni des accents (« E-mail », « email » et « Courriel » désignent la même colonne). Une colonne obligatoire absente fait refuser **tout** le fichier, avec la liste de ce qui manque. Une colonne inconnue est ignorée et signalée dans le rapport.
3. **Nettoyage de chaque ligne** : « Professionnel » devient `pro`, « Rosé » devient `rose`, « 12,50 € » devient 12.50, « 13 % » devient 13.
4. **Validation par les mêmes fonctions que les formulaires** (`client_valider()` et `cuvee_valider()`) : les règles de gestion ne sont écrites qu'une fois. Puis deux contrôles de doublon : dans le fichier (une ligne qui répète une adresse e-mail, ou une cuvée et un millésime déjà vus, cite la ligne d'origine) et dans la base.
5. **Enregistrement** de toutes les lignes valides dans **une seule transaction**.
6. **Rapport** : lignes lues, enregistrées et refusées, format reconnu, colonnes ignorées, et le **motif de chaque refus** avec le numéro de ligne du fichier. Les lignes refusées se téléchargent en CSV (vos colonnes plus une colonne `motif_du_rejet`) : on les corrige, puis on les réimporte.

**Pourquoi ces choix :**

| Choix | Problème évité |
|---|---|
| Mêmes fonctions de validation que les formulaires | Deux jeux de règles qui divergent : un fichier ne peut pas faire entrer ce qu'un formulaire refuserait |
| Les lignes valides sont importées, les autres listées avec leur motif | Un fichier de 500 lignes bloqué en entier par 3 erreurs, ou des erreurs perdues en silence |
| Fichier des refus au même format, réimportable | Ressaisir à la main les lignes en erreur |
| Mode « Simuler seulement » | Découvrir les erreurs après avoir écrit dans la base |
| Une transaction, avec un `SAVEPOINT` par ligne | Une panne en plein milieu (base arrêtée, coupure) qui laisserait la moitié du fichier enregistrée. Un doublon que seule la base voit, à l'écriture, n'annule que sa propre ligne |
| Stock initial écrit par un mouvement « Mise en bouteille » | Un stock que le journal n'explique pas : la vue `v_controle_stock` signalerait un écart. Avec le mouvement, l'écart reste nul |
| Rapport gardé dans la session, page affichée par redirection | Réimporter le fichier en appuyant sur F5 |
| Limites de taille et de nombre de lignes | Un fichier géant qui bloque le serveur |

**Un `SAVEPOINT`, c'est quoi ?** Un point de retour à l'intérieur d'une transaction : `ROLLBACK TO SAVEPOINT` annule ce qui a été fait depuis ce point sans abandonner la transaction entière. `en_transaction()` (dans `src/helpers.php`) en pose un quand elle est appelée alors qu'une transaction est déjà ouverte. C'est ce qui permet à l'import de réutiliser `client_creer()` et `cuvee_creer()` tels quels, à l'intérieur de sa propre transaction.

**Un doublon, c'est quoi ?** Pour un client, la même adresse e-mail. Pour une cuvée, le même nom et le même millésime. La comparaison ignore les majuscules et les accents, comme la base (`utf8mb4_unicode_ci`). Quand le fichier ne voit pas un doublon que la base voit (« Straße » et « Strasse » sont identiques pour MariaDB), la ligne est refusée à l'écriture avec le même message, et les autres lignes sont enregistrées.

**L'import ne modifie jamais l'existant** : il ajoute, ou il refuse. Mettre à jour des fiches en masse demanderait une clé de rapprochement (code client, référence cuvée) qui n'existe pas dans ce modèle.

**À montrer :** `modeles/clients_avec_erreurs.csv` (8 lignes) donne 3 clients ajoutés et 5 lignes refusées, chacune pour un motif différent : e-mail déjà en base (ligne 4), e-mail invalide (5), nom manquant (6), type de client inconnu (7), doublon dans le fichier (9). `modeles/cuvees_avec_erreurs.csv` fait de même pour les cuvées : cuvée déjà existante, millésime futur, couleur inconnue, degré hors limites, prix négatif. Un test automatique vérifie que ces deux fichiers donnent bien ce résultat.

### L'export (`export_csv.php?donnees=…`)

- **Six fichiers** : `commandes`, `lignes` (le détail des commandes, avec le prix payé), `stock`, `clients`, `cuvees` et `mouvements`. Le téléchargement est en lecture seule et demande d'être connecté.
- **Même fonctions que les écrans** (`commandes_liste()`, `stock_liste()`, `clients_liste()`, `cuvees_liste()`, `mouvements_liste()`) : le fichier contient les lignes de l'écran, avec les mêmes filtres et dans le même ordre. Il n'y a pas de seconde requête à maintenir, donc pas de risque que l'écran et le fichier se contredisent (le détail des commandes, qui n'a pas d'écran propre, réutilise le même filtre SQL, `commandes_filtre_sql()`). Seule différence : l'écran des mouvements est limité à 200 lignes, le fichier contient tout le journal.
- **Format « Excel français »** : séparateur point-virgule, virgule décimale, dates `AAAA-MM-JJ HH:MM:SS`, UTF-8 avec BOM, fins de ligne Windows. Un double-clic suffit, sans assistant d'importation.
- **Injection de formules (« CSV injection »).** Un client peut s'appeler `=HYPERLINK("http://…")` : à l'ouverture du fichier dans Excel, la cellule serait exécutée comme une formule. Tout texte qui commence par `=`, `+`, `-`, `@`, une tabulation ou un retour chariot est donc précédé d'une apostrophe, qu'Excel affiche comme du texte. Les nombres, eux, ne sont jamais modifiés.
- **Les exports « Clients » et « Cuvées » se réimportent tels quels** dans une autre base : leurs titres sont ceux que l'import reconnaît, et les colonnes de calcul (commandes validées, chiffre d'affaires, stock) sont ignorées.

## 8. Sécurité

| Risque | Protection | Où |
|---|---|---|
| Injection SQL | Requêtes **préparées** partout. `PDO::ATTR_EMULATE_PREPARES = false` : c'est le serveur de base de données qui exécute la requête préparée, les valeurs ne sont jamais recopiées dans le texte SQL. Les jokers `%` et `_` des recherches sont neutralisés, les listes (statut, couleur) sont comparées à une liste blanche. | `src/db.php`, `src/helpers.php` |
| Injection de HTML / JavaScript (XSS) | Toute donnée affichée passe par `e()` (`htmlspecialchars`). Les info-bulles des graphiques utilisent `textContent`. L'en-tête `Content-Security-Policy` interdit les scripts en ligne. | `src/helpers.php`, `src/bootstrap.php` |
| CSRF (formulaire envoyé à l'insu de l'utilisateur) | Jeton secret dans chaque formulaire, vérifié à la réception. Toute action qui modifie des données passe par `POST`, jamais par un lien. | `src/csrf.php` |
| Vol ou fixation de session | Cookie `HttpOnly` et `SameSite=Lax` (et `Secure` en HTTPS), nouvel identifiant de session à la connexion, session détruite à la déconnexion. | `src/bootstrap.php`, `src/auth.php` |
| Mot de passe lisible | Seul le **hash bcrypt** est dans la configuration ; une attente de 0,4 s ralentit les essais répétés. | `config/config.php`, `src/auth.php` |
| Affichage d'erreurs techniques | Masquées par défaut (`debug` à `false`) : l'utilisateur voit un message clair, jamais une trace SQL. | `config/config.php` |
| Accès direct aux fichiers internes | Fichiers `.htaccess` « Require all denied » dans `src`, `config`, `database`, `tests`, `tools`, `docs` (serveur Apache). | dossiers concernés |
| Données incorrectes | Validation **côté serveur** de toutes les saisies. Le JavaScript n'est qu'une aide : le serveur ne lui fait jamais confiance. | `src/*.php` |
| Fichier importé hostile (énorme, binaire, mal encodé, rempli de HTML) | Taille limitée (2 Mo), 5 000 lignes au plus, fichier binaire refusé, encodage contrôlé. Chaque ligne passe par les validateurs des formulaires, et tout ce qui est affiché dans le rapport passe par `e()`. Le formulaire d'import est protégé par le jeton CSRF. | `src/import.php`, `src/csv.php` |
| Injection de formule dans un export (un client nommé `=…`) | Tout texte qui commence par `=`, `+`, `-`, `@`, tabulation ou retour chariot est précédé d'une apostrophe dans les fichiers CSV. Les exports sont en lecture seule et demandent d'être connecté. | `src/csv.php`, `export_csv.php` |

Les saisies sont aussi bornées : quantité par ligne limitée à 100 000, 100 lignes par commande au plus.

## 9. Questions probables

**Que se passe-t-il si deux commerciaux commandent la dernière bouteille en même temps ?**
Le premier prend un verrou sur la ligne de stock. Le second attend, puis lit le stock réel (0) et se voit refuser sa commande. C'est testé avec des processus réellement parallèles (section 6).

**Pourquoi recopier le prix dans la ligne de commande ?**
Parce qu'une commande est un fait historique : si le tarif change demain, le chiffre d'affaires d'hier ne doit pas changer. La requête R10 montre 5 cuvées déjà concernées.

**Pourquoi ne pas stocker le total de la commande ?**
Une donnée calculable n'a pas besoin d'être stockée, et une donnée stockée peut se contredire. Le total est calculé par une vue, il est donc toujours exact.

**Le stock existe à deux endroits (la table STOCK et le journal) : n'est-ce pas une redondance ?**
Oui, et c'est voulu. `stock.quantite` sert de lecture rapide et permet à la base d'imposer qu'il ne soit jamais négatif. Le journal est la mémoire. Les deux sont écrits dans la **même transaction**, et la vue `v_controle_stock` (requête R9) vérifie à tout moment qu'ils concordent.

**Pourquoi ne supprime-t-on jamais une commande ?**
Pour garder la traçabilité : une commande annulée reste visible, avec ses mouvements. Dans un vrai ERP, l'historique commercial doit être conservé. De même, une cuvée qui a un historique ne peut pas être supprimée.

**Pourquoi PHP sans framework ?**
Le projet est volontairement petit et doit se lancer sur XAMPP sans installation. Chaque fichier se lit en quelques minutes et chaque ligne s'explique. Pour un produit, on choisirait un framework (routage, authentification, migrations) ; la base de données et les règles de gestion resteraient les mêmes.

**Comment les erreurs de saisie sont-elles gérées ?**
Le serveur valide tout et renvoie des messages précis en français (« Ligne 2 : la quantité doit être un nombre entier strictement positif »). Le formulaire est réaffiché avec les valeurs déjà saisies, rien n'est à ressaisir.

**Et avec beaucoup de données ?**
Les clés étrangères sont indexées par InnoDB, et des index existent sur la date et le statut des commandes. Les listes ne sont pas paginées et les écrans n'ont pas été mesurés sur de gros volumes : c'est une limite connue de ce prototype (section 10). Seul l'import a été chronométré, sur la machine de test : 5 000 clients (268 Ko de CSV) sont ajoutés en 1,2 seconde, 5 000 cuvées avec leur stock et leur mouvement en 2,4 secondes, avec un stock resté égal à la somme des mouvements.

**Les montants sont-ils hors taxes ou TTC ?**
Le cahier des charges exclut la facturation légale ; les montants sont donc des prix de vente sans précision de TVA. Dans un ERP, chaque cuvée ou chaque ligne porterait un taux de TVA.

**Comment ce projet se relierait-il à notre ERP ?**
Par échange de fichiers CSV, ce qui est déjà possible : import des clients et des cuvées, export des commandes, du stock, des clients et des mouvements. Dans un second temps, par une API. Le schéma est du SQL standard, et le journal des mouvements est la bonne base pour une synchronisation.

**Que se passe-t-il si un fichier importé contient une erreur à la ligne 40 ?**
Cette ligne est refusée avec son motif (« Cette adresse e-mail n'est pas valide. Valeur lue : … »), et les autres lignes valides sont enregistrées. Les lignes refusées se téléchargent dans un fichier à corriger puis à réimporter, et la case « Simuler seulement » permet de tout vérifier sans rien écrire (section 7).

**Un import peut-il laisser la base à moitié remplie ?**
Non. Toutes les lignes valides sont écrites dans une seule transaction : si une panne technique survient en cours de route, rien n'est enregistré. Un test provoque une vraie panne au milieu d'un import (un déclencheur qui échoue sur une ligne) et vérifie qu'aucune cuvée ni aucun stock n'est resté.

**Pourquoi l'import ne met-il pas à jour les fiches existantes ?**
Parce que ce modèle n'a pas de clé de rapprochement fiable à part l'e-mail (clients) et le couple nom et millésime (cuvées), et qu'écraser des données en masse est risqué. L'import ajoute ou refuse. Une mise à jour en masse serait une évolution, avec un rapport « avant et après ».

**Comment prouver que ça marche ?**
`php tests/run.php` lance 293 vérifications en environ 4 secondes, sur une base de test séparée qui ne touche pas la base de démonstration : règles de gestion, refus sans modification de la base, annulation, filtres, tableau de bord, requêtes, commandes simultanées, injections, import et export CSV (dont les fichiers d'exemple du dossier `modeles/`).

## 10. Limites et évolutions vers un ERP complet

Ce qui est volontairement hors périmètre (cahier des charges) ou simplifié :

- **Un seul compte.** Le cahier des charges prévoit deux profils (gestionnaire et commercial) mais précise qu'un seul compte suffit. Évolution : une table `utilisateur` avec un rôle, et des contrôles de droits par page.
- **Pas de TVA, de facturation légale, de douanes ni d'accises** (exclus du cahier des charges). Évolution : taux de TVA par ligne, factures numérotées sans trou, régime douanier et documents d'accompagnement sur les mouvements.
- **Un seul lieu de stockage.** Évolution : une table `depot`, un stock par (cuvée, dépôt), des transferts écrits comme deux mouvements liés.
- **Pas de lots ni de traçabilité fine.** Évolution : une table `lot` (parcelle, date de mise en bouteille) reliée aux mouvements.
- **Pas de devis, de livraison ni de paiement.** Évolution : des statuts supplémentaires (`devis`, `livree`, `payee`) et une table de règlements.
- **Listes non paginées, pas de gestion des gros volumes** (suffisant pour la démonstration).
- **Import limité aux clients et aux cuvées**, en ajout seulement, 5 000 lignes par fichier. Évolution : import des commandes (avec contrôle du stock ligne à ligne), mise à jour en masse avec une clé de rapprochement, traitement par lots pour les gros fichiers.
- **Testé sur MariaDB 10.11 et PHP 8.3.** MariaDB est le moteur livré avec XAMPP. MySQL 8 devrait convenir (mêmes contraintes `CHECK`), mais n'a pas été testé ici.
