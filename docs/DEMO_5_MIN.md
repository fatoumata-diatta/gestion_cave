# Démonstration de 5 minutes

Scénario : **créer une commande, voir le stock baisser, ouvrir le tableau de bord**, plus la preuve qu'une commande refusée ne modifie pas la base. Les chiffres ci-dessous sont ceux obtenus en répétant ce scénario sur les données de démonstration fournies. L'import et l'export CSV (bonus F7 et F9) sont proposés en fin de document, à placer **après** le scénario principal.

## Avant la démonstration (5 minutes)

1. **Démarrer XAMPP** : Apache et MySQL doivent être verts.
2. **Remettre la base à zéro** : phpMyAdmin (`http://localhost/phpmyadmin`) → onglet *Importer* → fichier `database/install_complet.sql` → *Importer*. Cela supprime et recrée tout : la prochaine commande sera la n°51.
3. **Ouvrir `http://localhost/gestion-cave/verif.php`** : toutes les lignes doivent être « OK ».
4. **Se connecter** (`admin` / `cave2026`) et ouvrir le tableau de bord. Contrôler les trois chiffres de départ : **34 673,30 €**, **47 commandes validées**, **5 cuvées à réapprovisionner**. Si l'un diffère, la base a été modifiée : recommencer l'étape 2.
5. **Confort d'affichage** : zoom du navigateur à 110 ou 125 %, plein écran (F11), notifications coupées, onglets inutiles fermés.
6. **Préparer en réserve** : un onglet phpMyAdmin sur la base `gestion_cave` (onglet SQL), et `docs/img/mcd.png` ouvert, au cas où l'on demande le modèle de données. Pour le bonus, repérer dans l'explorateur de fichiers `modeles/clients_avec_erreurs.csv`, que la fenêtre d'envoi de fichier devra retrouver.

## Le scénario minuté

| Durée | Écran | Action | À dire |
|---|---|---|---|
| 0:00 à 0:30 | **Tableau de bord** | Montrer les cinq indicateurs du haut. Pointer le bandeau vert en bas de page. | « Voici un domaine avec 20 cuvées et 50 commandes. Chiffre d'affaires de 34 673 €, 47 commandes validées, et 5 cuvées à réapprovisionner dont une en rupture. Le bandeau vert vérifie que le stock est égal à la somme des mouvements. » |
| 0:30 à 1:00 | **Stock** (menu) | Repérer *Brume d'Automne 2022* (24 bouteilles, seuil 12) et *Les Hauts de Combe 2020* (210). | « Brume d'Automne : 24 bouteilles, le seuil d'alerte est à 12. Je vais en vendre 14. » |
| 1:00 à 2:15 | **Nouvelle commande** | Client : *Restaurant Le Bouchon Gourmand (Lyon)*. Ligne 1 : *Brume d'Automne 2022*, quantité **14**. Cliquer *Ajouter une ligne* ; ligne 2 : *Les Hauts de Combe 2020*, quantité **24**. Le total affiché est **846,20 €**. Cliquer *Valider la commande*. | « Le prix et le stock apparaissent dès que je choisis la cuvée, et le total se calcule en direct. À la validation, le serveur recalcule tout lui-même : il ne fait pas confiance au navigateur. » |
| 2:15 à 3:00 | **Commande n°51** (affichée après la validation) | Montrer les deux lignes et les deux mouvements de sortie (−24 et −14, « Vente commande n°51 »). | « La commande est enregistrée avec le prix du jour. Les mouvements de stock ont été écrits dans la même transaction : tout réussit ensemble ou rien ne se passe. » |
| 3:00 à 3:30 | **Stock** | *Brume d'Automne 2022* passe de 24 à **10** et affiche *Stock bas* ; *Les Hauts de Combe 2020* passe de 210 à **186**. | « Le stock a baissé tout seul, et Brume d'Automne est maintenant sous son seuil. » |
| 3:30 à 4:15 | **Nouvelle commande** (refus) | Client : *Restaurant Le Bouchon Gourmand*. Ligne 1 : *Terre Rouge 2021*, quantité **6**. Ligne 2 : *Cuvée Étincelle 2020*, quantité **12**. Le formulaire affiche en rouge *Stock : 9 (insuffisant)*. Cliquer *Valider la commande*. Lire le message de refus. Aller dans **Stock** : *Terre Rouge 2021* est toujours à **380**. Dans **Commandes** : toujours 51 commandes. | « Douze bouteilles demandées, neuf disponibles : la commande est refusée. Et surtout, la ligne *Terre Rouge*, qui était correcte, n'a pas été enregistrée non plus : une commande est tout ou rien. La base n'a pas bougé. » |
| 4:15 à 5:00 | **Tableau de bord** | Chiffre d'affaires passé à **35 519,50 €**, **48 commandes validées**, **6 cuvées à réapprovisionner** (Brume d'Automne est apparue). Dans *Meilleurs clients*, le Restaurant Le Bouchon Gourmand passe de la 5e à la **4e place** (4 697,00 €). | « Le tableau de bord est calculé en direct sur les données : rien n'est recopié à la main. Le restaurant vient de dépasser l'épicerie fine. » |

**Conclusion (15 secondes) :** « Six tables, quatre vues, dix requêtes d'analyse. Les règles de gestion sont garanties à deux niveaux, dans le code et dans la base. 293 tests automatiques le vérifient, y compris des commandes simultanées et l'import de fichiers. »

## Si le temps le permet (30 secondes chacun)

- **Annuler la commande n°51** : sur sa fiche, bouton *Annuler la commande*, confirmer. Le stock revient (Brume d'Automne à 24), des mouvements « Annulation commande n°51 » apparaissent, la commande reste visible avec le statut *Annulée* (elle n'est jamais supprimée).
- **Prix recopié** : ouvrir la commande n°1. *Fleur de Sable 2023* a été vendue 8,90 € alors que le tarif actuel est de 9,50 € (affiché en note). Le prix de l'époque est conservé.
- **Entrée de stock (F2)** : menu *Stock*, bouton *Entrée* sur la ligne *Le Grand Chêne 2020* (en rupture), quantité 120, puis *Enregistrer l'entrée* : la rupture disparaît et le mouvement « Mise en bouteille » est écrit dans le journal.
- **Preuve en SQL** : dans phpMyAdmin, exécuter la requête R9 de `database/04_requetes.sql` : elle ne retourne **aucune ligne**, c'est-à-dire aucun écart entre le stock et les mouvements.
- **Import CSV avec rapport d'erreurs (F7)** : menu *Import CSV*, *Clients*, choisir `modeles/clients_avec_erreurs.csv`, *Importer le fichier*. Le message annonce **3 clients ajoutés, 5 lignes refusées** et le rapport donne le motif de chaque refus : e-mail déjà en base (Sophie Lambert), e-mail invalide, nom manquant, type de client inconnu (« grossiste »), doublon dans le fichier. *Télécharger ces lignes (CSV)* donne le fichier à corriger, puis à réimporter. À dire : « Chaque ligne passe par les mêmes contrôles que le formulaire. Les lignes valides sont enregistrées, les autres sont expliquées. Et si la base tombe en panne en plein import, rien n'est enregistré. » À faire une seule fois par base : un second passage refuserait les 8 lignes, puisque les clients existent désormais (ce qui montre aussi qu'on ne crée pas de doublon).
- **Export CSV (F9)** : menu *Commandes*, client *Restaurant Le Bouchon Gourmand*, *Filtrer* (5 commandes après le scénario principal), *Exporter en CSV*, puis ouvrir le fichier dans Excel : mêmes lignes que l'écran, montants avec virgule décimale, accents corrects. La page *Export CSV* du menu propose les cinq autres fichiers.
- **Tests** : dans un terminal, `C:\xampp\php\php.exe tests\run.php` (4 secondes, 293 vérifications vertes).

## Si quelque chose se passe mal

| Problème | Que faire |
|---|---|
| Page blanche ou erreur de connexion | Ouvrir `verif.php` : la ligne en rouge indique la cause (MySQL arrêté, mauvais port, base non importée). |
| Les chiffres ne correspondent pas au scénario | La base a été modifiée : réimporter `install_complet.sql` (5 secondes). |
| Erreur de manipulation pendant la démo (mauvaise quantité, mauvaise cuvée) | Annuler la commande depuis sa fiche : le stock revient. C'est aussi une démonstration utile. |
| Le serveur ne répond plus | Montrer les captures de `docs/img/` (tableau de bord, saisie, refus, fiche de commande, rapport d'import, page d'export) et dérouler le même discours. |
| Pas de connexion Internet | Aucun impact : l'application n'utilise aucun service externe (polices et graphiques inclus). |
| Un appui sur F5 après la validation | Sans danger : la validation redirige vers la fiche de commande, la commande n'est pas recréée. |
| L'import affiche « 0 client ajouté » | Le fichier a déjà été importé. Montrer le rapport tel quel (aucun doublon créé) ou réimporter `install_complet.sql` pour repartir de la démonstration. |
| L'import répond « trop volumineux » ou n'envoie rien | Limite d'envoi de fichiers de PHP : ouvrir `verif.php` (ligne « Envoi de fichiers »). Les fichiers d'exemple sont minuscules, donc c'est le signe d'un réglage inhabituel. |

## Questions fréquentes

Les réponses sont dans [`EXPLICATIONS.md`](EXPLICATIONS.md) : chaque table (§3), chaque requête (§5), la transaction qui protège le stock (§6), l'import et l'export CSV (§7), la sécurité (§8), les questions probables (§9) et les limites avec les évolutions vers un ERP (§10).
