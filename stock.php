<?php
declare(strict_types=1);

/** F2 : stock par cuvée, avec jauge et alerte sous le seuil. */
require __DIR__ . '/src/bootstrap.php';

$recherche = champ_get('q');
$alertes   = champ_get('alertes') === '1';
$lignes    = stock_liste($recherche, $alertes);

// Échelle commune des jauges : le plus grand stock (ou seuil) affiché.
$echelle = 1;
foreach ($lignes as $ligne) {
    $echelle = max($echelle, (int) $ligne['quantite'], (int) $ligne['seuil_alerte']);
}
$totalBouteilles = array_sum(array_map(static fn(array $l): int => (int) $l['quantite'], $lignes));
$totalValeur = array_sum(array_map(static fn(array $l): float => (float) $l['valeur'], $lignes));

page_debut(
    'Stock',
    'stock',
    'Bouteilles disponibles pour chaque cuvée. Le trait noir de la jauge marque le seuil d\'alerte.',
    '<a class="bouton bouton-secondaire" href="mouvements.php">' . icone('mouvements') . ' Journal des mouvements</a>'
    . '<a class="bouton bouton-secondaire" href="' . e(export_url('stock', ['q' => $recherche, 'alertes' => $alertes ? '1' : ''])) . '">' . icone('export') . ' Exporter en CSV</a>'
    . '<a class="bouton" href="stock_entree.php">' . icone('plus') . ' Saisir une entrée</a>'
);
?>
<form class="filtres" method="get" action="stock.php" role="search">
    <div class="champ champ-large">
        <label for="f-q">Rechercher une cuvée</label>
        <input id="f-q" type="search" name="q" value="<?= e($recherche) ?>" placeholder="Nom ou millésime">
    </div>
    <label class="case"><input type="checkbox" name="alertes" value="1"<?= $alertes ? ' checked' : '' ?>> Alertes seulement</label>
    <button class="bouton bouton-secondaire" type="submit"><?= icone('recherche', 16) ?> Filtrer</button>
    <?php if ($recherche !== '' || $alertes): ?><a href="stock.php">Effacer les filtres</a><?php endif; ?>
</form>

<?php if (!$lignes): ?>
    <div class="vide"><p><?= $alertes ? 'Aucune cuvée n\'est sous son seuil d\'alerte.' : 'Aucune cuvée ne correspond à cette recherche.' ?></p></div>
<?php else: ?>
    <div class="defilement">
        <table class="tableau">
            <caption class="sr-only">Stock de chaque cuvée</caption>
            <thead>
                <tr>
                    <th scope="col">Cuvée</th>
                    <th scope="col">Niveau</th>
                    <th scope="col" class="nombre">Stock</th>
                    <th scope="col" class="nombre">Seuil</th>
                    <th scope="col">État</th>
                    <th scope="col" class="nombre">Valeur</th>
                    <th scope="col"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($lignes as $ligne):
                $quantite = (int) $ligne['quantite'];
                $seuil = (int) $ligne['seuil_alerte'];
                $niveau = niveau_stock($quantite, $seuil);
                ?>
                <tr>
                    <td class="nom"><?= e($ligne['nom'] . ' ' . $ligne['millesime']) ?></td>
                    <td style="min-width:9rem"><?= jauge_stock($quantite, $seuil, $echelle) ?></td>
                    <td class="nombre"><strong><?= e(nombre($quantite)) ?></strong></td>
                    <td class="nombre discret"><?= e(nombre($seuil)) ?></td>
                    <td><?= badge_stock($niveau) ?></td>
                    <td class="nombre"><?= e(money($ligne['valeur'])) ?></td>
                    <td>
                        <div class="actions-ligne">
                            <a class="bouton bouton-secondaire bouton-petit" href="stock_entree.php?id_cuvee=<?= (int) $ligne['id'] ?>"><?= icone('plus', 14) ?> Entrée</a>
                            <a class="bouton bouton-secondaire bouton-petit" href="mouvements.php?id_cuvee=<?= (int) $ligne['id'] ?>">Historique</a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="2">Total affiché</td>
                    <td class="nombre"><?= e(nombre($totalBouteilles)) ?></td>
                    <td colspan="2"></td>
                    <td class="nombre"><?= e(money($totalValeur)) ?></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <p class="legende-tableau">La valeur est calculée au prix de vente actuel de chaque cuvée.</p>
<?php endif; ?>
<?php
page_fin();
