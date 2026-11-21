<?php
declare(strict_types=1);

/**
 * Tests automatiques de la gestion de cave.
 *
 * Lancer depuis le dossier du projet :   php tests/run.php
 * (sous XAMPP : C:\xampp\php\php.exe tests\run.php)
 *
 * Les tests travaillent sur une base SÉPARÉE, « gestion_cave_test », recréée à chaque lancement
 * à partir de database/install_complet.sql : la base de démonstration n'est jamais touchée.
 * Option --garder : ne supprime pas la base de test à la fin (pour l'examiner dans phpMyAdmin).
 *
 * Ce que les tests prouvent (voir la liste des sections ci-dessous) :
 *   - les règles de gestion (au moins une ligne, quantités > 0, jamais plus que le stock, prix recopié) ;
 *   - le critère « une commande refusée pour stock insuffisant ne modifie pas la base » ;
 *   - la cohérence stock = somme des mouvements après chaque opération ;
 *   - la tenue en cas de commandes simultanées sur les dernières bouteilles ;
 *   - la résistance aux injections SQL et à l'injection de HTML ;
 *   - l'import CSV (lignes valides enregistrées, refus expliqués, tout ou rien en cas de panne, stock cohérent) ;
 *   - l'export CSV (contenu recoupé avec des requêtes SQL, filtres de l'écran, neutralisation des formules Excel).
 */

if (PHP_SAPI !== 'cli') {
    exit('Ces tests se lancent en ligne de commande : php tests/run.php');
}
if (function_exists('sapi_windows_cp_set')) {
    @sapi_windows_cp_set(65001);   // console Windows en UTF-8 : les accents s'affichent correctement
}

const BASE_TEST = 'gestion_cave_test';
putenv('CAVE_DB_NAME=' . BASE_TEST);   // la configuration lit cette variable : tout le code travaille sur la base de test
require dirname(__DIR__) . '/src/core.php';

// ---------------------------------------------------------------------------
//  Petit outillage de test
// ---------------------------------------------------------------------------

$GLOBALS['bilan'] = ['ok' => 0, 'echec' => 0, 'ignore' => 0];

function section(string $titre): void
{
    echo "\n== $titre ==\n";
}

function verifier(bool $condition, string $message): void
{
    $GLOBALS['bilan'][$condition ? 'ok' : 'echec']++;
    echo ($condition ? '  [OK]     ' : '  [ÉCHEC]  ') . $message . "\n";
}

function ignorer(string $message): void
{
    $GLOBALS['bilan']['ignore']++;
    echo '  [IGNORÉ] ' . $message . "\n";
}

/** Exécute un bloc de test ; une exception inattendue est comptée comme un échec. */
function essayer(callable $bloc): void
{
    try {
        $bloc();
    } catch (Throwable $erreur) {
        verifier(false, 'Exception inattendue : ' . get_class($erreur) . ' : ' . $erreur->getMessage());
    }
}

/** Vérifie qu'une action est refusée par une règle de gestion, avec un message contenant $fragment. */
function refus_attendu(callable $action, string $fragment, string $message): void
{
    try {
        $action();
        verifier(false, $message . ' : aucun refus (la règle n\'est pas appliquée)');
    } catch (RegleGestionException $refus) {
        $texte = implode(' | ', $refus->erreurs());
        verifier(str_contains($texte, $fragment), $message . '  [message : ' . $texte . ']');
    }
}

/** Vérifie qu'une instruction SQL directe est rejetée par la base (contrainte CHECK, clé étrangère, doublon). */
function sql_rejete(string $sql, array $codes, string $message): void
{
    try {
        db()->exec($sql);
        verifier(false, $message . ' : la base a accepté la ligne');
    } catch (PDOException $erreur) {
        verifier(in_array(code_erreur_sql($erreur), $codes, true), $message . '  [erreur ' . code_erreur_sql($erreur) . ']');
    }
}

/** Exécute un script SQL instruction par instruction (les erreurs sont ainsi toutes signalées). */
function executer_script(PDO $pdo, string $sql): void
{
    $lignes = array_filter(explode("\n", str_replace("\r", '', $sql)), static fn(string $l): bool => !str_starts_with(ltrim($l), '--'));
    foreach (preg_split('/;\s*(\n|$)/', implode("\n", $lignes)) ?: [] as $instruction) {
        if (trim($instruction) !== '') {
            $pdo->exec($instruction);
        }
    }
}

/** Empreinte de l'état de la base : si elle est identique avant et après, rien n'a été modifié. */
function instantane(): array
{
    return [
        'commandes'      => (int) requete_valeur('SELECT COUNT(*) FROM commande'),
        'lignes'         => (int) requete_valeur('SELECT COUNT(*) FROM ligne_commande'),
        'mouvements'     => (int) requete_valeur('SELECT COUNT(*) FROM mouvement_stock'),
        'stocks'         => (string) requete_valeur("SELECT MD5(GROUP_CONCAT(CONCAT(id_cuvee, ':', quantite) ORDER BY id_cuvee SEPARATOR ',')) FROM stock"),
        'dernier_numero' => (int) requete_valeur('SELECT COALESCE(MAX(id), 0) FROM commande'),
    ];
}

function creer_cuvee_test(string $nom, int $stock, float $prix = 10.0, int $seuil = 5): int
{
    return cuvee_creer([
        'nom' => $nom, 'millesime' => 2025, 'couleur' => 'rouge', 'degre' => 13.0,
        'prix_vente' => $prix, 'seuil_alerte' => $seuil, 'stock_initial' => $stock,
    ]);
}

function creer_client_test(string $nom, string $type = 'particulier'): int
{
    return client_creer([
        'nom' => $nom, 'type_client' => $type, 'email' => strtolower(bin2hex(random_bytes(5))) . '@example.com', 'ville' => 'Lyon',
    ]);
}

function stock_de(int $idCuvee): int
{
    return (int) requete_valeur('SELECT quantite FROM stock WHERE id_cuvee = ?', [$idCuvee]);
}

function nb_ecarts(): int
{
    return count(controle_stock_ecarts());
}

/** Lance plusieurs commandes dans des processus PHP distincts, qui valident toutes au même instant. */
function lancer_en_parallele(array $commandes): array
{
    $depart = microtime(true) + 2.0;
    $processus = [];
    foreach ($commandes as $i => [$idClient, $idCuvee, $quantite]) {
        $pipes = [];
        $commande = [PHP_BINARY, __DIR__ . '/worker_commande.php', (string) $idClient, (string) $idCuvee, (string) $quantite, sprintf('%.6F', $depart)];
        $processus[$i] = [proc_open($commande, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes), $pipes];
    }
    $resultats = [];
    foreach ($processus as $i => [$ressource, $pipes]) {
        $sortie = (string) stream_get_contents($pipes[1]);
        $erreurs = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($ressource);
        $resultats[$i] = json_decode(trim($sortie), true) ?? ['ok' => false, 'technique' => 'sortie illisible : ' . $sortie . $erreurs];
    }
    return $resultats;
}

// ---------------------------------------------------------------------------
//  Préparation : base de test recréée depuis les scripts SQL du projet
// ---------------------------------------------------------------------------

echo "Préparation de la base de test « " . BASE_TEST . " »...\n";
try {
    $c = config('db');
    $serveur = new PDO(
        sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $c['host'], $c['port']),
        (string) $c['user'],
        (string) $c['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $script = (string) file_get_contents(APP_ROOT . '/database/install_complet.sql');
    executer_script($serveur, str_replace('gestion_cave', BASE_TEST, $script));
} catch (Throwable $erreur) {
    fwrite(STDERR, "Impossible de préparer la base de test : " . $erreur->getMessage() . "\n"
        . "Vérifiez que MySQL est démarré et que config/config.php contient les bons identifiants.\n");
    exit(2);
}
$debut = microtime(true);

// ===========================================================================
section('1. Base de données : structure et données de démonstration');
// ===========================================================================
essayer(static function (): void {
    $objets = requete_toutes('SELECT TABLE_TYPE AS genre, COUNT(*) AS nb FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() GROUP BY TABLE_TYPE');
    $nb = array_column($objets, 'nb', 'genre');
    verifier((int) ($nb['BASE TABLE'] ?? 0) === 6, '6 tables : cuvee, stock, client, commande, ligne_commande, mouvement_stock');
    verifier((int) ($nb['VIEW'] ?? 0) === 4, '4 vues : v_commande_total, v_ca_par_cuvee, v_stock_bas, v_controle_stock');

    verifier((int) requete_valeur('SELECT COUNT(*) FROM cuvee') === 20, '20 cuvées de démonstration');
    verifier((int) requete_valeur('SELECT COUNT(*) FROM client') === 15, '15 clients de démonstration');
    verifier((int) requete_valeur('SELECT COUNT(*) FROM commande') === 50, '50 commandes de démonstration');
    verifier(nb_ecarts() === 0, 'Le stock de chaque cuvée est égal à la somme de ses mouvements (écart nul)');

    $niveaux = array_column(requete_toutes('SELECT niveau, COUNT(*) AS nb FROM v_stock_bas GROUP BY niveau'), 'nb', 'niveau');
    verifier((int) ($niveaux['bas'] ?? 0) >= 1 && (int) ($niveaux['rupture'] ?? 0) >= 1, 'La démo contient au moins une cuvée en stock bas et une en rupture');
});

// ===========================================================================
section('2. Contraintes de la base (elles protègent même si le code PHP se trompait)');
// ===========================================================================
essayer(static function (): void {
    $checks = (int) requete_valeur(
        "SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME LIKE 'ck\\_%'"
    );
    if ($checks < 8) {
        ignorer('Ce serveur ne gère pas les contraintes CHECK (MariaDB 10.2+ ou MySQL 8.0.16+ requis) : tests de contraintes sautés');
    } else {
        $codesCheck = [4025, 3819];   // MariaDB, MySQL
        sql_rejete('UPDATE stock SET quantite = -1 WHERE id_cuvee = 1', $codesCheck, 'Un stock négatif est refusé par la base');
        sql_rejete('INSERT INTO ligne_commande (id_commande, id_cuvee, quantite, prix_unitaire) VALUES (1, 20, 0, 10)', $codesCheck, 'Une quantité nulle sur une ligne de commande est refusée');
        sql_rejete('INSERT INTO ligne_commande (id_commande, id_cuvee, quantite, prix_unitaire) VALUES (1, 20, 3, 0)', $codesCheck, 'Un prix unitaire nul sur une ligne de commande est refusé');
        sql_rejete('INSERT INTO mouvement_stock (id_cuvee, quantite, type_mouvement, motif) VALUES (1, 0, \'entree\', \'test\')', $codesCheck, 'Un mouvement de quantité nulle est refusé');
        sql_rejete('UPDATE cuvee SET prix_vente = -5 WHERE id = 1', $codesCheck, 'Un prix de vente négatif est refusé');
    }
    sql_rejete('INSERT INTO client (nom, type_client, email, ville) SELECT \'Doublon\', \'pro\', email, \'Paris\' FROM client LIMIT 1', [1062], 'Deux clients ne peuvent pas avoir la même adresse e-mail');
    sql_rejete('DELETE FROM cuvee WHERE id = (SELECT id_cuvee FROM ligne_commande LIMIT 1)', [1451], 'Une cuvée déjà vendue ne peut pas être supprimée (clé étrangère)');
    sql_rejete('INSERT INTO ligne_commande (id_commande, id_cuvee, quantite, prix_unitaire) SELECT id_commande, id_cuvee, 1, 5 FROM ligne_commande LIMIT 1', [1062], 'Une cuvée ne peut apparaître qu\'une fois par commande');
});

// ===========================================================================
section('3. F1 : cuvées (validation, création, modification, suppression)');
// ===========================================================================
essayer(static function (): void {
    [, $erreurs] = cuvee_valider([
        'nom' => '', 'millesime' => '1850', 'couleur' => 'violet', 'degre' => 'abc',
        'prix_vente' => '0', 'seuil_alerte' => '-1', 'stock_initial' => '1.5',
    ]);
    $attendus = ['nom', 'millesime', 'couleur', 'degre', 'prix_vente', 'seuil_alerte', 'stock_initial'];
    verifier(array_keys($erreurs) === $attendus, 'Une saisie entièrement fausse produit une erreur par champ (' . implode(', ', array_keys($erreurs)) . ')');

    [$donnees, $erreurs] = cuvee_valider([
        'nom' => '  Test   Cuvée  ', 'millesime' => '2024', 'couleur' => 'rouge', 'degre' => '13,5',
        'prix_vente' => '12,90', 'seuil_alerte' => '10', 'stock_initial' => '48',
    ]);
    verifier(!$erreurs && $donnees['nom'] === 'Test Cuvée' && $donnees['prix_vente'] === 12.9 && $donnees['degre'] === 13.5,
        'Une saisie correcte est acceptée et nettoyée (espaces réduits, virgule décimale)');

    $id = cuvee_creer($donnees);
    verifier(stock_de($id) === 48, 'La création avec stock initial crée la ligne de stock (48 bouteilles)');
    $mvt = requete_une('SELECT type_mouvement, quantite, motif FROM mouvement_stock WHERE id_cuvee = ?', [$id]);
    verifier($mvt !== null && $mvt['type_mouvement'] === 'entree' && (int) $mvt['quantite'] === 48, 'Le stock initial est aussi écrit dans le journal (entrée « ' . ($mvt['motif'] ?? '?') . ' »)');
    refus_attendu(static fn() => cuvee_creer($donnees), 'existe déjà', 'Une cuvée en double (même nom, même millésime) est refusée');

    $donnees['prix_vente'] = 14.5;
    cuvee_modifier($id, $donnees);
    verifier((float) cuvee_trouver($id)['prix_vente'] === 14.5, 'La modification du prix est enregistrée');
    verifier(stock_de($id) === 48, 'La modification d\'une cuvée ne touche pas au stock');

    refus_attendu(static fn() => cuvee_supprimer($id), 'historique', 'Une cuvée qui a un historique de stock ne peut pas être supprimée');

    $idVierge = cuvee_creer(['nom' => 'Cuvée vierge', 'millesime' => 2025, 'couleur' => 'blanc', 'degre' => 12.0, 'prix_vente' => 9.0, 'seuil_alerte' => 5, 'stock_initial' => 0]);
    cuvee_supprimer($idVierge);
    verifier(cuvee_trouver($idVierge) === null && (int) requete_valeur('SELECT COUNT(*) FROM stock WHERE id_cuvee = ?', [$idVierge]) === 0,
        'Une cuvée sans aucun historique peut être supprimée (sa ligne de stock disparaît avec elle)');
    verifier(nb_ecarts() === 0, 'Cohérence du stock conservée');
});

// ===========================================================================
section('4. F2 : stock (entrées, seuil d\'alerte)');
// ===========================================================================
essayer(static function (): void {
    $cuvee = creer_cuvee_test('Cuvée stock', 20, 10.0, 5);
    $avant = instantane();

    $nouveau = stock_entree($cuvee, '50', 'Livraison de test');
    verifier($nouveau === 70 && stock_de($cuvee) === 70, 'Une entrée de 50 bouteilles porte le stock de 20 à 70');
    $mvt = requete_une("SELECT quantite, motif FROM mouvement_stock WHERE id_cuvee = ? AND type_mouvement = 'entree' ORDER BY id DESC LIMIT 1", [$cuvee]);
    verifier($mvt !== null && (int) $mvt['quantite'] === 50 && $mvt['motif'] === 'Livraison de test', 'L\'entrée est écrite dans le journal avec son motif');

    stock_entree($cuvee, '5', '');
    $motif = (string) requete_valeur("SELECT motif FROM mouvement_stock WHERE id_cuvee = ? ORDER BY id DESC LIMIT 1", [$cuvee]);
    verifier($motif === 'Mise en bouteille', 'Sans motif saisi, le motif par défaut est « Mise en bouteille »');

    $avantRefus = instantane();
    foreach (['0' => 'zéro', '-5' => 'négative', '1.5' => 'décimale', 'abc' => 'non numérique', '' => 'vide', '100001' => 'trop grande'] as $saisie => $nom) {
        refus_attendu(static fn() => stock_entree($cuvee, (string) $saisie, ''), 'quantité', "Une entrée de quantité $nom est refusée");
    }
    verifier(instantane() === $avantRefus, 'Les entrées refusées n\'ont rien modifié');
    refus_attendu(static fn() => stock_entree(999999, '5', ''), 'introuvable', 'Une entrée sur une cuvée inexistante est refusée');

    // Seuil d'alerte : 75 bouteilles, seuil 5
    $client = creer_client_test('Client seuil');
    $niveau = static fn(): ?string => requete_valeur('SELECT niveau FROM v_stock_bas WHERE id_cuvee = ?', [$cuvee]);
    verifier($niveau() === null, 'Avec 75 bouteilles (seuil 5), la cuvée n\'est pas en alerte');
    commande_creer($client, [['id_cuvee' => (string) $cuvee, 'quantite' => '70']]);
    verifier($niveau() === 'bas', 'Avec 5 bouteilles (= seuil), la cuvée passe en « stock bas »');
    commande_creer($client, [['id_cuvee' => (string) $cuvee, 'quantite' => '5']]);
    verifier($niveau() === 'rupture', 'Avec 0 bouteille, la cuvée passe en « rupture »');
    verifier(nb_ecarts() === 0, 'Cohérence du stock conservée');
});

// ===========================================================================
section('5. F3 : clients (validation, doublons, recherche)');
// ===========================================================================
essayer(static function (): void {
    [, $erreurs] = client_valider(['nom' => '', 'type_client' => 'vip', 'email' => 'pas-un-email', 'ville' => '']);
    verifier(array_keys($erreurs) === ['nom', 'type_client', 'email', 'ville'], 'Une saisie fausse produit une erreur par champ (' . implode(', ', array_keys($erreurs)) . ')');

    [$donnees, $erreurs] = client_valider(['nom' => ' Domaine  Test ', 'type_client' => 'pro', 'email' => 'Contact@Test-Domaine.FR', 'ville' => 'Dijon']);
    verifier(!$erreurs && $donnees['email'] === 'contact@test-domaine.fr' && $donnees['nom'] === 'Domaine Test', 'Une saisie correcte est acceptée (e-mail mis en minuscules, espaces réduits)');
    $id = client_creer($donnees);
    verifier($id > 0, 'Le client est créé');
    refus_attendu(static fn() => client_creer($donnees), 'existe déjà', 'Un client avec la même adresse e-mail est refusé');

    $donnees['ville'] = 'Beaune';
    client_modifier($id, $donnees);
    verifier(client_trouver($id)['ville'] === 'Beaune', 'La modification d\'un client est enregistrée');

    $trouves = clients_liste('halles');
    verifier(count($trouves) === 1 && $trouves[0]['nom'] === 'Cave des Halles', 'La recherche « halles » trouve « Cave des Halles »');
    $pros = clients_liste('', 'pro');
    verifier(count($pros) >= 6 && count(array_filter($pros, static fn(array $c): bool => $c['type_client'] !== 'pro')) === 0, 'Le filtre « Professionnel » ne renvoie que des professionnels');
    verifier(count(clients_liste('', 'particulier')) >= 9, 'Le filtre « Particulier » renvoie les particuliers');
});

// ===========================================================================
section('6. F4 + F5 : saisie d\'une commande, total, baisse du stock, prix recopié');
// ===========================================================================
$A = creer_cuvee_test('Cuvée A', 100, 12.50);
$B = creer_cuvee_test('Cuvée B', 40, 8.00);
$client = creer_client_test('Client commandes', 'pro');
essayer(static function () use ($A, $B, $client): void {
    $id = commande_creer($client, [
        ['id_cuvee' => (string) $A, 'quantite' => '12'],
        ['id_cuvee' => (string) $B, 'quantite' => '5'],
    ]);
    $detail = commande_detail($id);
    verifier($detail !== null && $detail['statut'] === 'validee' && count($detail['lignes']) === 2, 'La commande est créée avec 2 lignes, au statut « validée »');
    verifier(abs((float) $detail['total'] - 190.0) < 0.001, 'Le total est calculé automatiquement : 12 x 12,50 + 5 x 8,00 = 190,00 € (obtenu : ' . $detail['total'] . ')');
    verifier(stock_de($A) === 88 && stock_de($B) === 35, 'Le stock baisse : A passe de 100 à 88, B de 40 à 35');

    $sorties = requete_toutes("SELECT id_cuvee, quantite, motif FROM mouvement_stock WHERE id_commande = ? AND type_mouvement = 'sortie' ORDER BY id_cuvee", [$id]);
    verifier(count($sorties) === 2 && (int) $sorties[0]['quantite'] === 12 && (int) $sorties[1]['quantite'] === 5 && $sorties[0]['motif'] === 'Vente commande n°' . $id,
        'Deux mouvements de sortie sont écrits, liés à la commande');
    verifier(nb_ecarts() === 0, 'Stock = somme des mouvements après la commande');

    // Le prix est recopié dans la ligne : une hausse de tarif ne change pas la commande passée.
    $cuvee = cuvee_trouver($A);
    cuvee_modifier($A, ['nom' => $cuvee['nom'], 'millesime' => (int) $cuvee['millesime'], 'couleur' => $cuvee['couleur'], 'degre' => (float) $cuvee['degre'],
        'prix_vente' => 15.0, 'seuil_alerte' => (int) $cuvee['seuil_alerte'], 'stock_initial' => 0]);
    $apres = commande_detail($id);
    $ligneA = array_values(array_filter($apres['lignes'], static fn(array $l): bool => (int) $l['id_cuvee'] === $A))[0];
    verifier(abs((float) $ligneA['prix_unitaire'] - 12.5) < 0.001 && abs((float) $ligneA['prix_actuel'] - 15.0) < 0.001,
        'Après une hausse de tarif à 15,00 €, la commande garde son prix d\'origine (12,50 €)');
    verifier(abs((float) $apres['total'] - 190.0) < 0.001, 'Le total de la commande passée n\'a pas changé');

    $id2 = commande_creer($client, [['id_cuvee' => (string) $A, 'quantite' => '2']]);
    verifier(abs((float) commande_detail($id2)['total'] - 30.0) < 0.001, 'Une nouvelle commande utilise le nouveau tarif : 2 x 15,00 = 30,00 €');
});

// ===========================================================================
section('7. Règles de gestion et REFUS : une commande refusée ne modifie pas la base');
// ===========================================================================
essayer(static function () use ($A, $B, $client): void {
    $avant = instantane();

    refus_attendu(static fn() => commande_creer($client, [['id_cuvee' => (string) $A, 'quantite' => '1000']]),
        'Stock insuffisant', 'Une quantité supérieure au stock est refusée');
    verifier(instantane() === $avant, 'CRITÈRE : la commande refusée pour stock insuffisant n\'a modifié AUCUNE donnée (commandes, lignes, mouvements, stocks)');

    $stockB = stock_de($B);
    refus_attendu(static fn() => commande_creer($client, [
        ['id_cuvee' => (string) $B, 'quantite' => '1'],       // cette ligne seule serait acceptée...
        ['id_cuvee' => (string) $A, 'quantite' => '10000'],   // ...mais celle-ci est impossible
    ]), 'Stock insuffisant', 'Une commande dont UNE ligne dépasse le stock est refusée en entier');
    verifier(instantane() === $avant && stock_de($B) === $stockB, 'La ligne acceptable n\'a pas été enregistrée non plus (tout ou rien)');

    $restant = stock_de($A);
    refus_attendu(static fn() => commande_creer($client, [
        ['id_cuvee' => (string) $A, 'quantite' => (string) ($restant - 10)],
        ['id_cuvee' => (string) $A, 'quantite' => '20'],
    ]), 'Stock insuffisant', 'Deux lignes de la même cuvée sont additionnées avant de comparer au stock');
    verifier(instantane() === $avant, 'Aucune donnée modifiée');

    $cas = [
        'Aucune ligne'                          => [$client, [], 'au moins une ligne'],
        'Lignes toutes vides'                   => [$client, [['id_cuvee' => '', 'quantite' => ''], ['id_cuvee' => '', 'quantite' => '']], 'au moins une ligne'],
        'Quantité nulle'                        => [$client, [['id_cuvee' => (string) $A, 'quantite' => '0']], 'strictement positif'],
        'Quantité négative'                     => [$client, [['id_cuvee' => (string) $A, 'quantite' => '-3']], 'strictement positif'],
        'Quantité décimale'                     => [$client, [['id_cuvee' => (string) $A, 'quantite' => '1.5']], 'strictement positif'],
        'Quantité non numérique'                => [$client, [['id_cuvee' => (string) $A, 'quantite' => 'douze']], 'strictement positif'],
        'Quantité absente'                      => [$client, [['id_cuvee' => (string) $A, 'quantite' => '']], 'strictement positif'],
        'Quantité démesurée'                    => [$client, [['id_cuvee' => (string) $A, 'quantite' => '999999']], 'ne peut pas dépasser'],
        'Cuvée non choisie'                     => [$client, [['id_cuvee' => '', 'quantite' => '3']], 'choisissez une cuvée'],
        'Cuvée inexistante'                     => [$client, [['id_cuvee' => '999999', 'quantite' => '3']], 'n\'existe pas'],
        'Client non choisi'                     => [0, [['id_cuvee' => (string) $A, 'quantite' => '1']], 'Choisissez un client'],
        'Client inexistant'                     => [999999, [['id_cuvee' => (string) $A, 'quantite' => '1']], 'Choisissez un client'],
    ];
    foreach ($cas as $nom => [$idClient, $lignes, $fragment]) {
        refus_attendu(static fn() => commande_creer($idClient, $lignes), $fragment, "$nom : commande refusée");
    }
    verifier(instantane() === $avant, 'Aucun de ces refus n\'a modifié la base');

    // Pas de « trou » dans la numérotation après des refus : la prochaine commande suit la dernière.
    $suivante = commande_creer($client, [['id_cuvee' => (string) $B, 'quantite' => '1']]);
    verifier($suivante === $avant['dernier_numero'] + 1, 'La commande suivante reçoit le numéro ' . ($avant['dernier_numero'] + 1) . ' (aucun numéro perdu par les refus)');

    // Lignes vides ignorées, lignes identiques fusionnées
    $id = commande_creer($client, [
        ['id_cuvee' => '', 'quantite' => ''],
        ['id_cuvee' => (string) $B, 'quantite' => '3'],
        ['id_cuvee' => (string) $B, 'quantite' => '4'],
    ]);
    $lignes = commande_detail($id)['lignes'];
    verifier(count($lignes) === 1 && (int) $lignes[0]['quantite'] === 7, 'Une ligne vide est ignorée ; deux lignes de la même cuvée (3 + 4) sont fusionnées en une ligne de 7');

    // Commander exactement tout le stock est permis
    $reste = stock_de($B);
    $id = commande_creer($client, [['id_cuvee' => (string) $B, 'quantite' => (string) $reste]]);
    verifier($id > 0 && stock_de($B) === 0, "Commander exactement tout le stock ($reste bouteilles) est accepté : le stock tombe à 0");
    refus_attendu(static fn() => commande_creer($client, [['id_cuvee' => (string) $B, 'quantite' => '1']]), '0 disponible', 'Une cuvée en rupture ne peut plus être commandée');
    verifier(nb_ecarts() === 0, 'Stock = somme des mouvements après toutes ces opérations');
});

// ===========================================================================
section('8. Annulation d\'une commande');
// ===========================================================================
essayer(static function () use ($A, $client): void {
    $stockAvant = stock_de($A);
    $caAvant = (float) requete_valeur('SELECT chiffre_affaires FROM v_ca_par_cuvee WHERE id_cuvee = ?', [$A]);
    $id = commande_creer($client, [['id_cuvee' => (string) $A, 'quantite' => '10']]);
    verifier(stock_de($A) === $stockAvant - 10, 'La commande de 10 bouteilles fait baisser le stock');
    verifier(abs((float) requete_valeur('SELECT chiffre_affaires FROM v_ca_par_cuvee WHERE id_cuvee = ?', [$A]) - ($caAvant + 150.0)) < 0.001, 'Le chiffre d\'affaires de la cuvée augmente de 10 x 15,00 €');

    commande_annuler($id);
    verifier(stock_de($A) === $stockAvant, 'L\'annulation remet les 10 bouteilles en stock');
    verifier(commande_detail($id)['statut'] === 'annulee', 'La commande passe au statut « annulée » (elle n\'est pas supprimée)');
    verifier(abs((float) requete_valeur('SELECT chiffre_affaires FROM v_ca_par_cuvee WHERE id_cuvee = ?', [$A]) - $caAvant) < 0.001, 'Le chiffre d\'affaires ne compte plus la commande annulée');
    $retour = requete_une("SELECT quantite, motif FROM mouvement_stock WHERE id_commande = ? AND type_mouvement = 'entree'", [$id]);
    verifier($retour !== null && (int) $retour['quantite'] === 10 && $retour['motif'] === 'Annulation commande n°' . $id, 'Un mouvement d\'entrée « Annulation commande » est écrit');

    $avant = instantane();
    refus_attendu(static fn() => commande_annuler($id), 'déjà annulée', 'Une commande déjà annulée ne peut pas l\'être une seconde fois');
    refus_attendu(static fn() => commande_annuler(999999), 'introuvable', 'Annuler une commande inexistante est refusé');
    verifier(instantane() === $avant, 'Ces refus n\'ont rien modifié (le stock n\'est pas remis deux fois)');
    verifier(nb_ecarts() === 0, 'Stock = somme des mouvements après annulation');
});

// ===========================================================================
section('9. F8 : filtres de la liste des commandes (comparés à un comptage SQL direct)');
// ===========================================================================
essayer(static function (): void {
    $total = (int) requete_valeur('SELECT COUNT(*) FROM commande');
    verifier(count(commandes_liste()) === $total, "Sans filtre : toutes les commandes ($total)");

    $annulees = (int) requete_valeur("SELECT COUNT(*) FROM commande WHERE statut = 'annulee'");
    verifier(count(commandes_liste(['statut' => 'annulee'])) === $annulees && $annulees >= 3, "Filtre statut « annulée » : $annulees commandes");

    $mars = (int) requete_valeur("SELECT COUNT(*) FROM commande WHERE date_commande >= '2026-03-01 00:00:00' AND date_commande <= '2026-03-31 23:59:59'");
    verifier(count(commandes_liste(['du' => '2026-03-01', 'au' => '2026-03-31'])) === $mars && $mars > 0, "Filtre par date (mars 2026) : $mars commandes");

    $c1 = (int) requete_valeur('SELECT COUNT(*) FROM commande WHERE id_client = 1');
    verifier(count(commandes_liste(['id_client' => 1])) === $c1 && $c1 > 0, "Filtre par client n°1 : $c1 commandes");

    $cuvee3 = (int) requete_valeur('SELECT COUNT(DISTINCT id_commande) FROM ligne_commande WHERE id_cuvee = 3');
    verifier(count(commandes_liste(['id_cuvee' => 3])) === $cuvee3 && $cuvee3 > 0, "Filtre par cuvée n°3 : $cuvee3 commandes");

    $combine = (int) requete_valeur("SELECT COUNT(DISTINCT co.id) FROM commande co JOIN ligne_commande l ON l.id_commande = co.id WHERE co.id_client = 1 AND co.statut = 'validee' AND l.id_cuvee = 3");
    verifier(count(commandes_liste(['id_client' => 1, 'id_cuvee' => 3, 'statut' => 'validee'])) === $combine, "Filtres combinés (client + cuvée + statut) : $combine commandes");

    verifier(count(commandes_liste(['du' => 'pas-une-date', 'statut' => 'inconnu'])) === $total, 'Une date invalide ou un statut inconnu sont ignorés (pas d\'erreur, pas d\'injection)');
});

// ===========================================================================
section('10. F6 : tableau de bord (recoupé avec des requêtes SQL directes)');
// ===========================================================================
essayer(static function (): void {
    $caSql = (float) requete_valeur("SELECT SUM(l.quantite * l.prix_unitaire) FROM ligne_commande l JOIN commande co ON co.id = l.id_commande WHERE co.statut = 'validee'");
    $kpi = dashboard_kpis();
    verifier(abs($kpi['ca'] - $caSql) < 0.005, 'Chiffre d\'affaires du tableau de bord = somme des lignes des commandes validées (' . money($caSql) . ')');

    $parCuvee = dashboard_ca_par_cuvee();
    $somme = array_sum(array_map(static fn(array $l): float => (float) $l['chiffre_affaires'], $parCuvee));
    verifier(abs($somme - $caSql) < 0.005, 'La somme du CA par cuvée est égale au CA total (aucune vente oubliée ni comptée deux fois)');
    verifier($parCuvee === array_values($parCuvee) && (float) $parCuvee[0]['chiffre_affaires'] >= (float) $parCuvee[count($parCuvee) - 1]['chiffre_affaires'], 'Le classement est trié du plus fort CA au plus faible');

    $clients = dashboard_meilleurs_clients(5);
    $tries = true;
    for ($i = 1; $i < count($clients); $i++) {
        $tries = $tries && (float) $clients[$i - 1]['chiffre_affaires'] >= (float) $clients[$i]['chiffre_affaires'];
    }
    verifier(count($clients) === 5 && $tries, 'Les 5 meilleurs clients sont renvoyés, du plus fort CA au plus faible');

    verifier(count(dashboard_stock_bas()) === (int) requete_valeur('SELECT COUNT(*) FROM stock WHERE quantite <= seuil_alerte'), 'La liste « stock bas » correspond au comptage direct (quantité <= seuil)');

    $mensuel = array_sum(array_map(static fn(array $m): float => (float) $m['chiffre_affaires'], dashboard_ca_mensuel()));
    verifier($mensuel > 0 && $mensuel <= $caSql + 0.005, 'Le chiffre d\'affaires mensuel est cohérent avec le total');
});

// ===========================================================================
section('11. Requêtes d\'analyse du fichier database/04_requetes.sql');
// ===========================================================================
essayer(static function (): void {
    $script = (string) file_get_contents(APP_ROOT . '/database/04_requetes.sql');
    $lignes = array_filter(explode("\n", str_replace("\r", '', $script)), static fn(string $l): bool => !str_starts_with(ltrim($l), '--'));
    $nb = 0;
    $resultats = [];
    foreach (preg_split('/;\s*(\n|$)/', implode("\n", $lignes)) ?: [] as $instruction) {
        $instruction = trim($instruction);
        if (stripos($instruction, 'SELECT') === 0) {
            $resultats[] = requete_toutes($instruction);   // une erreur SQL lèverait une exception
            $nb++;
        }
    }
    verifier($nb === 10, "Les 10 requêtes R1 à R10 s'exécutent sans erreur (trouvées : $nb)");

    // R8 (cuvées jamais vendues) doit désigner exactement les cuvées à 0 bouteille vendue dans la vue du CA (R1).
    $r8 = array_map(static fn(array $l): string => $l['nom'] . ' ' . $l['millesime'], $resultats[7] ?? []);
    $r1 = array_map(
        static fn(array $l): string => $l['nom'] . ' ' . $l['millesime'],
        requete_toutes('SELECT nom, millesime FROM v_ca_par_cuvee WHERE bouteilles_vendues = 0 ORDER BY nom')
    );
    verifier($r8 === $r1, 'R8 (jamais vendues) = cuvées à 0 bouteille vendue dans v_ca_par_cuvee : ' . implode(', ', $r8));
    verifier(($resultats[8] ?? null) === [], 'R9 (contrôle d\'intégrité) ne retourne aucune ligne');
});

// ===========================================================================
section('12. Commandes simultanées (plusieurs utilisateurs en même temps)');
// ===========================================================================
essayer(static function (): void {
    $client = creer_client_test('Client concurrence');

    $D = creer_cuvee_test('Cuvée dernière-bouteilles', 10);
    $res = lancer_en_parallele([[$client, $D, 6], [$client, $D, 6]]);
    $ok = count(array_filter($res, static fn(array $r): bool => $r['ok'] === true));
    $techniques = count(array_filter($res, static fn(array $r): bool => isset($r['technique'])));
    verifier($techniques === 0, 'Aucune erreur technique pendant la course' . ($techniques ? ' : ' . json_encode($res) : ''));
    verifier($ok === 1, "Deux commandes de 6 bouteilles en même temps sur un stock de 10 : exactement UNE est acceptée (acceptées : $ok)");
    verifier(stock_de($D) === 4, 'Le stock final est de 4 bouteilles (10 - 6) : pas de survente');

    $E = creer_cuvee_test('Cuvée huit-acheteurs', 5);
    $res = lancer_en_parallele(array_fill(0, 8, [$client, $E, 1]));
    $ok = count(array_filter($res, static fn(array $r): bool => $r['ok'] === true));
    $techniques = count(array_filter($res, static fn(array $r): bool => isset($r['technique'])));
    verifier($techniques === 0, 'Aucune erreur technique avec 8 acheteurs simultanés' . ($techniques ? ' : ' . json_encode($res) : ''));
    verifier($ok === 5, "8 acheteurs d'une bouteille en même temps sur un stock de 5 : exactement 5 commandes acceptées (acceptées : $ok)");
    verifier(stock_de($E) === 0, 'Le stock final est de 0 : jamais négatif');
    verifier(nb_ecarts() === 0, 'Stock = somme des mouvements après les courses');
});

// ===========================================================================
section('13. Sécurité : injections SQL, injection de HTML, saisies hostiles');
// ===========================================================================
essayer(static function (): void {
    $attaques = ["' OR '1'='1", "x'; DROP TABLE cuvee; --", '" OR ""="', '%', '_', '\\', "Robert'); DELETE FROM client; --"];
    foreach ($attaques as $texte) {
        $clients = clients_liste($texte);
        $cuvees = cuvees_liste($texte);
        $stock = stock_liste($texte);
        verifier(count($clients) === 0 && count($cuvees) === 0 && count($stock) === 0, 'La recherche « ' . $texte . ' » est traitée comme du simple texte (0 résultat, aucune erreur)');
    }
    verifier((int) requete_valeur('SELECT COUNT(*) FROM cuvee') >= 20 && (int) requete_valeur('SELECT COUNT(*) FROM client') >= 15, 'Les tables sont intactes après les tentatives d\'injection');

    verifier(count(commandes_liste(['statut' => "validee' OR '1'='1"])) === (int) requete_valeur('SELECT COUNT(*) FROM commande'), 'Un statut piégé est ignoré (liste blanche), sans erreur SQL');
    verifier(count(commandes_liste(['id_client' => (int) '1 OR 1=1'])) === (int) requete_valeur('SELECT COUNT(*) FROM commande WHERE id_client = 1'), 'Un identifiant piégé est converti en nombre');

    $client = creer_client_test('Client injection');
    $cuvee = creer_cuvee_test('Cuvée injection', 10);
    refus_attendu(static fn() => commande_creer($client, [['id_cuvee' => $cuvee . ' OR 1=1', 'quantite' => '1']]), 'choisissez une cuvée', 'Un identifiant de cuvée piégé dans une commande est refusé');
    refus_attendu(static fn() => commande_creer($client, [['id_cuvee' => (string) $cuvee, 'quantite' => '1; DROP TABLE commande']]), 'strictement positif', 'Une quantité piégée dans une commande est refusée');
    refus_attendu(static fn() => commande_creer($client, [['id_cuvee' => [(string) $cuvee], 'quantite' => ['1']]]), 'saisie invalide', 'Des tableaux envoyés à la place de textes sont refusés');

    // Texte hostile enregistré tel quel en base, mais jamais interprété à l'affichage
    $hostile = '<script>alert("x")</script>';
    $idClient = client_creer(['nom' => $hostile, 'type_client' => 'pro', 'email' => 'xss-' . bin2hex(random_bytes(3)) . '@example.com', 'ville' => '<b>Lyon</b>']);
    $relu = client_trouver($idClient);
    verifier($relu['nom'] === $hostile, 'Le texte saisi est conservé tel quel en base (pas de bricolage à l\'enregistrement)');
    verifier(!str_contains(e($relu['nom']), '<') && str_contains(e($relu['nom']), '&lt;script&gt;'), 'À l\'affichage, e() neutralise le HTML : « ' . e($relu['nom']) . ' »');
    verifier(e("l'été \"ok\"") === 'l&#039;été &quot;ok&quot;', 'e() échappe aussi les apostrophes et guillemets (attributs HTML)');
});

// ===========================================================================
section('14. Petites fonctions utilitaires');
// ===========================================================================
essayer(static function (): void {
    verifier(lire_entier('12') === 12 && lire_entier(' 12 ') === 12 && lire_entier('1 000') === 1000, 'lire_entier : « 12 », « 12  », « 1 000 » sont acceptés');
    verifier(lire_entier('-3') === null && lire_entier('1.5') === null && lire_entier('abc') === null && lire_entier('') === null && lire_entier('1e3') === null, 'lire_entier : négatif, décimal, texte, vide et notation scientifique sont refusés');
    verifier(lire_decimal('12,5') === 12.5 && lire_decimal('12.50') === 12.5 && lire_decimal('1e3') === null && lire_decimal('-1') === null, 'lire_decimal : virgule ou point acceptés, notation scientifique et négatif refusés');
    verifier(lire_date('2026-02-28') === '2026-02-28' && lire_date('2026-02-30') === null && lire_date('28/02/2026') === null, 'lire_date : seules les dates réelles au format AAAA-MM-JJ sont acceptées');
    verifier(echapper_like('50%_off\\') === '50\\%\\_off\\\\', 'echapper_like : % et _ perdent leur sens de joker');
    $nbsp = "\u{00A0}";
    verifier(pluriel(0, 'bouteille') === "0{$nbsp}bouteille" && pluriel(1, 'bouteille') === "1{$nbsp}bouteille" && pluriel(2, 'bouteille') === "2{$nbsp}bouteilles", 'pluriel : 0 et 1 au singulier, 2 et plus au pluriel');
    verifier(money(1234.5) === "1{$nbsp}234,50{$nbsp}€", 'money : 1 234,50 €');
    verifier(niveau_stock(0, 24) === 'rupture' && niveau_stock(24, 24) === 'bas' && niveau_stock(25, 24) === 'ok', 'niveau_stock : rupture à 0, bas au seuil, ok au-dessus');
});

// ===========================================================================
section('15. Transactions imbriquées (SAVEPOINT) : la base du « tout ou rien » de l\'import');
// ===========================================================================
essayer(static function (): void {
    $pdo = db();
    $nbClients = static fn(): int => (int) requete_valeur('SELECT COUNT(*) FROM client');
    $mail = static fn(string $p): string => $p . '-' . bin2hex(random_bytes(4)) . '@example.com';
    $insere = static fn(string $nom, string $email): int => requete_executer("INSERT INTO client (nom, type_client, email, ville) VALUES (?, 'pro', ?, 'Lyon')", [$nom, $email]);
    $avant = $nbClients();

    // a) Une opération interne qui échoue est annulée seule ; la transaction englobante continue et valide le reste.
    en_transaction($pdo, static function (PDO $pdo) use ($insere, $mail, $nbClients, $avant): void {
        $insere('Imbriqué A', $mail('a'));
        try {
            en_transaction($pdo, static function () use ($insere, $mail): void {
                $insere('Imbriqué B', $mail('b'));
                throw new RuntimeException('échec volontaire');
            });
        } catch (RuntimeException) {
            // attendu
        }
        verifier($pdo->inTransaction(), 'Après l\'échec de l\'opération interne, la transaction englobante est toujours ouverte');
        verifier($nbClients() === $avant + 1, 'L\'opération interne (B) est annulée, la première écriture (A) est conservée');
        $insere('Imbriqué C', $mail('c'));
    });
    verifier(!$pdo->inTransaction() && $nbClients() === $avant + 2, 'Validation finale : A et C sont enregistrés, B ne l\'est pas');

    // b) Opération interne réussie, puis échec de l'englobante : tout est annulé, y compris ce qui avait réussi.
    try {
        en_transaction($pdo, static function (PDO $pdo) use ($insere, $mail): void {
            en_transaction($pdo, static fn() => $insere('Imbriqué D', $mail('d')));
            throw new RuntimeException('échec de l\'englobante');
        });
    } catch (RuntimeException) {
        // attendu
    }
    verifier($nbClients() === $avant + 2 && !$pdo->inTransaction(), 'Si la transaction englobante échoue, même l\'opération interne réussie (D) est annulée');

    // c) Les fonctions métier gardent leurs messages de refus à l'intérieur d'une transaction englobante.
    $dejaPris = $mail('pris');
    client_creer(['nom' => 'Premier', 'type_client' => 'pro', 'email' => $dejaPris, 'ville' => 'Lyon']);
    en_transaction($pdo, static function (PDO $pdo) use ($dejaPris): void {
        refus_attendu(static fn() => client_creer(['nom' => 'Second', 'type_client' => 'pro', 'email' => $dejaPris, 'ville' => 'Lyon']), 'existe déjà', 'Un doublon est refusé avec son message, même dans une transaction englobante');
        creer_cuvee_test('Cuvée imbriquée', 12);
        refus_attendu(static fn() => creer_cuvee_test('Cuvée imbriquée', 12), 'existe déjà', 'Un doublon de cuvée est refusé (point de sauvegarde annulé)');
        verifier($pdo->inTransaction(), 'La transaction englobante reste ouverte après ces refus');
    });
    verifier((int) requete_valeur("SELECT COUNT(*) FROM cuvee WHERE nom = 'Cuvée imbriquée'") === 1 && nb_ecarts() === 0, 'Une seule « Cuvée imbriquée » est enregistrée, stock cohérent');
});

// ===========================================================================
section('16. F7 + F9 : outils CSV (lecture tolérante, écriture « Excel français »)');
// ===========================================================================
essayer(static function (): void {
    // --- Encodages ---
    [$t, $enc] = csv_decoder("\xEF\xBB\xBFNom;Ville\r\nÉlodie;Orléans\r\n");
    verifier($enc === 'UTF-8' && str_starts_with($t, 'Nom;Ville') && !str_contains($t, "\r") && !str_contains($t, "\xEF\xBB\xBF"), 'UTF-8 avec BOM : BOM retiré, fins de ligne CRLF ramenées à une seule forme');
    [$t, $enc] = csv_decoder("Nom;Ville\nÉlodie;Orléans");
    verifier($enc === 'UTF-8' && $t === "Nom;Ville\nÉlodie;Orléans", 'UTF-8 sans BOM reconnu tel quel');
    [$t, $enc] = csv_decoder(mb_convert_encoding("Élodie;Orléans;œuf", 'Windows-1252', 'UTF-8'));
    verifier($enc === 'Windows-1252' && $t === 'Élodie;Orléans;œuf', 'Windows-1252 (Excel français) reconnu et converti : accents et « œ » intacts');
    [$t, $enc] = csv_decoder("\xFF\xFE" . mb_convert_encoding("Zoé\tNîmes", 'UTF-16LE', 'UTF-8'));
    verifier($enc === 'UTF-16' && $t === "Zoé\tNîmes", 'UTF-16 avec BOM (« Texte Unicode » d\'Excel) reconnu et converti');
    [$t] = csv_decoder("a;b\rc;d\r\ne;f");
    verifier($t === "a;b\nc;d\ne;f", 'Les fins de ligne CR (Mac) et CRLF (Windows) sont ramenées à LF');
    refus_attendu(static fn() => csv_decoder("PK\x03\x04\x14\0\x06\0binaire"), 'données binaires', 'Un fichier binaire (classeur .xlsx, PDF, image) est refusé avec une explication');

    // --- Séparateur ---
    verifier(csv_separateur("a;b;c\n1;2;3") === ';', 'Séparateur détecté : point-virgule');
    verifier(csv_separateur("a,b,c\n1,2,3") === ',', 'Séparateur détecté : virgule');
    verifier(csv_separateur("a\tb\tc\n1\t2\t3") === "\t", 'Séparateur détecté : tabulation');
    verifier(csv_separateur("\n\n a;b;c") === ';', 'Les lignes vides avant les titres sont sautées');
    verifier(csv_separateur('"a;b;c",d,e') === ',', 'Un séparateur placé entre guillemets n\'est pas compté');
    verifier(csv_separateur('nom;prix,unitaire;ville') === ';', 'Le séparateur le plus fréquent l\'emporte');
    verifier(csv_separateur('seul') === ';', 'Sans aucun séparateur : point-virgule par défaut');

    // --- Lecture ---
    $l = csv_lire("a;b\n1;2\n", ';');
    verifier($l === [['ligne' => 1, 'champs' => ['a', 'b']], ['ligne' => 2, 'champs' => ['1', '2']]], 'csv_lire : lignes numérotées à partir de 1 (comme dans Excel)');
    $l = csv_lire("a;b\n\n   ;  \n1;2\n", ';');
    verifier(count($l) === 2 && $l[1]['ligne'] === 4, 'Les lignes vides sont ignorées, mais comptées dans la numérotation (la ligne « 1;2 » est bien la ligne 4)');
    $l = csv_lire('"x;y";"dit ""oui""";"l1' . "\n" . 'l2"' . "\n", ';');
    verifier($l[0]['champs'] === ['x;y', 'dit "oui"', "l1\nl2"], 'Guillemets : séparateur protégé, guillemet doublé, saut de ligne dans une valeur');
    $l = csv_lire("a;b;;\n", ';');
    verifier($l[0]['champs'] === ['a', 'b', '', ''], 'Séparateurs en fin de ligne (Excel) : deux colonnes vides');
    $l = csv_lire("x;C:\\temp\\\ny;z\n", ';');
    verifier(count($l) === 2 && $l[0]['champs'] === ['x', 'C:\\temp\\'], 'Une barre oblique inverse en fin de valeur ne casse pas la lecture (pas de caractère d\'échappement)');

    // --- Écriture ---
    verifier(csv_ligne(['a', 'b;c', 'd"e', ' x', 'y ', "l1\nl2", '']) === "a;\"b;c\";\"d\"\"e\";\" x\";\"y \";\"l1\nl2\";\r\n", 'csv_ligne : guillemets seulement quand c\'est nécessaire, CRLF en fin de ligne');
    verifier(csv_formater(12.5, 'decimal') === '12,50' && csv_formater('9', 'decimal') === '9,00', 'csv_formater : montants avec virgule décimale et 2 décimales');
    verifier(csv_formater(13, 'degre') === '13,0' && csv_formater('42', 'entier') === '42' && csv_formater(null, 'entier') === '' && csv_formater(null) === '', 'csv_formater : degré à 1 décimale, entier, valeur absente = cellule vide');
    $dangereux = ['=HYPERLINK("http://x")', '+1+1', '@SUM(A1)', '-2+3', "\t=1", ' =2', '--x'];
    $neutralises = array_filter($dangereux, static fn(string $t): bool => csv_formater($t) === "'" . $t);
    verifier(count($neutralises) === count($dangereux), 'Injection de formule : les textes commençant par = + - @ ou une tabulation reçoivent une apostrophe (' . count($neutralises) . '/' . count($dangereux) . ')');
    verifier(csv_formater('-5') === '-5' && csv_formater('+3,5') === '+3,5' && csv_formater('Normal') === 'Normal' && csv_formater("O'Brien") === "O'Brien", 'Un nombre seul ou un texte ordinaire n\'est pas modifié');

    // --- Aller-retour : ce que csv_document écrit, csv_decoder + csv_lire le relisent à l'identique ---
    $entetes = ['Nom', 'Ville', 'Note'];
    $lignes = [
        ['Élodie "la rouge"', 'Saint-Étienne', 'a;b'],
        [' espaces ', "deux\nlignes", ''],
        ['œuf', '€ 12,50', 'fin'],
    ];
    $doc = csv_document($entetes, $lignes);
    verifier(str_starts_with($doc, "\xEF\xBB\xBF") && str_ends_with($doc, "\r\n") && substr_count($doc, "\r\n") === 4, 'csv_document : BOM UTF-8 au début, une fin de ligne CRLF par ligne (le saut interne de la valeur multiligne reste un simple LF)');
    [$relu] = csv_decoder($doc);
    $enr = csv_lire($relu, csv_separateur($relu));
    verifier(array_column($enr, 'champs') === array_merge([$entetes], $lignes), 'Aller-retour écriture puis lecture : toutes les valeurs reviennent à l\'identique (guillemets, accents, espaces, saut de ligne)');
});

// ===========================================================================
section('17. F7 : import CSV de clients (validation ligne par ligne, rapport des refus)');
// ===========================================================================
essayer(static function (): void {
    $csv = static fn(array ...$lignes): string => implode('', array_map('csv_ligne', $lignes));
    $nbClients = static fn(): int => (int) requete_valeur('SELECT COUNT(*) FROM client');
    $motifs = static fn(array $r): array => array_column($r['rejets'], 'motifs', 'ligne');
    $texteMotifs = static fn(array $r, int $ligne): string => implode(' ', $motifs($r)[$ligne] ?? []);
    $p = bin2hex(random_bytes(3));   // préfixe unique : les adresses du test ne rencontrent jamais celles d'un autre passage

    $fichier = $csv(
        ['Nom', 'Type de client', 'E-mail', 'Ville'],
        ['Atelier ' . $p, 'Professionnel', "atelier.$p@example.org", 'Colmar'],              // 2 : valide (« Professionnel » accepté)
        ['Paul ' . $p, 'PARTICULIER', strtoupper("paul.$p") . '@Example.com', 'Metz'],       // 3 : valide (type et e-mail normalisés)
        ['Sophie Lambert', 'particulier', 'sophie.lambert@example.com', 'Nantes'],           // 4 : e-mail déjà en base (client de démonstration)
        ['Clara ' . $p, 'particulier', "clara.$p.example.com", 'Pau'],                       // 5 : e-mail sans @
        ['', 'pro', "sans.nom.$p@example.org", 'Rouen'],                                     // 6 : nom manquant
        ['Épicerie ' . $p, 'grossiste', "port.$p@example.org", 'Brest'],                     // 7 : type inconnu
        ['Lucie ' . $p, 'particulier', "lucie.$p@example.com", ''],                          // 8 : ville manquante
        ['Paul bis ' . $p, 'particulier', "paul.$p@example.com", 'Metz'],                    // 9 : même e-mail que la ligne 3
        ['Julie ' . $p, 'particulier', "julie.$p@example.com", 'Angers']                     // 10 : valide
    );

    // --- Simulation : tout est vérifié, rien n'est écrit ---
    $avant = $nbClients();
    $sim = import_executer($fichier, 'clients', true);
    verifier($nbClients() === $avant, 'Simulation : aucun client n\'est enregistré');
    verifier($sim['simulation'] && $sim['nb_lues'] === 9 && $sim['nb_ok'] === 3 && count($sim['rejets']) === 6, 'Simulation : 9 lignes lues, 3 valides, 6 refusées');

    // --- Import réel ---
    $r = import_executer($fichier, 'clients');
    verifier($nbClients() === $avant + 3 && $r['nb_ok'] === 3 && $r['nb_lues'] === 9, 'Import : les 3 lignes valides sont enregistrées, les 6 autres ne le sont pas');
    verifier($r['encodage'] === 'UTF-8' && $r['separateur'] === ';' && $r['colonnes_ignorees'] === [], 'Format reconnu : UTF-8, point-virgule, aucune colonne ignorée');
    verifier(array_keys($motifs($r)) === [4, 5, 6, 7, 8, 9], 'Les lignes refusées portent leur numéro de ligne du fichier (4 à 9, la ligne de titres est la ligne 1)');
    verifier(str_contains($texteMotifs($r, 4), 'existe déjà'), 'Ligne 4 : e-mail déjà utilisé par un client de la base');
    verifier(str_contains($texteMotifs($r, 5), 'pas valide') && str_contains($texteMotifs($r, 5), "clara.$p.example.com"), 'Ligne 5 : e-mail invalide, avec la valeur lue dans le fichier');
    verifier(str_contains($texteMotifs($r, 6), 'nom est obligatoire'), 'Ligne 6 : nom manquant');
    verifier(str_contains($texteMotifs($r, 7), 'Type de client inconnu « grossiste »'), 'Ligne 7 : type de client inconnu, avec la valeur lue');
    verifier(str_contains($texteMotifs($r, 8), 'ville est obligatoire'), 'Ligne 8 : ville manquante');
    verifier(str_contains($texteMotifs($r, 9), 'Doublon') && str_contains($texteMotifs($r, 9), 'ligne 3'), 'Ligne 9 : doublon dans le fichier, qui renvoie à la ligne 3');
    $ligne3 = requete_une('SELECT nom, type_client, email FROM client WHERE email = ?', ["paul.$p@example.com"]);
    verifier($ligne3 !== null && $ligne3['type_client'] === 'particulier', 'Ligne 3 : « PARTICULIER » et l\'e-mail en majuscules sont normalisés (type = particulier, e-mail en minuscules)');
    verifier(requete_valeur('SELECT type_client FROM client WHERE email = ?', ["atelier.$p@example.org"]) === 'pro', 'Ligne 2 : « Professionnel » devient « pro »');
    verifier(str_contains(import_bilan($r), "3\u{00A0}clients ajoutés") && str_contains(import_bilan($r), "6\u{00A0}lignes refusées"), 'Phrase de bilan : « ' . import_bilan($r) . ' »');
    verifier(str_contains(import_bilan($sim), 'Simulation') && str_contains(import_bilan($sim), 'Rien n\'a été enregistré'), 'Phrase de bilan de la simulation : « ' . import_bilan($sim) . ' »');

    // --- Réimporter le même fichier : tout est refusé comme doublon, la base ne bouge pas ---
    $avant = $nbClients();
    $deuxieme = import_executer($fichier, 'clients');
    verifier($deuxieme['nb_ok'] === 0 && count($deuxieme['rejets']) === 9 && $nbClients() === $avant, 'Réimport du même fichier : 0 ajouté, 9 refusées, base inchangée');

    // --- Le fichier des lignes refusées se corrige et se réimporte tel quel ---
    [$entetesRejets, $lignesRejets] = import_rejets_pour_csv($r);
    verifier(end($entetesRejets) === 'motif_du_rejet' && count($lignesRejets) === 6, 'Fichier des refus : colonnes d\'origine + « motif_du_rejet », une ligne par refus');
    $corrige = [];
    foreach ($lignesRejets as $i => $cellules) {
        if (str_contains($cellules[2], 'clara.')) {                  // ligne 5 : on répare l'e-mail
            $cellules[2] = "clara.$p@example.com";
        } elseif ($cellules[0] === '') {                           // ligne 6 : on ajoute le nom
            $cellules[0] = 'Nora ' . $p;
        } elseif (str_contains($cellules[0], 'Épicerie')) {        // ligne 7 : on corrige le type
            $cellules[1] = 'pro';
        } elseif (str_contains($cellules[0], 'Lucie')) {           // ligne 8 : on ajoute la ville
            $cellules[3] = 'Tours';
        } else {
            continue;                                              // lignes 4 et 9 : vrais doublons, on les laisse
        }
        $corrige[] = $cellules;
    }
    $avant = $nbClients();
    $r2 = import_executer(csv_document($entetesRejets, array_merge($corrige, [$lignesRejets[0], $lignesRejets[5]])), 'clients');
    verifier($r2['nb_ok'] === 4 && count($r2['rejets']) === 2 && $nbClients() === $avant + 4, 'Après correction, le fichier des refus est réimporté : 4 ajoutés, les 2 vrais doublons restent refusés');
    verifier($r2['colonnes_ignorees'] === ['motif_du_rejet'], 'La colonne « motif_du_rejet » est ignorée à l\'import et signalée comme telle');

    // --- Titres de colonnes : ordre libre, accents, majuscules, colonnes inconnues, séparateurs et encodages ---
    $avant = $nbClients();
    $r = import_executer($csv(['VILLE', 'Courriel', 'Commentaire', 'nom', 'type'], ['Dijon', "ordre.$p@example.com", 'ignoré', 'Ordre libre ' . $p, 'pro']), 'clients');
    verifier($r['nb_ok'] === 1 && $r['colonnes_ignorees'] === ['Commentaire'] && $nbClients() === $avant + 1, 'Colonnes dans un autre ordre, titres variantes (« Courriel », « type »), colonne inconnue ignorée et signalée');
    $r = import_executer("nom,type,email,ville\n\"Dupont, Jean $p\",particulier,jean.$p@example.com,\"Saint-Étienne\"\n", 'clients');
    verifier($r['separateur'] === ',' && $r['nb_ok'] === 1 && requete_valeur('SELECT nom FROM client WHERE email = ?', ["jean.$p@example.com"]) === "Dupont, Jean $p", 'Fichier séparé par des virgules, valeur entre guillemets contenant une virgule');
    $r = import_executer("Nom\tType\tEmail\tVille\nTab $p\tpro\ttab.$p@example.com\tNîmes\n", 'clients');
    verifier($r['separateur'] === "\t" && $r['nb_ok'] === 1, 'Fichier séparé par des tabulations');
    $r = import_executer(mb_convert_encoding("Nom;Type;Email;Ville\nÉlodie $p;pro;elodie.$p@example.com;Orléans\n", 'Windows-1252', 'UTF-8'), 'clients');
    verifier($r['encodage'] === 'Windows-1252' && $r['nb_ok'] === 1 && requete_valeur('SELECT ville FROM client WHERE email = ?', ["elodie.$p@example.com"]) === 'Orléans', 'Fichier Windows-1252 : « Orléans » arrive avec ses accents');
    $r = import_executer("\xFF\xFE" . mb_convert_encoding("Nom\tType\tEmail\tVille\r\nSeize $p\tpro\tseize.$p@example.com\tNîmes\r\n", 'UTF-16LE', 'UTF-8'), 'clients');
    verifier($r['encodage'] === 'UTF-16' && $r['nb_ok'] === 1, 'Fichier UTF-16 (« Texte Unicode » d\'Excel)');
    $r = import_executer($csv(['Nom', 'Type', 'Email', 'Ville'], ['Court ' . $p, 'pro', "court.$p@example.com"]), 'clients');
    verifier($r['nb_ok'] === 0 && str_contains($texteMotifs($r, 2), 'ville est obligatoire'), 'Ligne plus courte que les titres : la valeur manquante est signalée (ville obligatoire)');
    $r = import_executer($csv(['Nom', 'Type', 'Email', 'Ville'], ['Long ' . $p, 'pro', "long.$p@example.com", 'Lyon', 'extra', 'encore']), 'clients');
    verifier($r['nb_ok'] === 0 && str_contains($texteMotifs($r, 2), '6 colonnes alors que la première ligne en annonce 4'), 'Ligne plus longue que les titres : refusée, avec le conseil d\'entourer la valeur de guillemets');

    // --- Fichiers inutilisables : refusés en bloc, avec une explication, sans rien écrire ---
    $avant = $nbClients();
    refus_attendu(static fn() => import_executer('', 'clients'), 'fichier est vide', 'Fichier vide refusé');
    refus_attendu(static fn() => import_executer("\xEF\xBB\xBF\r\n\r\n", 'clients'), 'fichier est vide', 'Fichier ne contenant que des lignes vides refusé');
    refus_attendu(static fn() => import_executer("PK\x03\x04\0\0binaire", 'clients'), 'données binaires', 'Fichier binaire (.xlsx) refusé');
    refus_attendu(static fn() => import_executer($csv(['Nom', 'Ville'], ['A', 'B']), 'clients'), 'obligatoires absentes', 'Colonnes obligatoires manquantes : refus qui cite les titres attendus');
    refus_attendu(static fn() => import_executer($csv(['Nom', 'Ville']), 'clients'), 'Type de client, E-mail', 'Le refus cite les colonnes manquantes par leur titre lisible (« Type de client, E-mail »)');
    refus_attendu(static fn() => import_executer($csv(['Nom', 'Type', 'Email', 'Ville']), 'clients'), 'rien à importer', 'Fichier ne contenant que la ligne de titres refusé');
    refus_attendu(static fn() => import_executer($csv(['Nom', 'Nom', 'Type', 'Email', 'Ville'], ['A', 'B', 'pro', 'a@example.com', 'V']), 'clients'), 'apparaît deux fois', 'Deux colonnes pour la même donnée : refus');
    refus_attendu(static fn() => import_executer($csv(['Nom', 'Type', 'Email', 'Ville'], ['A', 'pro', 'a@example.com', 'V']), 'vins'), 'clients ou des cuvées', 'Type d\'import inconnu refusé');
    verifier($nbClients() === $avant, 'Aucun de ces fichiers refusés en bloc n\'a écrit quoi que ce soit');

    // --- Limite de lignes ---
    $limite = $GLOBALS['config']['import']['lignes_max'];
    $GLOBALS['config']['import']['lignes_max'] = 3;
    $lignes = [['Nom', 'Type', 'Email', 'Ville']];
    for ($i = 1; $i <= 4; $i++) {
        $lignes[] = ["Limite $i $p", 'pro', "limite$i.$p@example.com", 'Lyon'];
    }
    refus_attendu(static fn() => import_executer($csv(...$lignes), 'clients'), 'la limite est de 3', 'Plus de lignes que la limite configurée : refus de tout le fichier');
    verifier($nbClients() === $avant, 'Le fichier trop long n\'a rien écrit');
    $GLOBALS['config']['import']['lignes_max'] = $limite;

    // --- Contenu hostile : enregistré comme du texte, jamais interprété ---
    $avant = $nbClients();
    $r = import_executer($csv(
        ['Nom', 'Type', 'Email', 'Ville'],
        ['=HYPERLINK("http://x.example")', 'pro', "formule.$p@example.com", '<b>Lyon</b>'],
        ["Robert'); DROP TABLE client; --", 'pro', "robert.$p@example.com", "' OR '1'='1"]
    ), 'clients');
    verifier($r['nb_ok'] === 2 && $nbClients() === $avant + 2, 'Formule de tableur et tentative d\'injection SQL : acceptées comme simples textes');
    verifier(requete_valeur('SELECT nom FROM client WHERE email = ?', ["formule.$p@example.com"]) === '=HYPERLINK("http://x.example")', 'Le texte « =HYPERLINK(...) » est conservé tel quel en base');
    verifier((int) requete_valeur('SELECT COUNT(*) FROM client') >= $avant + 2 && (int) requete_valeur('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'client\'') === 1, 'La table client existe toujours après la tentative « DROP TABLE »');
    $export = export_donnees('clients', ['recherche' => 'HYPERLINK']);
    verifier(count($export['lignes']) === 1 && str_starts_with($export['lignes'][0][0], "'=HYPERLINK"), 'À l\'export, ce nom est neutralisé par une apostrophe : un tableur ne l\'exécutera pas');
});

// ===========================================================================
section('18. F7 : import CSV de cuvées (stock initial, doublons, tout ou rien)');
// ===========================================================================
essayer(static function (): void {
    $csv = static fn(array ...$lignes): string => implode('', array_map('csv_ligne', $lignes));
    $nbCuvees = static fn(): int => (int) requete_valeur('SELECT COUNT(*) FROM cuvee');
    $motifs = static fn(array $r): array => array_column($r['rejets'], 'motifs', 'ligne');
    $texteMotifs = static fn(array $r, int $ligne): string => implode(' ', $motifs($r)[$ligne] ?? []);
    $p = strtoupper(bin2hex(random_bytes(3)));
    $enTete = ['Nom', 'Millésime', 'Couleur', 'Degré', 'Prix de vente', "Seuil d'alerte", 'Stock initial'];
    $anneeMax = (int) date('Y') + 1;

    $fichier = $csv(
        $enTete,
        ["Terrasses $p", '2023', 'rouge', '13,0', '21,00', '24', '80'],                         // 2 : valide
        ["Perle $p", '2024', 'Blanc', '12,0 %', '13,50 €', '', '60'],                           // 3 : valide (« Blanc », « % », « € », seuil vide)
        ['TERRE ROUGE', '2021', 'rouge', '13,0', '11,90', '24', '40'],                          // 4 : existe déjà (casse ignorée)
        ["Vigne $p", (string) ($anneeMax + 5), 'rouge', '13,0', '18,00', '24', '10'],           // 5 : millésime futur
        ["Mystère $p", '2022', 'orange', '12,5', '15,00', '24', '10'],                          // 6 : couleur inconnue
        ["Grand $p", '2020', 'rouge', '45', '55,00', '12', '6'],                                // 7 : degré 45
        ["Verdot $p", '2022', 'rouge', '13,5', '-4', '24', '10'],                               // 8 : prix négatif
        ["Zéro $p", '2022', 'rouge', '13,5', '0', '24', '10'],                                  // 9 : prix nul
        ["Texte $p", '2022', 'rouge', '13,5', 'gratuit', '24', '10'],                           // 10 : prix non numérique
        ["Stock $p", '2022', 'rouge', '13,5', '12,00', '24', '-3'],                             // 11 : stock négatif
        ["Coteau $p", '2023', 'Rosé', '12,5', '1 234,20', '30', '50'],                          // 12 : valide (« Rosé », prix avec espace des milliers)
        ["Coteau $p", '2023', 'rosé', '12,5', '9,99', '30', '5'],                               // 13 : doublon de la ligne 12
        ["Sans stock $p", '2021', 'effervescent', '12,0', '17,50', '', '']                      // 14 : valide, seuil et stock vides
    );

    $avant = $nbCuvees();
    $sim = import_executer($fichier, 'cuvees', true);
    verifier($nbCuvees() === $avant && $sim['nb_ok'] === 4 && count($sim['rejets']) === 9, 'Simulation : 4 cuvées valides, 9 refusées, rien d\'enregistré');

    $r = import_executer($fichier, 'cuvees');
    verifier($r['nb_ok'] === 4 && $nbCuvees() === $avant + 4 && $r['nb_lues'] === 13, 'Import : les 4 cuvées valides sont enregistrées parmi les 13 lignes lues');
    verifier(array_keys($motifs($r)) === [4, 5, 6, 7, 8, 9, 10, 11, 13], 'Les 9 refus portent le bon numéro de ligne : 4 à 11, puis 13');
    verifier(str_contains($texteMotifs($r, 4), 'existe déjà'), 'Ligne 4 : « TERRE ROUGE » 2021 existe déjà (la casse ne compte pas)');
    verifier(str_contains($texteMotifs($r, 5), 'millésime') && str_contains($texteMotifs($r, 5), "1900 et $anneeMax"), 'Ligne 5 : millésime hors limites (borne haute = année prochaine)');
    verifier(str_contains($texteMotifs($r, 6), 'Couleur inconnue « orange »'), 'Ligne 6 : couleur inconnue');
    verifier(str_contains($texteMotifs($r, 7), 'degré') && str_contains($texteMotifs($r, 7), '45'), 'Ligne 7 : degré hors limites');
    verifier(str_contains($texteMotifs($r, 8), 'prix') && str_contains($texteMotifs($r, 8), '-4'), 'Ligne 8 : prix négatif');
    verifier(str_contains($texteMotifs($r, 9), 'prix'), 'Ligne 9 : prix nul');
    verifier(str_contains($texteMotifs($r, 10), 'prix') && str_contains($texteMotifs($r, 10), 'gratuit'), 'Ligne 10 : prix non numérique');
    verifier(str_contains($texteMotifs($r, 11), 'stock initial'), 'Ligne 11 : stock négatif');
    verifier(str_contains($texteMotifs($r, 13), 'Doublon') && str_contains($texteMotifs($r, 13), 'ligne 12'), 'Ligne 13 : doublon de la ligne 12, qui est citée');

    $c = requete_une('SELECT cu.degre, cu.prix_vente, cu.couleur, s.quantite, s.seuil_alerte FROM cuvee cu JOIN stock s ON s.id_cuvee = cu.id WHERE cu.nom = ? AND cu.millesime = 2024', ["Perle $p"]);
    verifier($c !== null && $c['couleur'] === 'blanc' && (float) $c['degre'] === 12.0 && (float) $c['prix_vente'] === 13.5 && (int) $c['seuil_alerte'] === 24 && (int) $c['quantite'] === 60, 'Ligne 3 : « Blanc », « 12,0 % » et « 13,50 € » sont convertis ; seuil vide = 24 ; stock 60');
    $c = requete_une('SELECT cu.couleur, cu.prix_vente, s.quantite, s.seuil_alerte FROM cuvee cu JOIN stock s ON s.id_cuvee = cu.id WHERE cu.nom = ?', ["Coteau $p"]);
    verifier($c !== null && $c['couleur'] === 'rose' && (float) $c['prix_vente'] === 1234.2 && (int) $c['seuil_alerte'] === 30, 'Ligne 12 : « Rosé » devient « rose », « 1 234,20 » devient 1234.20 (la ligne 13, doublon, n\'a pas écrasé la ligne 12)');
    $c = requete_une('SELECT s.quantite, s.seuil_alerte FROM cuvee cu JOIN stock s ON s.id_cuvee = cu.id WHERE cu.nom = ?', ["Sans stock $p"]);
    verifier($c !== null && (int) $c['quantite'] === 0 && (int) $c['seuil_alerte'] === 24, 'Ligne 14 : stock et seuil vides = 0 et 24');
    $nbMouvements = (int) requete_valeur("SELECT COUNT(*) FROM mouvement_stock m JOIN cuvee cu ON cu.id = m.id_cuvee WHERE cu.nom LIKE ? AND m.type_mouvement = 'entree' AND m.motif = 'Mise en bouteille'", ["%$p"]);
    verifier($nbMouvements === 3, 'Les 3 cuvées importées avec un stock > 0 ont chacune leur entrée « Mise en bouteille » (la 4e, à 0, n\'en a pas)');
    verifier(nb_ecarts() === 0, 'Après l\'import, le stock de chaque cuvée est toujours égal à la somme de ses mouvements (écart nul)');

    // --- Colonnes facultatives absentes ---
    $r = import_executer($csv(['Nom', 'Millésime', 'Couleur', 'Degré', 'Prix de vente'], ["Minimal $p", '2022', 'rouge', '13', '10']), 'cuvees');
    $c = requete_une('SELECT s.quantite, s.seuil_alerte FROM cuvee cu JOIN stock s ON s.id_cuvee = cu.id WHERE cu.nom = ?', ["Minimal $p"]);
    verifier($r['nb_ok'] === 1 && (int) $c['quantite'] === 0 && (int) $c['seuil_alerte'] === 24, 'Sans colonnes « Seuil d\'alerte » et « Stock initial » : valeurs par défaut (24 et 0)');
    refus_attendu(static fn() => import_executer($csv(['Nom', 'Millésime', 'Couleur'], ['A', '2022', 'rouge']), 'cuvees'), 'Degré, Prix de vente', 'Colonnes obligatoires manquantes : refus qui les nomme');

    // --- Un doublon que le fichier n'a pas vu est arrêté à l'écriture, sans perdre le reste ---
    // « Straße » et « Strasse » sont identiques pour la base (collation unicode) mais pas pour la comparaison du fichier.
    $avant = $nbCuvees();
    $r = import_executer($csv($enTete, ["Straße $p", '2022', 'blanc', '12,0', '10,00', '5', '10'], ["Strasse $p", '2022', 'blanc', '12,0', '10,00', '5', '10'], ["Autre $p", '2022', 'blanc', '12,0', '10,00', '5', '10']), 'cuvees');
    verifier($r['nb_ok'] === 2 && count($r['rejets']) === 1 && $r['rejets'][0]['ligne'] === 3 && $nbCuvees() === $avant + 2, 'Doublon vu seulement par la base (ß = ss) : refusé à l\'écriture (ligne 3), les autres lignes sont enregistrées');
    verifier(str_contains($texteMotifs($r, 3), 'existe déjà') && nb_ecarts() === 0, 'Le refus à l\'écriture a le même message, et le stock reste cohérent');

    // --- Tout ou rien : une erreur technique pendant l'écriture annule tout l'import ---
    $avant = $nbCuvees();
    $pdo = db();
    $pdo->exec("CREATE TRIGGER trg_test_boom BEFORE INSERT ON cuvee FOR EACH ROW IF NEW.nom = 'Boom $p' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'panne simulée'; END IF");
    try {
        import_executer($csv($enTete, ["Avant $p", '2022', 'rouge', '13,0', '10,00', '5', '10'], ["Boom $p", '2022', 'rouge', '13,0', '10,00', '5', '10'], ["Apres $p", '2022', 'rouge', '13,0', '10,00', '5', '10']), 'cuvees');
        verifier(false, 'Une panne pendant l\'écriture devrait remonter une exception');
    } catch (PDOException $e) {
        verifier(str_contains($e->getMessage(), 'panne simulée'), 'Une panne technique pendant l\'écriture remonte (ce n\'est pas un refus de règle de gestion)');
    } finally {
        $pdo->exec('DROP TRIGGER IF EXISTS trg_test_boom');
    }
    verifier($nbCuvees() === $avant && !$pdo->inTransaction() && (int) requete_valeur('SELECT COUNT(*) FROM stock s JOIN cuvee cu ON cu.id = s.id_cuvee WHERE cu.nom = ?', ["Avant $p"]) === 0, 'Tout ou rien : la cuvée écrite avant la panne est annulée aussi (aucune cuvée, aucun stock, transaction fermée)');
    verifier(nb_ecarts() === 0, 'Et le stock reste cohérent après cette panne');

    // --- L'import fait partie de la transaction de l'appelant s'il y en a une ---
    $avant = $nbCuvees();
    try {
        en_transaction($pdo, static function () use ($csv, $enTete, $p): void {
            import_executer($csv($enTete, ["Englobé $p", '2022', 'rouge', '13,0', '10,00', '5', '10']), 'cuvees');
            throw new RuntimeException('annulation demandée par l\'appelant');
        });
    } catch (RuntimeException) {
        // attendu
    }
    verifier($nbCuvees() === $avant, 'Importé dans une transaction englobante qui échoue : tout est annulé');
});

// ===========================================================================
section('19. F9 : exports CSV (recoupés avec des requêtes SQL directes)');
// ===========================================================================
essayer(static function (): void {
    $nombre = static fn(string $cellule): float => (float) str_replace(',', '.', $cellule);
    $colonne = static fn(array $lignes, int $i): array => array_column($lignes, $i);
    $types = export_types();
    verifier(array_keys($types) === ['commandes', 'lignes', 'stock', 'clients', 'cuvees', 'mouvements'], '6 exports proposés : commandes, détail, stock, clients, cuvées, mouvements');

    foreach ($types as $code => $definition) {
        $d = export_donnees($code);
        $complet = true;
        foreach ($d['lignes'] as $ligne) {
            $complet = $complet && count($ligne) === count($d['entetes']);
        }
        verifier($d['entetes'] === $definition['colonnes'] && $complet && $d['lignes'] !== [], 'Export « ' . $code . ' » : ' . count($d['lignes']) . ' lignes, ' . count($d['entetes']) . ' colonnes, chaque ligne a toutes ses cellules');
    }

    // --- Nombre de lignes = comptage SQL direct ---
    $total = static fn(string $code): int => count(export_donnees($code)['lignes']);
    verifier($total('commandes') === (int) requete_valeur('SELECT COUNT(*) FROM commande'), 'Commandes : une ligne par commande, annulées comprises');
    verifier($total('lignes') === (int) requete_valeur('SELECT COUNT(*) FROM ligne_commande'), 'Détail : une ligne par ligne de commande');
    verifier($total('stock') === (int) requete_valeur('SELECT COUNT(*) FROM stock'), 'Stock : une ligne par cuvée');
    verifier($total('clients') === (int) requete_valeur('SELECT COUNT(*) FROM client'), 'Clients : une ligne par client');
    verifier($total('cuvees') === (int) requete_valeur('SELECT COUNT(*) FROM cuvee'), 'Cuvées : une ligne par cuvée');
    $manque = 205 - (int) requete_valeur('SELECT COUNT(*) FROM mouvement_stock');
    if ($manque > 0) {
        $idCuvee = creer_cuvee_test('Cuvée export journal', 1);
        for ($i = 0; $i < $manque; $i++) {
            stock_entree($idCuvee, '1', 'Journal long');
        }
    }
    $nbMouvements = (int) requete_valeur('SELECT COUNT(*) FROM mouvement_stock');
    verifier($nbMouvements > 200 && $total('mouvements') === $nbMouvements && count(mouvements_liste()) === 200, 'Mouvements : tout le journal (' . $nbMouvements . ' lignes), pas seulement les 200 affichés à l\'écran');

    // --- Montants recoupés ---
    $commandes = export_donnees('commandes')['lignes'];
    $caTous = (float) requete_valeur('SELECT SUM(total) FROM v_commande_total');
    verifier(abs(array_sum(array_map($nombre, $colonne($commandes, 6))) - $caTous) < 0.005, 'Somme de la colonne « Total (€) » des commandes = somme SQL des totaux (' . number_format($caTous, 2, ',', ' ') . ' €)');
    $detail = export_donnees('lignes')['lignes'];
    $lignesTous = (float) requete_valeur('SELECT SUM(quantite * prix_unitaire) FROM ligne_commande');
    verifier(abs(array_sum(array_map($nombre, $colonne($detail, 8))) - $lignesTous) < 0.005, 'Somme de « Total ligne (€) » du détail = somme SQL quantité × prix (' . number_format($lignesTous, 2, ',', ' ') . ' €)');
    $validees = array_filter($detail, static fn(array $l): bool => $l[3] === 'Validée');
    verifier(abs(array_sum(array_map($nombre, $colonne($validees, 8))) - (float) requete_valeur("SELECT SUM(total) FROM v_commande_total WHERE statut = 'validee'")) < 0.005, 'Détail des seules commandes validées = chiffre d\'affaires du tableau de bord');
    $quantites = array_sum(array_map(static fn(array $l): int => (int) $l[6], $detail));
    verifier($quantites === (int) requete_valeur('SELECT SUM(quantite) FROM ligne_commande'), 'Somme des quantités du détail = somme SQL');
    $stock = export_donnees('stock')['lignes'];
    verifier(abs(array_sum(array_map($nombre, $colonne($stock, 7))) - (float) requete_valeur('SELECT SUM(s.quantite * cu.prix_vente) FROM stock s JOIN cuvee cu ON cu.id = s.id_cuvee')) < 0.005, 'Somme de « Valeur du stock (€) » = somme SQL quantité × prix de vente');
    $etats = array_count_values($colonne($stock, 6));
    verifier(($etats['Stock bas'] ?? 0) + ($etats['Rupture'] ?? 0) === (int) requete_valeur('SELECT COUNT(*) FROM v_stock_bas') && ($etats['Rupture'] ?? 0) === (int) requete_valeur('SELECT COUNT(*) FROM v_stock_bas WHERE niveau = \'rupture\''), 'Colonne « État » du stock : « Stock bas » + « Rupture » = vue v_stock_bas');
    $ca = array_sum(array_map(static fn(array $l): float => (float) str_replace(',', '.', $l[5]), export_donnees('clients')['lignes']));
    verifier(abs($ca - (float) requete_valeur("SELECT SUM(total) FROM v_commande_total WHERE statut = 'validee'")) < 0.005, 'Somme du chiffre d\'affaires de l\'export clients = chiffre d\'affaires validé');

    // --- Même liste, même ordre que l'écran ---
    verifier($colonne($commandes, 0) === array_map(static fn(array $c): string => (string) $c['id'], commandes_liste()), 'Commandes : mêmes numéros, dans le même ordre (récentes d\'abord) que l\'écran');

    // --- Filtres : le fichier contient exactement les lignes de l'écran filtré ---
    $filtres = ['id_client' => 1, 'statut' => 'validee'];
    $ids = array_map(static fn(array $c): string => (string) $c['id'], commandes_liste($filtres));
    $f = export_donnees('commandes', $filtres)['lignes'];
    verifier($colonne($f, 0) === $ids && count($ids) === (int) requete_valeur("SELECT COUNT(*) FROM commande WHERE id_client = 1 AND statut = 'validee'") && $ids !== [], 'Commandes filtrées (client 1, validées) : ' . count($ids) . ' lignes, comme l\'écran et comme le SQL');
    $fd = export_donnees('lignes', $filtres)['lignes'];
    verifier(count($fd) === (int) requete_valeur("SELECT COUNT(*) FROM ligne_commande l JOIN commande co ON co.id = l.id_commande WHERE co.id_client = 1 AND co.statut = 'validee'") && array_unique($colonne($fd, 0)) !== [] && array_diff($colonne($fd, 0), $ids) === [], 'Détail filtré : toutes les lignes de ces commandes, et d\'elles seules');
    $periode = export_donnees('commandes', ['du' => '2026-03-01', 'au' => '2026-03-31'])['lignes'];
    verifier(count($periode) === (int) requete_valeur("SELECT COUNT(*) FROM commande WHERE date_commande BETWEEN '2026-03-01 00:00:00' AND '2026-03-31 23:59:59'") && $periode !== [], 'Commandes de mars 2026 : ' . count($periode) . ' lignes, comme le SQL');
    $avecCuvee3 = export_donnees('lignes', ['id_cuvee' => 3])['lignes'];
    verifier(count($avecCuvee3) === (int) requete_valeur('SELECT COUNT(*) FROM ligne_commande WHERE id_commande IN (SELECT id_commande FROM ligne_commande WHERE id_cuvee = 3)'), 'Détail filtré sur une cuvée : toutes les lignes des commandes qui la contiennent');
    verifier($total('stock') > count(export_donnees('stock', ['alertes' => true])['lignes']) && count(export_donnees('stock', ['alertes' => true])['lignes']) === (int) requete_valeur('SELECT COUNT(*) FROM v_stock_bas'), 'Stock, « alertes seulement » : autant de lignes que la vue v_stock_bas');
    verifier(count(export_donnees('stock', ['recherche' => 'Aurore'])['lignes']) === (int) requete_valeur("SELECT COUNT(*) FROM cuvee WHERE nom LIKE '%Aurore%' OR millesime LIKE '%Aurore%'"), 'Stock : la recherche par nom est appliquée');
    verifier(count(export_donnees('clients', ['type' => 'pro'])['lignes']) === (int) requete_valeur("SELECT COUNT(*) FROM client WHERE type_client = 'pro'"), 'Clients : le filtre par type est appliqué');
    verifier(count(export_donnees('cuvees', ['couleur' => 'rose'])['lignes']) === (int) requete_valeur("SELECT COUNT(*) FROM cuvee WHERE couleur = 'rose'"), 'Cuvées : le filtre par couleur est appliqué');
    verifier(count(export_donnees('mouvements', ['type' => 'sortie'])['lignes']) === (int) requete_valeur("SELECT COUNT(*) FROM mouvement_stock WHERE type_mouvement = 'sortie'"), 'Mouvements : le filtre entrées/sorties est appliqué, sans limite de 200');
    verifier(count(export_donnees('mouvements', ['id_cuvee' => 1])['lignes']) === (int) requete_valeur('SELECT COUNT(*) FROM mouvement_stock WHERE id_cuvee = 1'), 'Mouvements : le filtre par cuvée est appliqué');

    // --- Paramètres de l'adresse : hostiles = ignorés ---
    $_GET = ['client' => '1 OR 1=1', 'statut' => "x' OR '1'='1", 'du' => 'pas-une-date', 'cuvee' => '-3'];
    $lus = export_filtres_get('commandes');
    verifier($lus === ['du' => '', 'au' => '', 'id_client' => 0, 'id_cuvee' => 0, 'statut' => ''], 'Paramètres d\'adresse piégés (injection, date invalide, négatif) : tous ignorés');
    $_GET = ['q' => 'Aur', 'alertes' => '1', 'type' => 'pro', 'couleur' => 'rose', 'id_cuvee' => '7'];
    verifier(export_filtres_get('stock') === ['recherche' => 'Aur', 'alertes' => true] && export_filtres_get('clients') === ['recherche' => 'Aur', 'type' => 'pro']
        && export_filtres_get('cuvees') === ['recherche' => 'Aur', 'couleur' => 'rose'] && export_filtres_get('mouvements') === ['id_cuvee' => 7, 'type' => 'pro'], 'Chaque export lit les paramètres de son écran (q, alertes, type, couleur, id_cuvee)');
    $_GET = [];
    verifier(export_url('commandes', ['du' => '2026-01-01', 'client' => '', 'cuvee' => '']) === 'export_csv.php?donnees=commandes&du=2026-01-01', 'export_url : les filtres vides ne sont pas recopiés dans l\'adresse');
    refus_attendu(static fn() => export_donnees('inconnu'), 'n\'existe pas', 'Un export inconnu est refusé');
    verifier(preg_match('/^commandes_\d{4}-\d{2}-\d{2}\.csv$/', export_nom_fichier('commandes')) === 1 && preg_match('/^detail_commandes_\d{4}-\d{2}-\d{2}\.csv$/', export_nom_fichier('lignes')) === 1, 'Noms de fichiers : commandes_AAAA-MM-JJ.csv, detail_commandes_AAAA-MM-JJ.csv...');

    // --- Format du fichier ---
    $doc = csv_document(export_donnees('commandes')['entetes'], $commandes);
    verifier(str_starts_with($doc, "\xEF\xBB\xBF") && substr_count($doc, "\r\n") === count($commandes) + 1, 'Fichier des commandes : BOM UTF-8, une ligne CRLF par commande plus les titres');
    verifier(preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $commandes[0][1]) === 1 && preg_match('/^\d+,\d{2}$/', $commandes[0][6]) === 1, 'Dates au format AAAA-MM-JJ HH:MM:SS, montants avec virgule décimale (« ' . $commandes[0][1] . ' », « ' . $commandes[0][6] . ' »)');
    [$relu] = csv_decoder($doc);
    $enr = csv_lire($relu, csv_separateur($relu));
    verifier(count($enr) === count($commandes) + 1 && $enr[1]['champs'] === $commandes[0], 'Le fichier se relit à l\'identique avec le lecteur CSV de l\'import');

    // --- Aller-retour : un export « clients » ou « cuvées » se réimporte tel quel ---
    $exp = export_donnees('clients');
    $simule = import_executer(csv_document($exp['entetes'], $exp['lignes']), 'clients', true);
    verifier($simule['nb_ok'] === 0 && count($simule['rejets']) === count($exp['lignes']) && $simule['colonnes_ignorees'] === ['Commandes validées', 'Chiffre d\'affaires (€)'], 'Réimporter l\'export « clients » dans la même base : titres reconnus, tout refusé comme déjà existant, 2 colonnes de calcul ignorées');
    $copie = array_map(static fn(array $l): array => [$l[0], $l[1], str_replace('@', '+copie@', $l[2]), $l[3], $l[4], $l[5]], $exp['lignes']);
    $r = import_executer(csv_document($exp['entetes'], $copie), 'clients');
    verifier($r['nb_ok'] === count($exp['lignes']) && $r['rejets'] === [], 'Export « clients » avec e-mails modifiés : les ' . count($exp['lignes']) . ' clients sont importés sans aucun refus');
    $ecart = (int) requete_valeur("SELECT COUNT(*) FROM client a WHERE a.email NOT LIKE '%+copie@%' AND a.nom NOT LIKE '=%'
        AND NOT EXISTS (SELECT 1 FROM client b WHERE b.email = REPLACE(a.email, '@', '+copie@') AND b.nom = a.nom AND b.type_client = a.type_client AND b.ville = a.ville)");
    verifier($ecart === 0, 'Chaque client importé a le même nom, type et ville que son original');

    $exp = export_donnees('cuvees');
    $sans_ss = array_values(array_filter($exp['lignes'], static fn(array $l): bool => !str_contains($l[0], 'Stra')));   // « Straße » / « Strasse » : doublon pour la base
    $copie = array_map(static fn(array $l): array => array_merge([$l[0] . ' (copie)'], array_slice($l, 1)), $sans_ss);
    $r = import_executer(csv_document($exp['entetes'], $copie), 'cuvees');
    verifier($r['nb_ok'] === count($copie) && $r['rejets'] === [], 'Export « cuvées » avec noms modifiés : les ' . count($copie) . ' cuvées sont importées sans aucun refus');
    $ecart = (int) requete_valeur("SELECT COUNT(*) FROM cuvee a JOIN stock sa ON sa.id_cuvee = a.id WHERE a.nom NOT LIKE '%(copie)' AND a.nom NOT LIKE 'Stra%'
        AND NOT EXISTS (SELECT 1 FROM cuvee b JOIN stock sb ON sb.id_cuvee = b.id
                        WHERE b.nom = CONCAT(a.nom, ' (copie)') AND b.millesime = a.millesime AND b.couleur = a.couleur AND b.degre = a.degre
                          AND b.prix_vente = a.prix_vente AND sb.quantite = sa.quantite AND sb.seuil_alerte = sa.seuil_alerte)");
    verifier($ecart === 0 && nb_ecarts() === 0, 'Chaque cuvée importée a les mêmes millésime, couleur, degré, prix, stock et seuil que son original, et le stock reste cohérent');
});

// ===========================================================================
section('20. F7 : les fichiers du dossier modeles/ se comportent comme la documentation l\'annonce');
// ===========================================================================
essayer(static function (): void {
    $lire = static fn(string $nom): string => (string) file_get_contents(dirname(__DIR__) . '/modeles/' . $nom);
    $motifs = static fn(array $r): array => array_column($r['rejets'], 'motifs', 'ligne');
    $texteMotifs = static fn(array $r, int $ligne): string => implode(' ', $motifs($r)[$ligne] ?? []);

    foreach (['clients.csv', 'clients_avec_erreurs.csv', 'cuvees.csv', 'cuvees_avec_erreurs.csv'] as $nom) {
        verifier(is_file(dirname(__DIR__) . '/modeles/' . $nom) && strlen($lire($nom)) > 0, "modeles/$nom existe et n'est pas vide");
    }
    verifier(str_starts_with($lire('clients.csv'), "\xEF\xBB\xBF") && str_contains($lire('clients.csv'), "\r\n"), 'Les modèles sont au format Excel français : UTF-8 avec BOM, fins de ligne Windows');

    // --- Exemples avec erreurs : 8 lignes, 3 valides, 5 refusées, chacune pour une raison différente ---
    $r = import_executer($lire('clients_avec_erreurs.csv'), 'clients', true);
    verifier($r['nb_lues'] === 8 && $r['nb_ok'] === 3 && array_keys($motifs($r)) === [4, 5, 6, 7, 9], 'clients_avec_erreurs.csv : 8 lignes, 3 valides, refus aux lignes 4, 5, 6, 7 et 9');
    verifier(str_contains($texteMotifs($r, 4), 'existe déjà') && str_contains($texteMotifs($r, 5), 'pas valide') && str_contains($texteMotifs($r, 6), 'nom est obligatoire')
        && str_contains($texteMotifs($r, 7), 'Type de client inconnu') && str_contains($texteMotifs($r, 9), 'Doublon'), 'clients_avec_erreurs.csv : e-mail déjà en base, e-mail invalide, nom manquant, type inconnu, doublon dans le fichier');

    $r = import_executer($lire('cuvees_avec_erreurs.csv'), 'cuvees', true);
    verifier($r['nb_lues'] === 8 && $r['nb_ok'] === 3 && array_keys($motifs($r)) === [4, 5, 6, 7, 8], 'cuvees_avec_erreurs.csv : 8 lignes, 3 valides, refus aux lignes 4 à 8');
    verifier(str_contains($texteMotifs($r, 4), 'existe déjà') && str_contains($texteMotifs($r, 5), 'millésime') && str_contains($texteMotifs($r, 6), 'Couleur inconnue')
        && str_contains($texteMotifs($r, 7), 'degré') && str_contains($texteMotifs($r, 8), 'prix'), 'cuvees_avec_erreurs.csv : cuvée déjà en base, millésime futur, couleur inconnue, degré hors limites, prix négatif');

    // --- Modèles sans erreur : tout est importé, le stock initial passe par le journal ---
    $r = import_executer($lire('clients.csv'), 'clients');
    verifier($r['nb_lues'] === 5 && $r['nb_ok'] === 5 && $r['rejets'] === [], 'clients.csv : les 5 clients sont importés sans aucun refus');
    $r = import_executer($lire('clients.csv'), 'clients');
    verifier($r['nb_ok'] === 0 && count($r['rejets']) === 5 && str_contains($texteMotifs($r, 2), 'existe déjà'), 'Réimporter clients.csv ne crée aucun doublon : les 5 lignes sont refusées comme déjà existantes');

    $r = import_executer($lire('cuvees.csv'), 'cuvees');
    verifier($r['nb_lues'] === 4 && $r['nb_ok'] === 4 && $r['rejets'] === [], 'cuvees.csv : les 4 cuvées sont importées sans aucun refus');
    $total = (int) requete_valeur("SELECT SUM(s.quantite) FROM stock s JOIN cuvee cu ON cu.id = s.id_cuvee WHERE cu.nom IN ('Clos des Cigales', 'Source Claire', 'Rosé de Soleil', 'Crémant des Coteaux')");
    verifier($total === 420 && nb_ecarts() === 0, 'cuvees.csv : 420 bouteilles de stock initial (120 + 96 + 144 + 60), et le stock reste égal à la somme des mouvements');
});

// ---------------------------------------------------------------------------
//  Bilan
// ---------------------------------------------------------------------------
$b = $GLOBALS['bilan'];
$duree = number_format(microtime(true) - $debut, 1, ',', '');
echo "\n------------------------------------------------------------\n";
echo sprintf("Résultat : %d vérification(s) réussie(s), %d échec(s), %d ignorée(s) en %s s.\n", $b['ok'], $b['echec'], $b['ignore'], $duree);

if ($b['echec'] === 0 && !in_array('--garder', $argv, true)) {
    $serveur->exec('DROP DATABASE IF EXISTS `' . BASE_TEST . '`');
    echo "Base de test supprimée (option --garder pour la conserver).\n";
}
echo $b['echec'] === 0 ? "TOUS LES TESTS PASSENT.\n" : "DES TESTS ÉCHOUENT : voir les lignes [ÉCHEC] ci-dessus.\n";
exit($b['echec'] === 0 ? 0 : 1);
