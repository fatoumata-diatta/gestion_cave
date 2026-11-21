<?php
declare(strict_types=1);

/**
 * F6 : tableau de bord.
 * Chiffre d'affaires par cuvée, meilleurs clients, cuvées en stock bas.
 */
require __DIR__ . '/src/bootstrap.php';

$kpi       = dashboard_kpis();
$caCuvees  = dashboard_ca_par_cuvee();
$meilleurs = dashboard_meilleurs_clients(5);
$stockBas  = dashboard_stock_bas();
$mensuel   = dashboard_ca_mensuel();
$parType   = dashboard_par_type_client();
$integrite = dashboard_integrite();

$maxCa = 0.0;
foreach ($caCuvees as $ligne) {
    $maxCa = max($maxCa, (float) $ligne['chiffre_affaires']);
}

page_debut('Tableau de bord', 'index', 'Chiffre d\'affaires, meilleurs clients et cuvées à réapprovisionner.');

/** Nombre de cuvées affichées d'emblée dans le classement ; les autres sont dans un volet repliable. */
const CUVEES_AFFICHEES = 10;

/** Une ligne du classement « chiffre d'affaires par cuvée » : nom, barre proportionnelle, montant, bouteilles. */
$ligneClassement = static function (array $ligne) use ($maxCa, $kpi): string {
    $ca = (float) $ligne['chiffre_affaires'];
    $largeur = $maxCa > 0 ? $ca / $maxCa * 100 : 0;
    $part = $kpi['ca'] > 0 ? $ca / $kpi['ca'] * 100 : 0;
    $titre = $ligne['nom'] . ' ' . $ligne['millesime'];
    $partTexte = number_format($part, 1, ',', '') . ' %';

    return '<tr>'
        . '<td class="intitule">' . puce_couleur((string) $ligne['couleur'], false) . ' ' . e($titre) . '</td>'
        . '<td class="piste"><span class="barre-donnee' . ($ca <= 0 ? ' nulle' : '') . '" style="width:' . e(number_format($largeur, 2, '.', '')) . '%"'
        . ' tabindex="0" role="img"'
        . ' aria-label="' . e(sprintf('%s : %s, %s des ventes', $titre, money($ca), $partTexte)) . '"'
        . ' data-tip-valeur="' . e(money($ca)) . '"'
        . ' data-tip-libelle="' . e(sprintf('%s, %s, %s du chiffre d\'affaires', $titre, pluriel($ligne['bouteilles_vendues'], 'bouteille'), $partTexte)) . '"></span></td>'
        . '<td class="nombre">' . e(money($ca)) . '</td>'
        . '<td class="nombre discret">' . e(nombre($ligne['bouteilles_vendues'])) . '</td>'
        . '</tr>';
};

$enteteClassement = '<thead><tr>'
    . '<th scope="col">Cuvée</th>'
    . '<th scope="col"><span class="sr-only">Barre proportionnelle au chiffre d\'affaires</span></th>'
    . '<th scope="col" class="nombre">Montant</th>'
    . '<th scope="col" class="nombre">Bouteilles</th>'
    . '</tr></thead>';

$principales = array_slice($caCuvees, 0, CUVEES_AFFICHEES);
$autres      = array_slice($caCuvees, CUVEES_AFFICHEES);
?>
<section class="synthese" aria-label="Indicateurs clés">
    <div class="indic-principal">
        <p class="indic-libelle">Chiffre d'affaires des commandes validées</p>
        <p class="indic-hero"><?= e(money($kpi['ca'])) ?></p>
        <p class="indic-detail">
            <?= e(pluriel($kpi['nb_commandes'], 'commande validée', 'commandes validées')) ?>.
            <?= e(pluriel($kpi['nb_annulees'], 'commande annulée', 'commandes annulées')) ?>, non comptée<?= accord($kpi['nb_annulees']) ?>.
        </p>
    </div>
    <div>
        <p class="indic-libelle">Panier moyen</p>
        <p class="indic-valeur"><?= e(money($kpi['panier_moyen'])) ?></p>
        <p class="indic-detail">par commande validée</p>
    </div>
    <div>
        <p class="indic-libelle">Bouteilles vendues</p>
        <p class="indic-valeur"><?= e(nombre($kpi['bouteilles'])) ?></p>
        <p class="indic-detail">hors commandes annulées</p>
    </div>
    <div>
        <p class="indic-libelle">Valeur du stock</p>
        <p class="indic-valeur"><?= e(money($kpi['stock_valeur'])) ?></p>
        <p class="indic-detail"><?= e(nombre($kpi['stock_bouteilles'])) ?> bouteilles, au prix de vente actuel</p>
    </div>
    <div>
        <p class="indic-libelle">Cuvées à réapprovisionner</p>
        <p class="indic-valeur"><?= e(nombre($kpi['nb_alertes'])) ?></p>
        <p class="indic-detail">
            <?= $kpi['nb_ruptures'] > 0 ? 'dont ' . e(nombre($kpi['nb_ruptures'])) . ' en rupture.' : 'Aucune rupture.' ?>
            <a href="stock.php?alertes=1">Voir la liste</a>
        </p>
    </div>
</section>

<div class="tableau-bord">
    <div class="colonne">
        <section class="bloc" aria-labelledby="titre-ca-cuvee">
            <div class="bloc-entete">
                <h2 id="titre-ca-cuvee">Chiffre d'affaires par cuvée</h2>
                <p class="bloc-note">Commandes validées, de la plus vendue à la moins vendue</p>
            </div>
            <table class="classement">
                <caption class="sr-only">Chiffre d'affaires et bouteilles vendues, cuvées les plus vendues</caption>
                <?= $enteteClassement ?>
                <tbody>
                <?php foreach ($principales as $ligne): ?>
                    <?= $ligneClassement($ligne) . "\n" ?>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ($autres): ?>
                <details class="vue-tableau">
                    <summary>Afficher les <?= e(nombre(count($autres))) ?> autres cuvées</summary>
                    <table class="classement">
                        <caption class="sr-only">Chiffre d'affaires et bouteilles vendues, autres cuvées</caption>
                        <?= $enteteClassement ?>
                        <tbody>
                        <?php foreach ($autres as $ligne): ?>
                            <?= $ligneClassement($ligne) . "\n" ?>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </details>
            <?php endif; ?>
        </section>

        <section class="bloc" aria-labelledby="titre-ca-mois">
            <div class="bloc-entete">
                <h2 id="titre-ca-mois">Chiffre d'affaires par mois</h2>
                <p class="bloc-note">Commandes validées, jusqu'à 12 mois d'activité</p>
            </div>
            <?= histogramme_mensuel($mensuel) ?>
            <details class="vue-tableau">
                <summary>Afficher les valeurs en tableau</summary>
                <table class="tableau">
                    <thead><tr><th scope="col">Mois</th><th scope="col" class="nombre">Commandes</th><th scope="col" class="nombre">Chiffre d'affaires</th></tr></thead>
                    <tbody>
                    <?php foreach ($mensuel as $m): ?>
                        <tr>
                            <td><?= e(mois_long($m['mois'])) ?></td>
                            <td class="nombre"><?= e(nombre($m['nb_commandes'])) ?></td>
                            <td class="nombre"><?= e(money($m['chiffre_affaires'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </details>
        </section>
    </div>

    <div class="colonne">
        <section class="bloc" aria-labelledby="titre-stock-bas">
            <div class="bloc-entete">
                <h2 id="titre-stock-bas">Cuvées à réapprovisionner</h2>
                <p class="bloc-note">Stock à ou sous le seuil d'alerte</p>
            </div>
            <?php if (!$stockBas): ?>
                <p class="vide">Tous les stocks sont au-dessus de leur seuil d'alerte.</p>
            <?php else: ?>
                <table class="tableau tableau-compact">
                    <caption class="sr-only">Cuvées dont le stock est à ou sous le seuil d'alerte</caption>
                    <thead>
                        <tr>
                            <th scope="col">Cuvée</th>
                            <th scope="col" class="nombre">Stock</th>
                            <th scope="col" class="nombre">Seuil</th>
                            <th scope="col">État</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($stockBas as $ligne): ?>
                        <tr>
                            <td class="nom"><?= e($ligne['nom'] . ' ' . $ligne['millesime']) ?></td>
                            <td class="nombre"><?= e(nombre($ligne['quantite'])) ?></td>
                            <td class="nombre discret"><?= e(nombre($ligne['seuil_alerte'])) ?></td>
                            <td><?= badge_stock((string) $ligne['niveau']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="legende-tableau"><a href="stock.php?alertes=1">Voir le stock et saisir une entrée</a></p>
            <?php endif; ?>
        </section>

        <section class="bloc" aria-labelledby="titre-clients">
            <div class="bloc-entete">
                <h2 id="titre-clients">Meilleurs clients</h2>
                <p class="bloc-note">Chiffre d'affaires, commandes validées</p>
            </div>
            <?php if (!$meilleurs): ?>
                <p class="vide">Aucune commande validée pour le moment.</p>
            <?php else: ?>
                <table class="tableau tableau-compact">
                    <caption class="sr-only">Les cinq clients au plus fort chiffre d'affaires</caption>
                    <thead>
                        <tr>
                            <th scope="col">Client</th>
                            <th scope="col" class="nombre">Chiffre d'affaires</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($meilleurs as $client): ?>
                        <tr>
                            <td>
                                <span class="nom"><?= e($client['nom']) ?></span>
                                <span class="note-prix"><?= e(TYPES_CLIENT[$client['type_client']] ?? $client['type_client']) ?> à <?= e($client['ville']) ?>, <?= e(pluriel($client['nb_commandes'], 'commande')) ?></span>
                            </td>
                            <td class="nombre"><?= e(money($client['chiffre_affaires'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

        <section class="bloc" aria-labelledby="titre-types">
            <div class="bloc-entete">
                <h2 id="titre-types">Particuliers et professionnels</h2>
                <p class="bloc-note">Commandes validées</p>
            </div>
            <table class="tableau tableau-compact">
                <caption class="sr-only">Commandes validées par type de client</caption>
                <thead>
                    <tr>
                        <th scope="col">Type de client</th>
                        <th scope="col" class="nombre">Commandes</th>
                        <th scope="col" class="nombre">Panier moyen</th>
                        <th scope="col" class="nombre">Chiffre d'affaires</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($parType as $ligne): ?>
                    <tr>
                        <td class="nom"><?= e(TYPES_CLIENT[$ligne['type_client']] ?? $ligne['type_client']) ?></td>
                        <td class="nombre"><?= e(nombre($ligne['nb_commandes'])) ?></td>
                        <td class="nombre"><?= e(money($ligne['panier_moyen'])) ?></td>
                        <td class="nombre"><?= e(money($ligne['chiffre_affaires'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    </div>
</div>

<p class="integrite<?= $integrite['nb_ecarts'] > 0 ? ' integrite-ko' : '' ?>">
    <?= icone('bouclier', 20) ?>
    <?php if ($integrite['nb_ecarts'] === 0): ?>
        <span>Contrôle d'intégrité : pour chacune des <?= e(nombre($integrite['nb_cuvees'])) ?> cuvées, le stock est égal à la somme des mouvements (écart nul).</span>
    <?php else: ?>
        <span>Écart détecté : <?= e(pluriel($integrite['nb_ecarts'], 'cuvée')) ?> avec un stock différent de la somme des mouvements. <a href="mouvements.php">Consulter le journal</a>.</span>
    <?php endif; ?>
</p>
<?php
page_fin();
