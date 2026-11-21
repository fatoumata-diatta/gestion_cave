<?php
declare(strict_types=1);

/**
 * F7 : import CSV de clients ou de cuvées.
 * Les lignes valides sont enregistrées, les autres sont listées avec leur motif (rapport + fichier à corriger).
 */
require __DIR__ . '/src/bootstrap.php';

$types      = import_types();
$type       = champ_get('type');                 // pré-sélection depuis les écrans Clients et Cuvées
$simulation = false;
$erreurs    = [];

if (est_post()) {
    if (!$_POST && !$_FILES && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        // Fichier plus gros que post_max_size : PHP vide $_POST et $_FILES, le jeton CSRF n'arrive même pas.
        $erreurs[] = 'Le fichier est trop volumineux pour le serveur. Découpez-le en plusieurs fichiers.';
    } else {
        csrf_verifier();

        if (champ_post('action') === 'masquer') {
            unset($_SESSION['import_rapport']);
            redirect('import.php');
        }

        $type       = champ_post('type');
        $simulation = champ_post('simulation') === '1';
        try {
            [$nomFichier, $contenu] = import_televersement($_FILES['fichier'] ?? null);
            $resultat = import_executer($contenu, $type, $simulation);

            $_SESSION['import_rapport'] = [
                'fichier'  => $nomFichier,
                'date'     => date('Y-m-d H:i:s'),
                'resultat' => $resultat,
            ];
            flash($resultat['nb_ok'] === 0 && $resultat['rejets'] ? 'erreur' : 'succes', import_bilan($resultat));
            redirect('import.php');
        } catch (RegleGestionException $erreur) {
            $erreurs = $erreur->erreurs();
        }
    }
}
if (!isset($types[$type])) {
    $type = 'clients';
}

$rapport = $_SESSION['import_rapport'] ?? null;
if (!is_array($rapport) || !isset($rapport['resultat']['type'], $types[$rapport['resultat']['type']])) {
    $rapport = null;
}

page_debut(
    'Import CSV',
    'import',
    'Ajoutez d\'un coup plusieurs clients ou plusieurs cuvées à partir d\'un fichier Excel enregistré au format CSV.'
);
?>
<?= bloc_erreurs($erreurs, 'Le fichier n\'a pas été importé') ?>

<?php if ($rapport !== null):
    $res      = $rapport['resultat'];
    $nbRefus  = count($res['rejets']);
    $affiches = array_slice($res['rejets'], 0, 100);
    $nbColonnes = count($res['entete']);
    ?>
    <section class="bloc rapport" aria-labelledby="titre-rapport">
        <div class="bloc-entete">
            <h2 id="titre-rapport"><?= $res['simulation'] ? 'Rapport de la simulation' : 'Rapport du dernier import' ?></h2>
            <p class="bloc-note">Fichier « <?= e($rapport['fichier']) ?> », traité le <?= e(date_fr((string) $rapport['date'], true)) ?></p>
        </div>

        <?php if ($res['simulation']): ?>
            <p class="rapport-note"><?= icone('bouclier', 16) ?> Simulation : le fichier a été vérifié, mais rien n'a été enregistré.
                Relancez l'import sans cocher « Simuler seulement » pour ajouter les lignes valides.</p>
        <?php endif; ?>

        <dl class="fiche">
            <div><dt>Contenu</dt><dd><?= e($types[$res['type']]['libelle']) ?></dd></div>
            <div><dt>Lignes lues</dt><dd><?= e(nombre($res['nb_lues'])) ?></dd></div>
            <div><dt><?= $res['simulation'] ? 'Lignes valides' : 'Lignes enregistrées' ?></dt><dd><?= e(nombre($res['nb_ok'])) ?></dd></div>
            <div><dt>Lignes refusées</dt><dd><?= e(nombre($nbRefus)) ?></dd></div>
            <div><dt>Format reconnu</dt><dd><?= e($res['encodage']) ?>, <?= e(import_libelle_separateur($res['separateur'])) ?></dd></div>
        </dl>

        <?php if ($res['colonnes_ignorees']): ?>
            <p class="rapport-note">Colonnes ignorées, car l'import ne les utilise pas : <?= e(implode(', ', $res['colonnes_ignorees'])) ?>.</p>
        <?php endif; ?>

        <?php if ($nbRefus === 0): ?>
            <p class="rapport-note"><?= icone('ok', 16) ?> Aucune ligne refusée : tout le fichier est valide.</p>
        <?php else: ?>
            <div class="bloc-entete bloc-suivant">
                <h3>Lignes refusées (<?= e(nombre($nbRefus)) ?>)</h3>
                <a class="bouton bouton-secondaire bouton-petit" href="import_rejets.php"><?= icone('export', 16) ?> Télécharger ces lignes (CSV)</a>
            </div>
            <div class="defilement">
                <table class="tableau tableau-compact">
                    <caption class="sr-only">Lignes du fichier refusées par l'import, avec le motif de chaque refus</caption>
                    <thead>
                        <tr>
                            <th scope="col" class="nombre">Ligne</th>
                            <?php foreach ($res['entete'] as $i => $titre): ?>
                                <th scope="col"><?= e(trim((string) $titre) !== '' ? $titre : 'Colonne ' . ($i + 1)) ?></th>
                            <?php endforeach; ?>
                            <th scope="col">Motif du refus</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($affiches as $rejet): ?>
                        <tr>
                            <td class="nombre discret"><?= (int) $rejet['ligne'] ?></td>
                            <?php for ($i = 0; $i < $nbColonnes; $i++): ?>
                                <td><?= e($rejet['champs'][$i] ?? '') ?></td>
                            <?php endfor; ?>
                            <td class="motif">
                                <ul class="liste-motifs">
                                    <?php foreach ($rejet['motifs'] as $motif): ?><li><?= e($motif) ?></li><?php endforeach; ?>
                                </ul>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="legende-tableau">
                <?php if ($nbRefus > count($affiches)): ?>
                    Les <?= e(nombre(count($affiches))) ?> premières lignes refusées sont affichées ; le fichier à télécharger les contient toutes.
                <?php endif; ?>
                Le fichier téléchargé reprend vos colonnes et ajoute le motif de chaque refus : corrigez-le, puis importez-le à nouveau
                (la colonne « motif_du_rejet » est ignorée à l'import).
            </p>
        <?php endif; ?>

        <form class="barre-boutons" method="post" action="import.php">
            <?= csrf_champ() ?>
            <input type="hidden" name="action" value="masquer">
            <button class="bouton bouton-secondaire bouton-petit" type="submit">Masquer ce rapport</button>
        </form>
    </section>
<?php endif; ?>

<section class="bloc bloc-etroit<?= $rapport !== null ? ' bloc-suivant' : '' ?>" aria-labelledby="titre-import">
    <div class="bloc-entete"><h2 id="titre-import">Importer un fichier</h2></div>
    <form class="formulaire" method="post" action="import.php" enctype="multipart/form-data" novalidate>
        <?= csrf_champ() ?>
        <input type="hidden" name="action" value="importer">
        <div class="grille-formulaire">
            <fieldset class="choix pleine-largeur">
                <legend>Que voulez-vous importer ?</legend>
                <div class="choix-liste">
                    <?php foreach ($types as $code => $definition): ?>
                        <label class="case"><input type="radio" name="type" value="<?= e($code) ?>"<?= $type === $code ? ' checked' : '' ?>> <?= e($definition['libelle']) ?></label>
                    <?php endforeach; ?>
                </div>
            </fieldset>
            <div class="champ pleine-largeur">
                <label for="f-fichier">Fichier CSV</label>
                <input id="f-fichier" type="file" name="fichier" accept=".csv,.txt,text/csv" aria-describedby="aide-fichier">
                <p class="aide" id="aide-fichier">
                    Jusqu'à <?= e(nombre(config('import.lignes_max', 5000))) ?> lignes. Depuis Excel : Fichier, Enregistrer sous,
                    puis « CSV UTF-8 » ou « CSV (séparateur : point-virgule) ».
                </p>
            </div>
            <label class="case pleine-largeur"><input type="checkbox" name="simulation" value="1"<?= $simulation ? ' checked' : '' ?>> Simuler seulement : vérifier le fichier sans rien enregistrer</label>
        </div>
        <div class="barre-boutons">
            <button class="bouton" type="submit"><?= icone('import', 16) ?> Importer le fichier</button>
        </div>
    </form>
</section>

<section class="bloc bloc-etroit bloc-suivant" aria-labelledby="titre-format">
    <div class="bloc-entete"><h2 id="titre-format">Format attendu</h2></div>
    <ul class="regles">
        <li>La première ligne du fichier contient les titres des colonnes, dans l'ordre de votre choix. Les colonnes inconnues sont ignorées.</li>
        <li>Le séparateur (point-virgule, virgule ou tabulation) et l'encodage (UTF-8, Windows-1252 ou UTF-16) sont reconnus automatiquement.</li>
        <li>Chaque ligne est vérifiée avec les mêmes règles que les formulaires. Une ligne refusée n'empêche pas d'importer les autres.</li>
        <li>Une ligne est aussi refusée si le client (même e-mail) ou la cuvée (même nom et même millésime) existe déjà, ou apparaît deux fois dans le fichier.</li>
    </ul>

    <?php foreach ($types as $code => $definition): ?>
        <h3><?= e($definition['libelle']) ?></h3>
        <div class="defilement">
            <table class="tableau tableau-compact">
                <caption class="sr-only">Colonnes du fichier de <?= e(mb_strtolower($definition['libelle'])) ?></caption>
                <thead>
                    <tr><th scope="col">Colonne</th><th scope="col">Obligatoire</th><th scope="col">Contenu attendu</th></tr>
                </thead>
                <tbody>
                <?php foreach ($definition['colonnes'] as $colonne): ?>
                    <tr>
                        <td class="nom"><?= e($colonne['titre']) ?></td>
                        <td><?= $colonne['obligatoire'] ? 'Oui' : 'Non' ?></td>
                        <td><?= e($colonne['aide']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($code === 'cuvees'): ?>
            <p class="legende-tableau">Un stock initial supérieur à 0 ajoute une entrée « Mise en bouteille » au journal des mouvements : le stock reste égal à la somme des mouvements.</p>
        <?php endif; ?>
        <p class="liens-modeles">
            <a href="modeles/<?= e($code) ?>.csv" download>Télécharger un modèle à compléter</a>
            <a href="modeles/<?= e($code) ?>_avec_erreurs.csv" download>Télécharger un exemple avec des erreurs</a>
        </p>
    <?php endforeach; ?>
</section>
<?php
page_fin();
