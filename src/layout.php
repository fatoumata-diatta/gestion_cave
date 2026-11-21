<?php
declare(strict_types=1);

/**
 * Mise en page commune à toutes les pages : barre latérale, titre, messages, pied de page,
 * plus quelques petits composants HTML réutilisés (icônes, badges, champs de formulaire).
 */

/** Icône SVG en ligne (trait fin, couleur héritée du texte). */
function icone(string $nom, int $taille = 18): string
{
    static $formes = [
        'tableau'    => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'cuvee'      => '<path d="M10 2h4v4l1.5 2.5V21a1 1 0 0 1-1 1h-5a1 1 0 0 1-1-1V8.5L10 6z"/><path d="M8.5 14h7"/>',
        'stock'      => '<path d="M21 8l-9-5-9 5v8l9 5 9-5z"/><path d="M3.3 7.5L12 12.5l8.7-5"/><path d="M12 22V12.5"/>',
        'mouvements' => '<path d="M7 4v14"/><path d="M3 14l4 4 4-4"/><path d="M17 20V6"/><path d="M13 10l4-4 4 4"/>',
        'clients'    => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.9"/><path d="M16 3.1a4 4 0 0 1 0 7.8"/>',
        'commandes'  => '<path d="M6 2v20l3-2 3 2 3-2 3 2V2z"/><path d="M9.5 8h5"/><path d="M9.5 12h5"/>',
        'plus'       => '<path d="M12 5v14"/><path d="M5 12h14"/>',
        'import'     => '<path d="M12 3v12"/><path d="M7 8l5-5 5 5"/><path d="M4 21h16"/>',
        'export'     => '<path d="M12 15V3"/><path d="M7 10l5 5 5-5"/><path d="M4 21h16"/>',
        'alerte'     => '<path d="M10.3 3.9L1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
        'rupture'    => '<circle cx="12" cy="12" r="10"/><path d="M15 9l-6 6"/><path d="M9 9l6 6"/>',
        'ok'         => '<path d="M20 6L9 17l-5-5"/>',
        'sortie'     => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/>',
        'recherche'  => '<circle cx="11" cy="11" r="8"/><path d="M21 21l-4.3-4.3"/>',
        'modifier'   => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
        'corbeille'  => '<path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/>',
        'bouclier'   => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M9 12l2 2 4-4"/>',
        'annuler'    => '<path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/>',
    ];
    return '<svg class="icone" width="' . $taille . '" height="' . $taille . '" viewBox="0 0 24 24" fill="none" '
        . 'stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" '
        . 'aria-hidden="true" focusable="false">' . ($formes[$nom] ?? '') . '</svg>';
}

/** Entrées du menu, regroupées par domaine : [identifiant de page, adresse, libellé, icône]. */
function menu_principal(): array
{
    return [
        'Pilotage' => [
            ['index', 'index.php', 'Tableau de bord', 'tableau'],
        ],
        'Ventes' => [
            ['commandes', 'commandes.php', 'Commandes', 'commandes'],
            ['commande_nouvelle', 'commande_nouvelle.php', 'Nouvelle commande', 'plus'],
        ],
        'Stock' => [
            ['stock', 'stock.php', 'Stock', 'stock'],
            ['mouvements', 'mouvements.php', 'Mouvements', 'mouvements'],
        ],
        'Référentiels' => [
            ['cuvees', 'cuvees.php', 'Cuvées', 'cuvee'],
            ['clients', 'clients.php', 'Clients', 'clients'],
        ],
        'Échanges de données' => [
            ['import', 'import.php', 'Import CSV', 'import'],
            ['export', 'export.php', 'Export CSV', 'export'],
        ],
    ];
}

/** Icône de l'onglet (petite bouteille), intégrée à la page : aucune requête supplémentaire, donc jamais de 404 sur /favicon.ico. */
function lien_favicon(): string
{
    return <<<'HTML'
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='7' fill='%2312332D'/%3E%3Cpath d='M13 5h6v6l2 3.5V26a1.5 1.5 0 0 1-1.5 1.5h-7A1.5 1.5 0 0 1 11 26V14.5L13 11z' fill='%23F1F3F0'/%3E%3Crect x='11' y='18' width='10' height='5' fill='%237A1F3D'/%3E%3C/svg%3E">
HTML;
}

/**
 * Début de page : <head>, barre latérale, titre. À fermer avec page_fin().
 *
 * @param string $titre       titre de la page (balise <title> et <h1>)
 * @param string $actif       identifiant de l'entrée de menu à surligner
 * @param string $description phrase d'aide sous le titre
 * @param string $actions     HTML des boutons d'action à droite du titre
 */
function page_debut(string $titre, string $actif = '', string $description = '', string $actions = ''): void
{
    $nomApp = (string) config('app_name', 'Cave');
    ?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($titre) ?> – <?= e($nomApp) ?></title>
    <?= lien_favicon() ?>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="app">
<a class="lien-evitement" href="#contenu">Aller au contenu</a>
<div class="structure">
    <aside class="barre-laterale">
        <a class="marque" href="index.php">
            <span class="marque-nom"><?= e($nomApp) ?></span>
            <span class="marque-sous">Stock, clients et ventes du domaine</span>
        </a>
        <nav class="menu" aria-label="Navigation principale">
            <?php foreach (menu_principal() as $groupe => $entrees): ?>
                <?php
                // On n'affiche que les pages qui existent réellement dans le dossier.
                $entrees = array_filter($entrees, static fn(array $entree): bool => is_file(APP_ROOT . '/' . $entree[1]));
                if (!$entrees) {
                    continue;
                }
                ?>
                <div class="menu-groupe">
                    <p class="menu-titre"><?= e($groupe) ?></p>
                    <ul>
                        <?php foreach ($entrees as [$id, $adresse, $libelle, $icone]): ?>
                            <li>
                                <a href="<?= e($adresse) ?>"<?= $id === $actif ? ' aria-current="page"' : '' ?>>
                                    <?= icone($icone) ?><span><?= e($libelle) ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endforeach; ?>
        </nav>
        <?php if (auth_active()): ?>
            <div class="utilisateur">
                <span class="utilisateur-nom"><?= e(utilisateur_courant()) ?></span>
                <form method="post" action="logout.php">
                    <?= csrf_champ() ?>
                    <button class="lien-bouton" type="submit"><?= icone('sortie', 16) ?> Se déconnecter</button>
                </form>
            </div>
        <?php endif; ?>
    </aside>

    <main id="contenu" class="contenu">
        <header class="entete-page">
            <div>
                <h1><?= e($titre) ?></h1>
                <?php if ($description !== ''): ?><p class="description"><?= e($description) ?></p><?php endif; ?>
            </div>
            <?php if ($actions !== ''): ?><div class="entete-actions"><?= $actions ?></div><?php endif; ?>
        </header>
        <?= messages_flash_html() ?>
<?php
}

/** Fin de page : ferme le contenu et charge les scripts (app.js toujours, les autres à la demande). */
function page_fin(array $scripts = []): void
{
    ?>
    </main>
</div>
<script src="assets/js/app.js" defer></script>
<?php foreach ($scripts as $script): ?>
<script src="assets/js/<?= e($script) ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
<?php
}

/** Page d'erreur autonome (sans menu ni base de données) : erreur technique, session expirée... */
function page_erreur_simple(string $titre, string $message, string $lienTexte = '', string $lienAdresse = ''): void
{
    ?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($titre) ?></title>
    <?= lien_favicon() ?>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="page-simple">
<main class="boite-simple">
    <h1><?= e($titre) ?></h1>
    <p><?= e($message) ?></p>
    <?php if ($lienTexte !== ''): ?><p><a class="bouton" href="<?= e($lienAdresse) ?>"><?= e($lienTexte) ?></a></p><?php endif; ?>
    <p><a href="index.php">Retour à l'accueil</a></p>
</main>
</body>
</html>
<?php
}

// ---------------------------------------------------------------------------
//  Messages flash : un message affiché une seule fois après une action (PRG)
// ---------------------------------------------------------------------------

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function messages_flash_html(): string
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    $html = '';
    foreach ($messages as $m) {
        $erreur = $m['type'] === 'erreur';
        $html .= '<div class="alerte ' . ($erreur ? 'alerte-erreur' : 'alerte-succes') . '" role="' . ($erreur ? 'alert' : 'status') . '">'
            . icone($erreur ? 'alerte' : 'ok', 18) . '<p>' . e($m['message']) . '</p></div>';
    }
    return $html;
}

/** Bloc d'erreurs de validation affiché au-dessus d'un formulaire. */
function bloc_erreurs(array $messages, string $titre = 'Le formulaire contient des erreurs'): string
{
    if (!$messages) {
        return '';
    }
    $liste = '';
    foreach ($messages as $message) {
        $liste .= '<li>' . e($message) . '</li>';
    }
    return '<div class="alerte alerte-erreur" role="alert">' . icone('alerte', 18)
        . '<div><p class="alerte-titre">' . e($titre) . '</p><ul>' . $liste . '</ul></div></div>';
}

// ---------------------------------------------------------------------------
//  Composants de formulaire
// ---------------------------------------------------------------------------

/** Transforme ['type' => 'text', 'required' => true] en attributs HTML échappés. */
function attributs_html(array $attributs): string
{
    $html = '';
    foreach ($attributs as $nom => $valeur) {
        if ($valeur === false || $valeur === null) {
            continue;
        }
        $html .= $valeur === true ? ' ' . e($nom) : ' ' . e($nom) . '="' . e($valeur) . '"';
    }
    return $html;
}

/**
 * Champ de saisie avec son libellé, son aide et son message d'erreur.
 *
 * @param array<string,string> $valeurs valeurs actuelles du formulaire
 * @param array<string,string> $erreurs erreurs par nom de champ
 */
function champ_texte(string $nom, string $libelle, array $valeurs, array $erreurs, array $attributs = [], string $aide = ''): string
{
    $id = 'f-' . $nom;
    $descriptions = [];
    if ($aide !== '') {
        $descriptions[] = 'aide-' . $nom;
    }
    if (isset($erreurs[$nom])) {
        $descriptions[] = 'erreur-' . $nom;
    }
    $attributs += ['type' => 'text', 'autocomplete' => 'off'];
    $attributs['id'] = $id;
    $attributs['name'] = $nom;
    $attributs['value'] = $valeurs[$nom] ?? '';
    if ($descriptions) {
        $attributs['aria-describedby'] = implode(' ', $descriptions);
    }
    if (isset($erreurs[$nom])) {
        $attributs['aria-invalid'] = 'true';
    }

    return '<div class="champ' . (isset($erreurs[$nom]) ? ' champ-invalide' : '') . '">'
        . '<label for="' . e($id) . '">' . e($libelle) . '</label>'
        . '<input' . attributs_html($attributs) . '>'
        . ($aide !== '' ? '<p class="aide" id="aide-' . e($nom) . '">' . e($aide) . '</p>' : '')
        . (isset($erreurs[$nom]) ? '<p class="erreur-champ" id="erreur-' . e($nom) . '">' . e($erreurs[$nom]) . '</p>' : '')
        . '</div>';
}

/**
 * Liste déroulante avec libellé.
 *
 * @param array<string,string> $options valeur => libellé
 */
function champ_select(string $nom, string $libelle, array $options, array $valeurs, array $erreurs, string $invite = 'Choisir…', string $aide = ''): string
{
    $id = 'f-' . $nom;
    $actuelle = $valeurs[$nom] ?? '';
    $html = '<option value="">' . e($invite) . '</option>';
    foreach ($options as $valeur => $texte) {
        $html .= '<option value="' . e($valeur) . '"' . ((string) $valeur === (string) $actuelle ? ' selected' : '') . '>' . e($texte) . '</option>';
    }
    $descriptions = [];
    if ($aide !== '') {
        $descriptions[] = 'aide-' . $nom;
    }
    if (isset($erreurs[$nom])) {
        $descriptions[] = 'erreur-' . $nom;
    }

    return '<div class="champ' . (isset($erreurs[$nom]) ? ' champ-invalide' : '') . '">'
        . '<label for="' . e($id) . '">' . e($libelle) . '</label>'
        . '<select id="' . e($id) . '" name="' . e($nom) . '"'
        . ($descriptions ? ' aria-describedby="' . e(implode(' ', $descriptions)) . '"' : '')
        . (isset($erreurs[$nom]) ? ' aria-invalid="true"' : '') . '>' . $html . '</select>'
        . ($aide !== '' ? '<p class="aide" id="aide-' . e($nom) . '">' . e($aide) . '</p>' : '')
        . (isset($erreurs[$nom]) ? '<p class="erreur-champ" id="erreur-' . e($nom) . '">' . e($erreurs[$nom]) . '</p>' : '')
        . '</div>';
}

// ---------------------------------------------------------------------------
//  Petits composants d'affichage
// ---------------------------------------------------------------------------

/**
 * Pastille de couleur du vin. La couleur n'est jamais seule : le nom est affiché, ou à défaut
 * lu par les lecteurs d'écran (quand $afficherTexte vaut false, par exemple dans un classement).
 */
function puce_couleur(string $couleur, bool $afficherTexte = true): string
{
    return '<span class="puce-vin puce-' . e($couleur) . '"><span class="puce-point" aria-hidden="true"></span>'
        . '<span' . ($afficherTexte ? '' : ' class="sr-only"') . '>' . e(libelle_couleur($couleur)) . '</span></span>';
}

/** Badge de statut d'une commande. */
function badge_statut(string $statut): string
{
    return $statut === 'annulee'
        ? '<span class="badge badge-neutre">Annulée</span>'
        : '<span class="badge badge-ok">Validée</span>';
}

/** Badge de niveau de stock : icône + texte (jamais la couleur seule). Rien à afficher quand tout va bien. */
function badge_stock(string $niveau): string
{
    return match ($niveau) {
        'rupture' => '<span class="badge badge-critique">' . icone('rupture', 14) . 'Rupture</span>',
        'bas'     => '<span class="badge badge-alerte">' . icone('alerte', 14) . 'Stock bas</span>',
        default   => '',
    };
}

/**
 * Jauge de stock : la barre montre la quantité, le trait vertical montre le seuil d'alerte.
 * Une barre qui s'arrête avant le trait = stock à réapprovisionner.
 */
function jauge_stock(int $quantite, int $seuil, int $echelle): string
{
    $echelle = max($echelle, 1);
    $largeur = min(100.0, $quantite / $echelle * 100);
    $position = min(100.0, $seuil / $echelle * 100);
    $niveau = niveau_stock($quantite, $seuil);

    return sprintf(
        '<span class="jauge jauge-%s" role="img" aria-label="%s"><span class="jauge-barre" style="width:%s%%"></span>'
        . '<span class="jauge-seuil" style="left:%s%%"></span></span>',
        e($niveau),
        e(sprintf('Stock : %s, seuil d\'alerte : %d', pluriel($quantite, 'bouteille'), $seuil)),
        number_format($largeur, 2, '.', ''),
        number_format($position, 2, '.', '')
    );
}
