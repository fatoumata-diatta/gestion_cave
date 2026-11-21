<?php
declare(strict_types=1);

/** F1 : liste des cuvées, avec recherche et filtre par couleur. */
require __DIR__ . '/src/bootstrap.php';

$recherche = champ_get('q');
$couleur   = champ_get('couleur');
$cuvees    = cuvees_liste($recherche, $couleur);
$filtre    = $recherche !== '' || $couleur !== '';

page_debut(
    'Cuvées',
    'cuvees',
    'Le catalogue des vins du domaine : prix de vente, degré d\'alcool et stock.',
    '<a class="bouton bouton-secondaire" href="import.php?type=cuvees">' . icone('import') . ' Importer un CSV</a>'
    . '<a class="bouton bouton-secondaire" href="' . e(export_url('cuvees', ['q' => $recherche, 'couleur' => $couleur])) . '">' . icone('export') . ' Exporter en CSV</a>'
    . '<a class="bouton" href="cuvee_form.php">' . icone('plus') . ' Nouvelle cuvée</a>'
);
?>
<form class="filtres" method="get" action="cuvees.php" role="search">
    <div class="champ champ-large">
        <label for="f-q">Rechercher une cuvée</label>
        <input id="f-q" type="search" name="q" value="<?= e($recherche) ?>" placeholder="Nom ou millésime">
    </div>
    <div class="champ">
        <label for="f-couleur">Couleur</label>
        <select id="f-couleur" name="couleur">
            <option value="">Toutes</option>
            <?php foreach (COULEURS as $code => $libelle): ?>
                <option value="<?= e($code) ?>"<?= $couleur === $code ? ' selected' : '' ?>><?= e($libelle) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button class="bouton bouton-secondaire" type="submit"><?= icone('recherche', 16) ?> Filtrer</button>
    <?php if ($filtre): ?><a href="cuvees.php">Effacer les filtres</a><?php endif; ?>
</form>

<?php if (!$cuvees): ?>
    <div class="vide">
        <p><?= $filtre ? 'Aucune cuvée ne correspond à ces filtres.' : 'Le catalogue est vide.' ?></p>
        <p><a href="cuvee_form.php">Ajouter une cuvée</a></p>
    </div>
<?php else: ?>
    <div class="defilement">
        <table class="tableau">
            <caption class="sr-only">Liste des cuvées</caption>
            <thead>
                <tr>
                    <th scope="col">Cuvée</th>
                    <th scope="col">Couleur</th>
                    <th scope="col" class="nombre">Degré</th>
                    <th scope="col" class="nombre">Prix de vente</th>
                    <th scope="col" class="nombre">Stock</th>
                    <th scope="col"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($cuvees as $cuvee):
                $niveau = niveau_stock((int) $cuvee['stock'], (int) $cuvee['seuil_alerte']);
                $titre = $cuvee['nom'] . ' ' . $cuvee['millesime'];
                ?>
                <tr>
                    <td class="nom"><?= e($titre) ?></td>
                    <td><?= puce_couleur($cuvee['couleur']) ?></td>
                    <td class="nombre"><?= e(number_format((float) $cuvee['degre'], 1, ',', '')) ?> %</td>
                    <td class="nombre"><?= e(money($cuvee['prix_vente'])) ?></td>
                    <td class="nombre"><?= e(nombre($cuvee['stock'])) ?> <?= badge_stock($niveau) ?></td>
                    <td>
                        <div class="actions-ligne">
                            <a class="bouton bouton-secondaire bouton-petit" href="cuvee_form.php?id=<?= (int) $cuvee['id'] ?>"><?= icone('modifier', 14) ?> Modifier</a>
                            <form method="post" action="cuvee_supprimer.php"
                                  data-confirm="<?= e('Supprimer la cuvée « ' . $titre . ' » ?') ?>">
                                <?= csrf_champ() ?>
                                <input type="hidden" name="id" value="<?= (int) $cuvee['id'] ?>">
                                <button class="bouton bouton-danger bouton-petit" type="submit"><?= icone('corbeille', 14) ?> Supprimer</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="legende-tableau">
        <?= e(pluriel(count($cuvees), 'cuvée')) ?>.
        Une cuvée qui a déjà été commandée ou mise en stock ne peut pas être supprimée : son historique reste intact.
    </p>
<?php endif; ?>
<?php
page_fin();
