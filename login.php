<?php
declare(strict_types=1);

const PAGE_PUBLIQUE = true;
require __DIR__ . '/src/bootstrap.php';

if (!auth_active() || est_connecte()) {
    redirect('index.php');
}

$erreur = '';
if (est_post()) {
    csrf_verifier();
    $motDePasse = is_string($_POST['mot_de_passe'] ?? null) ? $_POST['mot_de_passe'] : '';
    if (tenter_connexion(champ_post('utilisateur'), $motDePasse)) {
        redirect('index.php');
    }
    $erreur = 'Identifiant ou mot de passe incorrect.';
}
$nomApp = (string) config('app_name');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Connexion – <?= e($nomApp) ?></title>
    <?= lien_favicon() ?>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="page-connexion">
<section class="connexion-marque">
    <hr class="filet">
    <h1><?= e($nomApp) ?></h1>
    <p>Stock, clients et commandes du domaine, au même endroit.</p>
</section>
<main class="connexion-formulaire">
    <form method="post" action="login.php">
        <h2>Connexion</h2>
        <?php if ($erreur !== ''): ?>
            <div class="alerte alerte-erreur" role="alert"><?= icone('alerte') ?><p><?= e($erreur) ?></p></div>
        <?php endif; ?>
        <?= csrf_champ() ?>
        <?= champ_texte('utilisateur', 'Identifiant', ['utilisateur' => champ_post('utilisateur')], [], ['required' => true, 'autofocus' => true, 'autocomplete' => 'username']) ?>
        <?= champ_texte('mot_de_passe', 'Mot de passe', [], [], ['type' => 'password', 'required' => true, 'autocomplete' => 'current-password']) ?>
        <button class="bouton" type="submit">Se connecter</button>
        <?php if ((string) config('auth.indice') !== ''): ?>
            <p class="indice-connexion"><?= e(config('auth.indice')) ?></p>
        <?php endif; ?>
    </form>
</main>
</body>
</html>
