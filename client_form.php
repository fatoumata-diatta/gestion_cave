<?php
declare(strict_types=1);

/** F3 : ajout et modification d'un client. */
require __DIR__ . '/src/bootstrap.php';

$id = entier_get('id');
$client = null;
if ($id > 0) {
    $client = client_trouver($id);
    if ($client === null) {
        flash('erreur', 'Ce client n\'existe pas.');
        redirect('clients.php');
    }
}
$edition = $client !== null;

$valeurs = $edition
    ? [
        'nom'         => (string) $client['nom'],
        'type_client' => (string) $client['type_client'],
        'email'       => (string) $client['email'],
        'ville'       => (string) $client['ville'],
    ]
    : ['nom' => '', 'type_client' => 'particulier', 'email' => '', 'ville' => ''];

$erreurs = [];
$erreursGenerales = [];

if (est_post()) {
    csrf_verifier();
    foreach ($_POST as $cle => $valeur) {
        if (is_string($valeur) && array_key_exists($cle, $valeurs)) {
            $valeurs[$cle] = $valeur;
        }
    }
    [$donnees, $erreurs] = client_valider($_POST);

    if (!$erreurs) {
        try {
            if ($edition) {
                client_modifier($id, $donnees);
                flash('succes', 'Le client « ' . $donnees['nom'] . ' » a été modifié.');
            } else {
                client_creer($donnees);
                flash('succes', 'Le client « ' . $donnees['nom'] . ' » a été ajouté.');
            }
            redirect('clients.php');
        } catch (RegleGestionException $erreur) {
            $erreursGenerales = $erreur->erreurs();
        }
    }
}

page_debut($edition ? 'Modifier le client' : 'Nouveau client', 'clients', $edition ? $client['nom'] : 'Un client peut être un particulier ou un professionnel.');
?>
<?= bloc_erreurs(array_merge($erreursGenerales, array_values($erreurs))) ?>
<form class="formulaire" method="post" action="client_form.php<?= $edition ? '?id=' . $id : '' ?>" novalidate>
    <?= csrf_champ() ?>
    <div class="grille-formulaire">
        <div class="pleine-largeur">
            <?= champ_texte('nom', 'Nom ou raison sociale', $valeurs, $erreurs, ['required' => true, 'maxlength' => '120']) ?>
        </div>
        <?= champ_select('type_client', 'Type de client', TYPES_CLIENT, $valeurs, $erreurs, 'Choisir un type') ?>
        <?= champ_texte('ville', 'Ville', $valeurs, $erreurs, ['required' => true, 'maxlength' => '100']) ?>
        <div class="pleine-largeur">
            <?= champ_texte('email', 'Adresse e-mail', $valeurs, $erreurs, ['required' => true, 'type' => 'email', 'maxlength' => '190'], 'Chaque client a une adresse e-mail unique.') ?>
        </div>
    </div>
    <div class="barre-boutons">
        <button class="bouton" type="submit"><?= $edition ? 'Enregistrer les modifications' : 'Ajouter le client' ?></button>
        <a class="bouton bouton-secondaire" href="clients.php">Annuler</a>
    </div>
</form>
<?php
page_fin();
