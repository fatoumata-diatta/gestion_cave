<?php
declare(strict_types=1);

/** Journal des mouvements de stock : toutes les entrées et sorties, avec leur origine. */
require __DIR__ . '/src/bootstrap.php';

$idCuvee = entier_get('id_cuvee');
$type    = champ_get('type');
$type    = in_array($type, ['entree', 'sortie'], true) ? $type : '';

$mouvements = mouvements_liste(['id_cuvee' => $idCuvee, 'type' => $type], 200);
$cuvees = cuvees_pour_commande();
$filtre = $idCuvee > 0 || $type !== '';

page_debut(
    'Mouvements de stock',
    'mouvements',
    'Le journal de chaque entrée et sortie. Le stock affiché est toujours égal à la somme de ces mouvements.',
    '<a class="bouton bouton-secondaire" href="' . e(export_url('mouvements', ['id_cuvee' => $idCuvee ?: '', 'type' => $type])) . '">' . icone('export') . ' Exporter en CSV</a>'
);
?>
<form class="filtres" method="get" action="mouvements.php">
    <div class="champ champ-large">
        <label for="f-cuvee">Cuvée</label>
        <select id="f-cuvee" name="id_cuvee">
            <option value="">Toutes les cuvées</option>
            <?php foreach ($cuvees as $c): ?>
                <option value="<?= (int) $c['id'] ?>"<?= $idCuvee === (int) $c['id'] ? ' selected' : '' ?>><?= e($c['nom'] . ' ' . $c['millesime']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="champ">
        <label for="f-type">Type</label>
        <select id="f-type" name="type">
            <option value="">Entrées et sorties</option>
            <option value="entree"<?= $type === 'entree' ? ' selected' : '' ?>>Entrées</option>
            <option value="sortie"<?= $type === 'sortie' ? ' selected' : '' ?>>Sorties</option>
        </select>
    </div>
    <button class="bouton bouton-secondaire" type="submit"><?= icone('recherche', 16) ?> Filtrer</button>
    <?php if ($filtre): ?><a href="mouvements.php">Effacer les filtres</a><?php endif; ?>
</form>

<?php if (!$mouvements): ?>
    <div class="vide"><p>Aucun mouvement ne correspond à ces filtres.</p></div>
<?php else: ?>
    <div class="defilement">
        <table class="tableau">
            <caption class="sr-only">Journal des mouvements de stock, du plus récent au plus ancien</caption>
            <thead>
                <tr>
                    <th scope="col">Date</th>
                    <th scope="col">Cuvée</th>
                    <th scope="col">Mouvement</th>
                    <th scope="col" class="nombre">Quantité</th>
                    <th scope="col">Motif</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($mouvements as $m):
                $entree = $m['type_mouvement'] === 'entree';
                ?>
                <tr>
                    <td class="discret"><?= e(date_fr($m['date_mouvement'], true)) ?></td>
                    <td class="nom"><?= e($m['nom'] . ' ' . $m['millesime']) ?></td>
                    <td><?= $entree ? '<span class="badge badge-ok">Entrée</span>' : '<span class="badge badge-neutre">Sortie</span>' ?></td>
                    <td class="nombre"><?= $entree ? '+' : '−' ?><?= e(nombre($m['quantite'])) ?></td>
                    <td>
                        <?php if ($m['id_commande'] !== null): ?>
                            <a href="commande_voir.php?id=<?= (int) $m['id_commande'] ?>"><?= e($m['motif']) ?></a>
                        <?php else: ?>
                            <?= e($m['motif']) ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="legende-tableau">
        <?= count($mouvements) >= 200 ? 'Les 200 mouvements les plus récents sont affichés ; le fichier CSV contient tout le journal.' : e(pluriel(count($mouvements), 'mouvement')) . '.' ?>
    </p>
<?php endif; ?>
<?php
page_fin();
