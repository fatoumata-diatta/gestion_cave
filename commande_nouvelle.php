<?php
declare(strict_types=1);

/**
 * F4 : saisie d'une commande (client + lignes, total calculé).
 * F5 : à la validation, le stock baisse ; si une quantité dépasse le stock, la commande est refusée
 *      et la base n'est pas modifiée (voir commande_creer() dans src/commandes.php).
 */
require __DIR__ . '/src/bootstrap.php';

$cuvees  = cuvees_pour_commande();
$clients = clients_pour_liste();

$valeurs = ['id_client' => entier_get('id_client') > 0 ? (string) entier_get('id_client') : ''];
$lignes  = [['id_cuvee' => '', 'quantite' => ''], ['id_cuvee' => '', 'quantite' => '']];
$erreurs = [];

if (est_post()) {
    csrf_verifier();
    $valeurs['id_client'] = champ_post('id_client');

    // Lignes saisies : on ne garde que des textes (le navigateur peut envoyer n'importe quoi).
    $lignes = [];
    $brutes = $_POST['lignes'] ?? [];
    foreach (is_array($brutes) ? array_slice($brutes, 0, MAX_LIGNES_PAR_COMMANDE + 1) : [] as $brute) {
        $lignes[] = [
            'id_cuvee' => is_array($brute) && is_string($brute['id_cuvee'] ?? null) ? $brute['id_cuvee'] : '',
            'quantite' => is_array($brute) && is_string($brute['quantite'] ?? null) ? $brute['quantite'] : '',
        ];
    }

    try {
        $idCommande = commande_creer((int) ($valeurs['id_client'] !== '' ? $valeurs['id_client'] : 0), $lignes);
        flash('succes', 'La commande n°' . $idCommande . ' est enregistrée et le stock a été mis à jour.');
        redirect('commande_voir.php', ['id' => $idCommande]);
    } catch (RegleGestionException $erreur) {
        // Refus : rien n'a été écrit en base. On réaffiche le formulaire tel qu'il a été rempli.
        $erreurs = $erreur->erreurs();
    }
    while (count($lignes) < 2) {
        $lignes[] = ['id_cuvee' => '', 'quantite' => ''];
    }
}

/** Liste déroulante des cuvées pour une ligne (le prix et le stock servent au calcul affiché en direct). */
function options_cuvees(array $cuvees, string $choisie): string
{
    $html = '<option value="">Choisir une cuvée</option>';
    foreach ($cuvees as $c) {
        $html .= sprintf(
            '<option value="%d" data-prix="%s" data-stock="%d"%s>%s</option>',
            (int) $c['id'],
            e(number_format((float) $c['prix_vente'], 2, '.', '')),
            (int) $c['stock'],
            (string) $c['id'] === $choisie ? ' selected' : '',
            e($c['nom'] . ' ' . $c['millesime'])
        );
    }
    return $html;
}

/** Une ligne du tableau de saisie. */
function ligne_saisie(array $cuvees, string $indice, string $idCuvee, string $quantite): string
{
    return '<tr class="ligne">'
        . '<td class="colonne-cuvee"><label class="sr-only" for="cuvee-' . e($indice) . '">Cuvée</label>'
        . '<select id="cuvee-' . e($indice) . '" name="lignes[' . e($indice) . '][id_cuvee]">' . options_cuvees($cuvees, $idCuvee) . '</select></td>'
        . '<td class="colonne-quantite"><label class="sr-only" for="qte-' . e($indice) . '">Quantité</label>'
        . '<input id="qte-' . e($indice) . '" type="number" name="lignes[' . e($indice) . '][quantite]" min="1" step="1" inputmode="numeric" value="' . e($quantite) . '"></td>'
        . '<td class="nombre prix-ligne"></td>'
        . '<td class="stock-ligne"></td>'
        . '<td class="nombre sous-total"></td>'
        . '<td><button class="bouton bouton-secondaire bouton-petit retirer-ligne" type="button" aria-label="Retirer cette ligne">' . icone('corbeille', 16) . '</button></td>'
        . '</tr>';
}

page_debut(
    'Nouvelle commande',
    'commande_nouvelle',
    'Choisissez le client, ajoutez les cuvées et les quantités. Le stock est vérifié puis mis à jour à la validation.'
);
?>
<?= bloc_erreurs($erreurs, 'Commande refusée : le stock et les commandes n\'ont pas été modifiés') ?>

<?php if (!$clients || !$cuvees): ?>
    <div class="vide">
        <p><?= !$clients ? 'Il faut au moins un client pour saisir une commande.' : 'Il faut au moins une cuvée pour saisir une commande.' ?></p>
        <p><a href="<?= !$clients ? 'client_form.php' : 'cuvee_form.php' ?>"><?= !$clients ? 'Ajouter un client' : 'Ajouter une cuvée' ?></a></p>
    </div>
<?php else: ?>
<form class="saisie-commande" method="post" action="commande_nouvelle.php" novalidate>
    <?= csrf_champ() ?>

    <div class="bloc-client">
        <?php
        $optionsClients = [];
        foreach ($clients as $c) {
            $optionsClients[(string) $c['id']] = $c['nom'] . ' (' . $c['ville'] . ')';
        }
        echo champ_select('id_client', 'Client', $optionsClients, $valeurs, [], 'Choisir un client');
        ?>
    </div>

    <div class="defilement">
        <table class="tableau lignes-commande">
            <caption class="sr-only">Lignes de la commande</caption>
            <thead>
                <tr>
                    <th scope="col">Cuvée</th>
                    <th scope="col" class="nombre">Quantité</th>
                    <th scope="col" class="nombre">Prix unitaire</th>
                    <th scope="col">Disponibilité</th>
                    <th scope="col" class="nombre">Total ligne</th>
                    <th scope="col"><span class="sr-only">Retirer</span></th>
                </tr>
            </thead>
            <tbody id="lignes">
            <?php foreach ($lignes as $i => $ligne): ?>
                <?= ligne_saisie($cuvees, (string) $i, $ligne['id_cuvee'], $ligne['quantite']) . "\n" ?>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4">Total de la commande</td>
                    <td class="nombre" id="total-commande"><?= e(money(0)) ?></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <template id="modele-ligne"><?= ligne_saisie($cuvees, '__I__', '', '') ?></template>

    <p class="legende-tableau">
        Le prix de chaque cuvée est recopié dans la commande au moment de la validation : un changement de tarif ultérieur ne modifie pas les commandes passées.
    </p>

    <div class="barre-boutons">
        <button class="bouton bouton-secondaire" type="button" id="ajouter-ligne"><?= icone('plus', 16) ?> Ajouter une ligne</button>
        <button class="bouton" type="submit"><?= icone('ok', 16) ?> Valider la commande</button>
        <a class="bouton bouton-secondaire" href="commandes.php">Annuler</a>
    </div>
</form>
<?php endif; ?>
<?php
page_fin(['commande.js']);
