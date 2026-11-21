<?php
declare(strict_types=1);

/**
 * Fiche d'une commande : client, lignes (prix figés au moment de la vente), total,
 * mouvements de stock générés, et annulation.
 */
require __DIR__ . '/src/bootstrap.php';

$id = entier_get('id');
$commande = $id > 0 ? commande_detail($id) : null;
if ($commande === null) {
    flash('erreur', 'Cette commande n\'existe pas.');
    redirect('commandes.php');
}

$annulee = $commande['statut'] === 'annulee';

// Le bouton d'annulation est un formulaire POST protégé par jeton (une action qui modifie la base n'est jamais un simple lien).
$actions = '<a class="bouton bouton-secondaire" href="commandes.php">Toutes les commandes</a>';
if (!$annulee) {
    $actions .= '<form method="post" action="commande_annuler.php" data-confirm="Annuler la commande n°' . (int) $commande['id']
        . ' ? Les bouteilles seront remises en stock.">'
        . csrf_champ()
        . '<input type="hidden" name="id" value="' . (int) $commande['id'] . '">'
        . '<button class="bouton bouton-danger" type="submit">' . icone('annuler', 16) . ' Annuler la commande</button>'
        . '</form>';
}

page_debut(
    'Commande n°' . (int) $commande['id'],
    'commandes',
    'Passée le ' . date_fr($commande['date_commande'], true) . ' par ' . $commande['client'] . '.',
    $actions
);

$ecartPrix = false;
foreach ($commande['lignes'] as $ligne) {
    if (abs((float) $ligne['prix_unitaire'] - (float) $ligne['prix_actuel']) >= 0.005) {
        $ecartPrix = true;
    }
}
?>
<?php if ($annulee): ?>
    <div class="alerte alerte-info" role="status">
        <?= icone('annuler', 18) ?>
        <p>Cette commande est annulée. Les bouteilles ont été remises en stock et la commande ne compte plus dans le chiffre d'affaires.</p>
    </div>
<?php endif; ?>

<dl class="fiche">
    <div>
        <dt>Client</dt>
        <dd>
            <a href="commandes.php?client=<?= (int) $commande['id_client'] ?>"><?= e($commande['client']) ?></a>
            <span class="note-prix"><?= e(TYPES_CLIENT[$commande['type_client']] ?? $commande['type_client']) ?>, <?= e($commande['ville']) ?></span>
        </dd>
    </div>
    <div>
        <dt>E-mail</dt>
        <dd><?= e($commande['email']) ?></dd>
    </div>
    <div>
        <dt>Statut</dt>
        <dd><?= badge_statut((string) $commande['statut']) ?></dd>
    </div>
</dl>

<section class="bloc bloc-etroit" aria-labelledby="titre-lignes">
    <div class="bloc-entete">
        <h2 id="titre-lignes">Lignes de la commande</h2>
        <p class="bloc-note"><?= e(pluriel($commande['nb_lignes'], 'ligne')) ?>, <?= e(pluriel($commande['nb_bouteilles'], 'bouteille')) ?></p>
    </div>
    <div class="defilement">
        <table class="tableau">
            <caption class="sr-only">Lignes de la commande n°<?= (int) $commande['id'] ?></caption>
            <thead>
                <tr>
                    <th scope="col">Cuvée</th>
                    <th scope="col">Couleur</th>
                    <th scope="col" class="nombre">Quantité</th>
                    <th scope="col" class="nombre">Prix unitaire</th>
                    <th scope="col" class="nombre">Total ligne</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($commande['lignes'] as $ligne):
                $different = abs((float) $ligne['prix_unitaire'] - (float) $ligne['prix_actuel']) >= 0.005;
                ?>
                <tr>
                    <td class="nom"><?= e($ligne['nom'] . ' ' . $ligne['millesime']) ?></td>
                    <td><?= puce_couleur((string) $ligne['couleur']) ?></td>
                    <td class="nombre"><?= e(nombre($ligne['quantite'])) ?></td>
                    <td class="nombre">
                        <?= e(money($ligne['prix_unitaire'])) ?>
                        <?php if ($different): ?>
                            <span class="note-prix">Tarif actuel : <?= e(money($ligne['prix_actuel'])) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="nombre"><?= e(money($ligne['total_ligne'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4">Total de la commande</td>
                    <td class="nombre"><?= e(money($commande['total'])) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php if ($ecartPrix): ?>
        <p class="legende-tableau">
            Le prix de chaque ligne a été recopié le jour de la vente : le tarif de la cuvée a changé depuis, mais cette commande garde son prix d'origine.
        </p>
    <?php endif; ?>
</section>

<section class="bloc bloc-etroit bloc-suivant" aria-labelledby="titre-mouvements">
    <div class="bloc-entete">
        <h2 id="titre-mouvements">Mouvements de stock de cette commande</h2>
        <p class="bloc-note">Écrits dans la même transaction que la commande</p>
    </div>
    <?php if (!$commande['mouvements']): ?>
        <p class="bloc-note">Aucun mouvement lié à cette commande.</p>
    <?php else: ?>
        <div class="defilement">
            <table class="tableau">
                <caption class="sr-only">Mouvements de stock liés à la commande n°<?= (int) $commande['id'] ?></caption>
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
                <?php foreach ($commande['mouvements'] as $m):
                    $entree = $m['type_mouvement'] === 'entree';
                    ?>
                    <tr>
                        <td class="discret"><?= e(date_fr($m['date_mouvement'], true)) ?></td>
                        <td class="nom"><?= e($m['nom'] . ' ' . $m['millesime']) ?></td>
                        <td><?= $entree ? '<span class="badge badge-ok">Entrée</span>' : '<span class="badge badge-neutre">Sortie</span>' ?></td>
                        <td class="nombre"><?= $entree ? '+' : '−' ?><?= e(nombre($m['quantite'])) ?></td>
                        <td><?= e($m['motif']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php
page_fin();
