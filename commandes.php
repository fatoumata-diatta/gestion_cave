<?php
declare(strict_types=1);

/** Liste des commandes, avec filtres par période, client, cuvée et statut (F8). */
require __DIR__ . '/src/bootstrap.php';

$filtres = commandes_filtres_get();
$commandes = commandes_liste($filtres);
$filtre = $filtres['du'] !== '' || $filtres['au'] !== '' || $filtres['id_client'] > 0
    || $filtres['id_cuvee'] > 0 || $filtres['statut'] !== '';

$clients = clients_pour_liste();
$cuvees  = cuvees_pour_commande();

$totalValide = 0.0;
$nbValidees = 0;
foreach ($commandes as $c) {
    if ($c['statut'] === 'validee') {
        $totalValide += (float) $c['total'];
        $nbValidees++;
    }
}

// L'export CSV reprend les filtres affichés : le fichier contient exactement les lignes de l'écran.
$parametresExport = [
    'du' => $filtres['du'], 'au' => $filtres['au'],
    'client' => $filtres['id_client'] ?: '', 'cuvee' => $filtres['id_cuvee'] ?: '', 'statut' => $filtres['statut'],
];

page_debut(
    'Commandes',
    'commandes',
    'Toutes les commandes, de la plus récente à la plus ancienne.',
    '<a class="bouton bouton-secondaire" href="' . e(export_url('commandes', $parametresExport)) . '">' . icone('export') . ' Exporter en CSV</a>'
    . '<a class="bouton" href="commande_nouvelle.php">' . icone('plus') . ' Nouvelle commande</a>'
);
?>
<form class="filtres" method="get" action="commandes.php">
    <div class="champ">
        <label for="f-du">Du</label>
        <input id="f-du" type="date" name="du" value="<?= e($filtres['du']) ?>">
    </div>
    <div class="champ">
        <label for="f-au">Au</label>
        <input id="f-au" type="date" name="au" value="<?= e($filtres['au']) ?>">
    </div>
    <div class="champ">
        <label for="f-client">Client</label>
        <select id="f-client" name="client">
            <option value="">Tous les clients</option>
            <?php foreach ($clients as $c): ?>
                <option value="<?= (int) $c['id'] ?>"<?= $filtres['id_client'] === (int) $c['id'] ? ' selected' : '' ?>><?= e($c['nom']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="champ">
        <label for="f-cuvee">Cuvée commandée</label>
        <select id="f-cuvee" name="cuvee">
            <option value="">Toutes les cuvées</option>
            <?php foreach ($cuvees as $c): ?>
                <option value="<?= (int) $c['id'] ?>"<?= $filtres['id_cuvee'] === (int) $c['id'] ? ' selected' : '' ?>><?= e($c['nom'] . ' ' . $c['millesime']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="champ">
        <label for="f-statut">Statut</label>
        <select id="f-statut" name="statut">
            <option value="">Tous</option>
            <?php foreach (STATUTS_COMMANDE as $code => $libelle): ?>
                <option value="<?= e($code) ?>"<?= $filtres['statut'] === $code ? ' selected' : '' ?>><?= e($libelle) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button class="bouton bouton-secondaire" type="submit"><?= icone('recherche', 16) ?> Filtrer</button>
    <?php if ($filtre): ?><a href="commandes.php">Effacer les filtres</a><?php endif; ?>
</form>

<?php if (!$commandes): ?>
    <div class="vide">
        <p><?= $filtre ? 'Aucune commande ne correspond à ces filtres.' : 'Aucune commande pour le moment.' ?></p>
        <p><a href="commande_nouvelle.php">Saisir une commande</a></p>
    </div>
<?php else: ?>
    <div class="defilement">
        <table class="tableau">
            <caption class="sr-only">Liste des commandes</caption>
            <thead>
                <tr>
                    <th scope="col">N°</th>
                    <th scope="col">Date</th>
                    <th scope="col">Client</th>
                    <th scope="col" class="nombre">Lignes</th>
                    <th scope="col" class="nombre">Bouteilles</th>
                    <th scope="col" class="nombre">Total</th>
                    <th scope="col">Statut</th>
                    <th scope="col"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($commandes as $c): ?>
                <tr>
                    <td class="nom"><a href="commande_voir.php?id=<?= (int) $c['id'] ?>"><?= (int) $c['id'] ?></a></td>
                    <td class="discret"><?= e(date_fr($c['date_commande'], true)) ?></td>
                    <td><?= e($c['client']) ?></td>
                    <td class="nombre"><?= e(nombre($c['nb_lignes'])) ?></td>
                    <td class="nombre"><?= e(nombre($c['nb_bouteilles'])) ?></td>
                    <td class="nombre"><?= e(money($c['total'])) ?></td>
                    <td><?= badge_statut((string) $c['statut']) ?></td>
                    <td>
                        <div class="actions-ligne">
                            <a class="bouton bouton-secondaire bouton-petit" href="commande_voir.php?id=<?= (int) $c['id'] ?>">Voir</a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="5">Total des commandes validées affichées (<?= e(nombre($nbValidees)) ?>)</td>
                    <td class="nombre"><?= e(money($totalValide)) ?></td>
                    <td colspan="2"></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <p class="legende-tableau"><?= e(pluriel(count($commandes), 'commande')) ?> affichée<?= accord(count($commandes)) ?>. Les commandes annulées ne sont pas comptées dans le total.
        Le détail ligne par ligne de ces commandes se télécharge <a href="<?= e(export_url('lignes', $parametresExport)) ?>">ici (CSV)</a>.</p>
<?php endif; ?>
<?php
page_fin();
