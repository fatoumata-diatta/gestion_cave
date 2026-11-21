<?php
declare(strict_types=1);

/** F3 : liste des clients, avec recherche (nom, e-mail, ville) et filtre par type. */
require __DIR__ . '/src/bootstrap.php';

$recherche = champ_get('q');
$type      = champ_get('type');
$clients   = clients_liste($recherche, $type);
$filtre    = $recherche !== '' || $type !== '';

page_debut(
    'Clients',
    'clients',
    'Particuliers et professionnels, avec leurs commandes validées.',
    '<a class="bouton bouton-secondaire" href="import.php?type=clients">' . icone('import') . ' Importer un CSV</a>'
    . '<a class="bouton bouton-secondaire" href="' . e(export_url('clients', ['q' => $recherche, 'type' => $type])) . '">' . icone('export') . ' Exporter en CSV</a>'
    . '<a class="bouton" href="client_form.php">' . icone('plus') . ' Nouveau client</a>'
);
?>
<form class="filtres" method="get" action="clients.php" role="search">
    <div class="champ champ-large">
        <label for="f-q">Rechercher un client</label>
        <input id="f-q" type="search" name="q" value="<?= e($recherche) ?>" placeholder="Nom, e-mail ou ville">
    </div>
    <div class="champ">
        <label for="f-type">Type de client</label>
        <select id="f-type" name="type">
            <option value="">Tous</option>
            <?php foreach (TYPES_CLIENT as $code => $libelle): ?>
                <option value="<?= e($code) ?>"<?= $type === $code ? ' selected' : '' ?>><?= e($libelle) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button class="bouton bouton-secondaire" type="submit"><?= icone('recherche', 16) ?> Filtrer</button>
    <?php if ($filtre): ?><a href="clients.php">Effacer les filtres</a><?php endif; ?>
</form>

<?php if (!$clients): ?>
    <div class="vide">
        <p><?= $filtre ? 'Aucun client ne correspond à ces filtres.' : 'Aucun client pour le moment.' ?></p>
        <p><a href="client_form.php">Ajouter un client</a></p>
    </div>
<?php else: ?>
    <div class="defilement">
        <table class="tableau">
            <caption class="sr-only">Liste des clients</caption>
            <thead>
                <tr>
                    <th scope="col">Client</th>
                    <th scope="col">Type</th>
                    <th scope="col">Ville</th>
                    <th scope="col">E-mail</th>
                    <th scope="col" class="nombre">Commandes</th>
                    <th scope="col" class="nombre">Chiffre d'affaires</th>
                    <th scope="col"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($clients as $client): ?>
                <tr>
                    <td class="nom"><?= e($client['nom']) ?></td>
                    <td><?= $client['type_client'] === 'pro'
                        ? '<span class="badge badge-pro">Professionnel</span>'
                        : '<span class="badge badge-neutre">Particulier</span>' ?></td>
                    <td><?= e($client['ville']) ?></td>
                    <td class="discret"><?= e($client['email']) ?></td>
                    <td class="nombre">
                        <?php if ((int) $client['nb_commandes'] > 0): ?>
                            <a href="commandes.php?client=<?= (int) $client['id'] ?>"><?= e(nombre($client['nb_commandes'])) ?></a>
                        <?php else: ?>
                            0
                        <?php endif; ?>
                    </td>
                    <td class="nombre"><?= e(money($client['ca'])) ?></td>
                    <td>
                        <div class="actions-ligne">
                            <a class="bouton bouton-secondaire bouton-petit" href="commande_nouvelle.php?id_client=<?= (int) $client['id'] ?>"><?= icone('plus', 14) ?> Commande</a>
                            <a class="bouton bouton-secondaire bouton-petit" href="client_form.php?id=<?= (int) $client['id'] ?>"><?= icone('modifier', 14) ?> Modifier</a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="legende-tableau">
        <?= e(pluriel(count($clients), 'client')) ?>. Un client n'est jamais supprimé : il garde l'historique de ses commandes.
    </p>
<?php endif; ?>
<?php
page_fin();
