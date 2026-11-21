<?php
declare(strict_types=1);

/** F9 : liste des exports CSV disponibles. */
require __DIR__ . '/src/bootstrap.php';

$exports = export_types();

page_debut(
    'Export CSV',
    'export',
    'Téléchargez les données de la cave dans un fichier qui s\'ouvre directement dans Excel.'
);
?>
<div class="defilement">
    <table class="tableau">
        <caption class="sr-only">Exports disponibles</caption>
        <thead>
            <tr>
                <th scope="col">Fichier</th>
                <th scope="col">Colonnes</th>
                <th scope="col"><span class="sr-only">Télécharger</span></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($exports as $code => $export): ?>
            <tr>
                <td>
                    <span class="nom"><?= e($export['libelle']) ?></span>
                    <span class="note-prix"><?= e($export['description']) ?></span>
                </td>
                <td class="discret"><?= e(implode(', ', $export['colonnes'])) ?></td>
                <td>
                    <div class="actions-ligne">
                        <a class="bouton bouton-secondaire bouton-petit" href="<?= e(export_url($code)) ?>"><?= icone('export', 16) ?> Télécharger<span class="sr-only"> : <?= e($export['libelle']) ?></span></a>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<section class="bloc bloc-etroit bloc-suivant" aria-labelledby="titre-notes">
    <div class="bloc-entete"><h2 id="titre-notes">À savoir</h2></div>
    <ul class="regles">
        <li>Pour n'exporter qu'une sélection (une période, un client, une cuvée, un statut), filtrez la liste dans son écran puis
            utilisez le bouton « Exporter en CSV » : le fichier contient exactement les lignes affichées. Cela vaut pour
            <a href="commandes.php">les commandes</a>, <a href="stock.php">le stock</a>, <a href="clients.php">les clients</a>
            et <a href="cuvees.php">les cuvées</a>. Pour <a href="mouvements.php">les mouvements</a>, l'écran n'affiche que les
            200 plus récents, alors que le fichier contient tout le journal (avec les mêmes filtres).</li>
        <li>Format : séparateur point-virgule, virgule décimale, dates au format AAAA-MM-JJ HH:MM:SS, encodage UTF-8. Excel le reconnaît d'un double-clic.</li>
        <li>Les fichiers « Clients » et « Cuvées » peuvent être réimportés tels quels dans une autre base
            (<a href="import.php">Import CSV</a>) : les colonnes de calcul sont ignorées.</li>
    </ul>
</section>
<?php
page_fin();
