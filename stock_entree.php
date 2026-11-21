<?php
declare(strict_types=1);

/** F2 : saisie d'une entrée de stock (mise en bouteille). */
require __DIR__ . '/src/bootstrap.php';

$cuvees = cuvees_pour_commande();
$valeurs = [
    'id_cuvee' => (string) entier_get('id_cuvee'),
    'quantite' => '',
    'motif'    => '',
];
if ($valeurs['id_cuvee'] === '0') {
    $valeurs['id_cuvee'] = '';
}
$erreurs = [];

if (est_post()) {
    csrf_verifier();
    foreach (array_keys($valeurs) as $cle) {
        $valeurs[$cle] = champ_post($cle);
    }
    try {
        $idCuvee = lire_entier($valeurs['id_cuvee']) ?? 0;
        $nouveauStock = stock_entree($idCuvee, $valeurs['quantite'], $valeurs['motif']);
        $cuvee = cuvee_trouver($idCuvee);
        flash('succes', sprintf(
            'Entrée enregistrée pour « %s %d » : le stock passe à %s.',
            $cuvee['nom'] ?? '',
            $cuvee['millesime'] ?? 0,
            pluriel($nouveauStock, 'bouteille')
        ));
        redirect('stock.php');
    } catch (RegleGestionException $erreur) {
        $erreurs = $erreur->erreurs();
    }
}

$options = [];
foreach ($cuvees as $c) {
    $options[(string) $c['id']] = sprintf('%s %d (stock actuel : %s)', $c['nom'], $c['millesime'], nombre($c['stock']));
}

page_debut('Saisir une entrée de stock', 'stock', 'Ajoute des bouteilles au stock et écrit le mouvement dans le journal.');
?>
<?= bloc_erreurs($erreurs, 'L\'entrée n\'a pas été enregistrée') ?>
<form class="formulaire" method="post" action="stock_entree.php" novalidate>
    <?= csrf_champ() ?>
    <div class="grille-formulaire">
        <div class="pleine-largeur">
            <?= champ_select('id_cuvee', 'Cuvée', $options, $valeurs, [], 'Choisir une cuvée') ?>
        </div>
        <?= champ_texte('quantite', 'Quantité (bouteilles)', $valeurs, [], ['required' => true, 'inputmode' => 'numeric', 'maxlength' => '6']) ?>
        <?= champ_texte('motif', 'Motif', $valeurs, [], ['maxlength' => '120', 'placeholder' => 'Mise en bouteille'], 'Laissez vide pour « Mise en bouteille ».') ?>
    </div>
    <div class="barre-boutons">
        <button class="bouton" type="submit">Enregistrer l'entrée</button>
        <a class="bouton bouton-secondaire" href="stock.php">Annuler</a>
    </div>
</form>
<?php
page_fin();
