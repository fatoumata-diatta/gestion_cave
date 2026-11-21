<?php
declare(strict_types=1);

/** F1 : ajout et modification d'une cuvée (le même formulaire sert aux deux). */
require __DIR__ . '/src/bootstrap.php';

$id = entier_get('id');
$cuvee = null;
if ($id > 0) {
    $cuvee = cuvee_trouver($id);
    if ($cuvee === null) {
        flash('erreur', 'Cette cuvée n\'existe pas.');
        redirect('cuvees.php');
    }
}
$edition = $cuvee !== null;

$valeurs = $edition
    ? [
        'nom'          => (string) $cuvee['nom'],
        'millesime'    => (string) $cuvee['millesime'],
        'couleur'      => (string) $cuvee['couleur'],
        'degre'        => number_format((float) $cuvee['degre'], 1, ',', ''),
        'prix_vente'   => number_format((float) $cuvee['prix_vente'], 2, ',', ''),
        'seuil_alerte' => (string) $cuvee['seuil_alerte'],
    ]
    : [
        'nom' => '', 'millesime' => (string) ((int) date('Y') - 1), 'couleur' => '',
        'degre' => '', 'prix_vente' => '', 'seuil_alerte' => '24', 'stock_initial' => '0',
    ];

$erreurs = [];           // erreurs par champ
$erreursGenerales = [];  // erreurs qui ne concernent pas un champ précis

if (est_post()) {
    csrf_verifier();
    foreach ($_POST as $cle => $valeur) {
        if (is_string($valeur) && array_key_exists($cle, $valeurs)) {
            $valeurs[$cle] = $valeur;
        }
    }
    [$donnees, $erreurs] = cuvee_valider($_POST);

    if (!$erreurs) {
        try {
            $titre = $donnees['nom'] . ' ' . $donnees['millesime'];
            if ($edition) {
                cuvee_modifier($id, $donnees);
                flash('succes', 'La cuvée « ' . $titre . ' » a été modifiée.');
            } else {
                cuvee_creer($donnees);
                flash('succes', 'La cuvée « ' . $titre . ' » a été ajoutée.');
            }
            redirect('cuvees.php');
        } catch (RegleGestionException $erreur) {
            $erreursGenerales = $erreur->erreurs();
        }
    }
}

$titrePage = $edition ? 'Modifier la cuvée' : 'Nouvelle cuvée';
page_debut(
    $titrePage,
    'cuvees',
    $edition ? $cuvee['nom'] . ' ' . $cuvee['millesime'] : 'Renseignez la cuvée, puis ajoutez son stock.'
);
?>
<?= bloc_erreurs(array_merge($erreursGenerales, array_values($erreurs))) ?>
<form class="formulaire" method="post" action="cuvee_form.php<?= $edition ? '?id=' . $id : '' ?>" novalidate>
    <?= csrf_champ() ?>
    <div class="grille-formulaire">
        <div class="pleine-largeur">
            <?= champ_texte('nom', 'Nom de la cuvée', $valeurs, $erreurs, ['required' => true, 'maxlength' => '100']) ?>
        </div>
        <?= champ_texte('millesime', 'Millésime', $valeurs, $erreurs, ['required' => true, 'inputmode' => 'numeric', 'maxlength' => '4']) ?>
        <?= champ_select('couleur', 'Couleur', COULEURS, $valeurs, $erreurs, 'Choisir une couleur') ?>
        <?= champ_texte('degre', 'Degré d\'alcool (% vol.)', $valeurs, $erreurs, ['required' => true, 'inputmode' => 'decimal', 'maxlength' => '5'], 'Par exemple 13,5') ?>
        <?= champ_texte(
            'prix_vente',
            'Prix de vente (€)',
            $valeurs,
            $erreurs,
            ['required' => true, 'inputmode' => 'decimal', 'maxlength' => '9'],
            $edition ? 'Le nouveau prix s\'applique aux prochaines commandes. Les commandes déjà passées gardent leur prix.' : 'Par exemple 14,90'
        ) ?>
        <?= champ_texte('seuil_alerte', 'Seuil d\'alerte (bouteilles)', $valeurs, $erreurs, ['inputmode' => 'numeric', 'maxlength' => '6'], 'Une alerte apparaît quand le stock est à ce niveau ou en dessous.') ?>
        <?php if (!$edition): ?>
            <?= champ_texte('stock_initial', 'Stock initial (bouteilles)', $valeurs, $erreurs, ['inputmode' => 'numeric', 'maxlength' => '6'], 'Enregistré comme une entrée « Mise en bouteille » dans le journal.') ?>
        <?php endif; ?>
    </div>
    <div class="barre-boutons">
        <button class="bouton" type="submit"><?= $edition ? 'Enregistrer les modifications' : 'Ajouter la cuvée' ?></button>
        <a class="bouton bouton-secondaire" href="cuvees.php">Annuler</a>
    </div>
</form>
<?php
page_fin();
