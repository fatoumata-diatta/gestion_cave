<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

// La déconnexion modifie la session : elle exige un POST avec jeton CSRF (jamais un simple lien).
if (est_post()) {
    csrf_verifier();
    deconnecter();
    redirect('login.php');
}
redirect('index.php');
