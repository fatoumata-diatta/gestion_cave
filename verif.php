<?php
declare(strict_types=1);

/**
 * Vérification de l'installation : PHP, extensions, connexion MySQL, tables, vues, données, cohérence du stock.
 *
 * Page volontairement publique (pas de connexion requise) : c'est elle qu'on ouvre quand
 * « ça ne marche pas ». Elle n'affiche jamais le mot de passe de la base.
 * À supprimer (ou à protéger) avant toute mise en ligne.
 */
define('PAGE_PUBLIQUE', true);
require __DIR__ . '/src/bootstrap.php';

const TABLES_ATTENDUES = ['cuvee', 'stock', 'client', 'commande', 'ligne_commande', 'mouvement_stock'];
const VUES_ATTENDUES   = ['v_commande_total', 'v_ca_par_cuvee', 'v_stock_bas', 'v_controle_stock'];

/** @var list<array{libelle: string, etat: string, detail: string, aide: string}> $controles */
$controles = [];
$ajouter = static function (string $libelle, string $etat, string $detail, string $aide = '') use (&$controles): void {
    $controles[] = ['libelle' => $libelle, 'etat' => $etat, 'detail' => $detail, 'aide' => $aide];
};
$contenu = [];   // table => nombre de lignes

// --- 1. PHP et extensions ------------------------------------------------------------------
$ajouter(
    'Version de PHP',
    version_compare(PHP_VERSION, '8.0.0', '>=') ? 'ok' : 'ko',
    'PHP ' . PHP_VERSION . ' (8.0 ou plus requis)',
    'Installez une version récente de XAMPP (PHP 8.0 ou plus).'
);
foreach (['pdo_mysql' => 'Extension PDO MySQL', 'mbstring' => 'Extension mbstring'] as $extension => $libelle) {
    $presente = extension_loaded($extension);
    $ajouter(
        $libelle,
        $presente ? 'ok' : 'ko',
        $presente ? 'Activée' : 'Absente',
        'Dans XAMPP : ouvrez php.ini (bouton Config d\'Apache), retirez le « ; » devant extension=' . $extension . ', puis redémarrez Apache.'
    );
}
$ajouter(
    'Sessions PHP',
    session_status() === PHP_SESSION_ACTIVE ? 'ok' : 'ko',
    session_status() === PHP_SESSION_ACTIVE ? 'Actives (nécessaires à la connexion)' : 'Inactives',
    'Vérifiez que le dossier indiqué par session.save_path dans php.ini existe et que PHP peut y écrire.'
);

// Import CSV (bonus F7) : PHP doit accepter l'envoi de fichiers, et assez gros pour le plus gros fichier que l'application accepte.
$enOctets = static function (string $valeur): int {
    $valeur = trim($valeur);
    $nombre = (int) $valeur;
    return match (strtolower(substr($valeur, -1))) {
        'g'     => $nombre * 1073741824,
        'm'     => $nombre * 1048576,
        'k'     => $nombre * 1024,
        default => $nombre,
    };
};
$envoiActif = filter_var(ini_get('file_uploads'), FILTER_VALIDATE_BOOLEAN);
$uploadMax  = $enOctets((string) ini_get('upload_max_filesize'));
$postMax    = $enOctets((string) ini_get('post_max_size'));
$limitePhp  = min($uploadMax, $postMax > 0 ? $postMax : PHP_INT_MAX);      // post_max_size = 0 : pas de limite
$limiteApp  = (int) config('import.taille_max_octets', 2 * 1024 * 1024);
$ajouter(
    'Envoi de fichiers (import CSV)',
    $envoiActif && $limitePhp >= $limiteApp ? 'ok' : 'attention',
    $envoiActif
        ? 'Activé, fichiers jusqu\'à ' . number_format(min($limitePhp, $limiteApp) / 1048576, 1, ',', "\u{00A0}") . "\u{00A0}Mo"
            . ' (upload_max_filesize = ' . ini_get('upload_max_filesize') . ', post_max_size = ' . ini_get('post_max_size') . ')'
        : 'Désactivé dans php.ini (file_uploads)',
    'Dans php.ini (bouton Config d\'Apache dans XAMPP), mettez file_uploads=On puis upload_max_filesize et post_max_size à '
    . number_format($limiteApp / 1048576, 0, ',', '') . 'M au moins, et redémarrez Apache. Seul l\'import CSV est concerné.'
);

// --- 2. Base de données --------------------------------------------------------------------
$cfg = (array) config('db');
$cibleBase = sprintf('%s:%d, base « %s », utilisateur « %s »', $cfg['host'], $cfg['port'], $cfg['name'], $cfg['user']);
$pdo = null;
if (extension_loaded('pdo_mysql')) {
    try {
        $pdo = db();
        $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        $moteur = stripos($version, 'mariadb') !== false ? 'MariaDB' : 'MySQL';
        $ajouter('Connexion à la base', 'ok', $moteur . ' ' . preg_replace('/-.*/', '', $version) . ' (' . $cibleBase . ')');
    } catch (ConnexionBaseException $erreur) {
        $ajouter(
            'Connexion à la base',
            'ko',
            $erreur->getMessage(),
            'Démarrez MySQL dans le panneau XAMPP. Si un autre MySQL occupe déjà le port 3306, XAMPP utilise souvent 3307 : '
            . 'créez config/config.local.php pour changer le port ou le mot de passe. '
            . 'Si l\'erreur parle d\'une base inconnue, importez database/install_complet.sql dans phpMyAdmin.'
        );
    }
} else {
    $ajouter('Connexion à la base', 'ignore', 'Non vérifiée : l\'extension PDO MySQL est absente.');
}

if ($pdo !== null) {
    $schema = (string) $cfg['name'];

    // Tables et vues présentes
    $objets = [];
    foreach (requete_toutes('SELECT TABLE_NAME AS nom, TABLE_TYPE AS genre FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?', [$schema]) as $ligne) {
        $objets[(string) $ligne['nom']] = (string) $ligne['genre'];
    }
    $tablesManquantes = array_values(array_filter(TABLES_ATTENDUES, static fn(string $t): bool => ($objets[$t] ?? '') !== 'BASE TABLE'));
    $vuesManquantes   = array_values(array_filter(VUES_ATTENDUES, static fn(string $v): bool => ($objets[$v] ?? '') !== 'VIEW'));
    $aideImport = 'Dans phpMyAdmin : onglet « Importer », choisissez database/install_complet.sql, puis « Importer ».';

    $ajouter(
        'Tables (' . count(TABLES_ATTENDUES) . ' attendues)',
        $tablesManquantes ? 'ko' : 'ok',
        $tablesManquantes ? 'Manquantes : ' . implode(', ', $tablesManquantes) : implode(', ', TABLES_ATTENDUES),
        $aideImport
    );
    $ajouter(
        'Vues (' . count(VUES_ATTENDUES) . ' attendues)',
        $vuesManquantes ? 'ko' : 'ok',
        $vuesManquantes ? 'Manquantes : ' . implode(', ', $vuesManquantes) : implode(', ', VUES_ATTENDUES),
        $aideImport
    );

    // Contraintes CHECK (règles « quantité > 0 », « prix > 0 »...) : actives sur MariaDB 10.2+ et MySQL 8.0.16+
    try {
        $nbCheck = (int) requete_valeur(
            'SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ? AND CONSTRAINT_NAME LIKE \'ck\\_%\'',
            [$schema]
        );
        $ajouter(
            'Contraintes CHECK',
            $nbCheck >= 8 ? 'ok' : ($tablesManquantes ? 'ignore' : 'attention'),
            pluriel($nbCheck, 'contrainte') . ' active' . accord($nbCheck) . ' dans la base',
            'Ces contraintes sont créées par 01_schema.sql ; réimportez install_complet.sql si elles manquent.'
        );
    } catch (PDOException $erreur) {
        $ajouter(
            'Contraintes CHECK',
            'attention',
            'Non prises en charge par cette version du serveur',
            'Le code PHP contrôle quand même chaque règle (quantités, prix) ; passez à MariaDB 10.2+ ou MySQL 8.0.16+ pour une double protection.'
        );
    }

    // Contenu de la base
    if (!$tablesManquantes) {
        foreach (TABLES_ATTENDUES as $table) {
            // $table vient de la constante ci-dessus, jamais d'une saisie : l'insérer dans la requête est sûr.
            $contenu[$table] = (int) requete_valeur('SELECT COUNT(*) FROM `' . $table . '`');
        }
        $ajouter(
            'Données de démonstration',
            $contenu['commande'] > 0 ? 'ok' : 'attention',
            $contenu['cuvee'] . ' cuvées, ' . $contenu['client'] . ' clients, ' . $contenu['commande'] . ' commandes',
            'La base est vide : importez database/install_complet.sql pour charger les données de démonstration.'
        );
    }

    // Cohérence stock enregistré / mouvements
    if (!$tablesManquantes && !$vuesManquantes) {
        $ecarts = controle_stock_ecarts();
        $ajouter(
            'Cohérence du stock',
            $ecarts ? 'ko' : 'ok',
            $ecarts
                ? pluriel(count($ecarts), 'cuvée') . ' avec un stock différent de la somme des mouvements'
                : 'Le stock de chaque cuvée est égal à la somme de ses mouvements (entrées − sorties)'
        );
    }
}

// --- 3. Connexion à l'application ----------------------------------------------------------
$ajouter(
    'Connexion à l\'application',
    'ok',
    auth_active() ? 'Activée (un seul compte, défini dans config/config.php)' : 'Désactivée dans config/config.php (accès libre)'
);

$nbKo = count(array_filter($controles, static fn(array $c): bool => $c['etat'] === 'ko'));
$nbAttention = count(array_filter($controles, static fn(array $c): bool => $c['etat'] === 'attention'));

/** Pastille d'état : icône + texte, jamais la couleur seule. */
$pastille = static function (string $etat): string {
    return match ($etat) {
        'ok'        => '<span class="badge badge-ok">' . icone('ok', 14) . 'OK</span>',
        'ko'        => '<span class="badge badge-critique">' . icone('rupture', 14) . 'À corriger</span>',
        'attention' => '<span class="badge badge-alerte">' . icone('alerte', 14) . 'Attention</span>',
        default     => '<span class="badge badge-neutre">Non vérifié</span>',
    };
};
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Vérification de l'installation – <?= e((string) config('app_name', 'Cave')) ?></title>
    <?= lien_favicon() ?>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="page-verif">
<main class="verification">
    <header class="entete-page">
        <div>
            <h1>Vérification de l'installation</h1>
            <p class="description">Cette page contrôle que PHP, MySQL et la base sont prêts. Elle ne modifie rien.</p>
        </div>
        <div class="entete-actions"><a class="bouton" href="index.php">Ouvrir l'application</a></div>
    </header>

    <?php if ($nbKo === 0): ?>
        <div class="alerte alerte-succes" role="status">
            <?= icone('ok', 18) ?>
            <p><?= $nbAttention > 0
                ? 'L\'installation fonctionne. Un point mérite votre attention (voir ci-dessous).'
                : 'Tout est en ordre : l\'application est prête à être utilisée.' ?></p>
        </div>
    <?php else: ?>
        <div class="alerte alerte-erreur" role="alert">
            <?= icone('alerte', 18) ?>
            <p><?= $nbKo === 1 ? '1 point doit être corrigé' : $nbKo . ' points doivent être corrigés' ?> avant d'utiliser l'application. L'aide se trouve sous chaque ligne concernée.</p>
        </div>
    <?php endif; ?>

    <div class="defilement">
        <table class="tableau">
            <caption class="sr-only">Résultat des contrôles d'installation</caption>
            <thead>
                <tr>
                    <th scope="col">Contrôle</th>
                    <th scope="col">Résultat</th>
                    <th scope="col">Détail</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($controles as $c): ?>
                <tr>
                    <td><?= e($c['libelle']) ?></td>
                    <td><?= $pastille($c['etat']) ?></td>
                    <td>
                        <?= e($c['detail']) ?>
                        <?php if ($c['aide'] !== '' && in_array($c['etat'], ['ko', 'attention'], true)): ?>
                            <span class="note-prix"><?= e($c['aide']) ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($contenu): ?>
        <section class="bloc bloc-suivant" aria-labelledby="titre-contenu">
            <div class="bloc-entete">
                <h2 id="titre-contenu">Contenu de la base</h2>
                <p class="bloc-note">Nombre de lignes par table</p>
            </div>
            <table class="tableau">
                <caption class="sr-only">Nombre de lignes par table</caption>
                <thead>
                    <tr><th scope="col">Table</th><th scope="col" class="nombre">Lignes</th></tr>
                </thead>
                <tbody>
                <?php foreach ($contenu as $table => $nb): ?>
                    <tr><td><?= e($table) ?></td><td class="nombre"><?= e(nombre($nb)) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    <?php endif; ?>

    <p class="legende-tableau">PHP <?= e(PHP_VERSION) ?>, serveur <?= e($_SERVER['SERVER_SOFTWARE'] ?? 'PHP intégré') ?>. Supprimez verif.php avant toute mise en ligne.</p>
</main>
</body>
</html>
