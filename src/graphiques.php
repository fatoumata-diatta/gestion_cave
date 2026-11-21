<?php
declare(strict_types=1);

/**
 * Graphiques du tableau de bord, dessinés en HTML/SVG pur (aucune bibliothèque JavaScript).
 * Chaque graphique a son équivalent en tableau, pour rester lisible sans souris ni couleurs.
 */

const MOIS_COURTS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
const MOIS_LONGS  = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

/** « 2026-03 » => « mars 2026 » */
function mois_long(string $anneeMois): string
{
    [$annee, $mois] = array_map('intval', explode('-', $anneeMois) + [0, 1]);
    return (MOIS_LONGS[$mois - 1] ?? '?') . ' ' . $annee;
}

/** Montant arrondi à l'euro, sans décimales : « 6 322 € ». */
function euros_entiers(float $valeur): string
{
    return number_format($valeur, 0, ',', "\u{00A0}") . "\u{00A0}€";
}

/** Choisit un sommet d'axe et un pas « ronds » (2 000, 4 000, 6 000, 8 000...) : 5 intervalles au plus. */
function echelle_axe(float $maximum): array
{
    foreach ([100, 200, 250, 500, 1000, 2000, 2500, 5000, 10000, 20000, 25000, 50000, 100000, 200000] as $pas) {
        if ($maximum / $pas <= 5) {
            return [max($pas, ceil($maximum / $pas) * $pas), (float) $pas];
        }
    }
    return [ceil($maximum / 1000000) * 1000000, 1000000.0];
}

/** Trace d'une colonne : coins supérieurs arrondis (4 px), pied carré posé sur la ligne de base. */
function trace_colonne(float $x, float $y, float $largeur, float $hauteur): string
{
    $r = min(4.0, $hauteur, $largeur / 2);
    return sprintf(
        'M%.2f,%.2f L%.2f,%.2f Q%.2f,%.2f %.2f,%.2f L%.2f,%.2f Q%.2f,%.2f %.2f,%.2f L%.2f,%.2f Z',
        $x, $y + $hauteur,
        $x, $y + $r,
        $x, $y, $x + $r, $y,
        $x + $largeur - $r, $y,
        $x + $largeur, $y, $x + $largeur, $y + $r,
        $x + $largeur, $y + $hauteur
    );
}

/**
 * Histogramme du chiffre d'affaires par mois.
 *
 * @param array<int,array{mois:string,nb_commandes:int|string,chiffre_affaires:float|string}> $donnees
 */
function histogramme_mensuel(array $donnees): string
{
    if (!$donnees) {
        return '';
    }

    $largeurTotale = 640;
    $hauteurTotale = 280;
    $marge = ['gauche' => 56, 'droite' => 8, 'haut' => 24, 'bas' => 30];
    $zoneL = $largeurTotale - $marge['gauche'] - $marge['droite'];
    $zoneH = $hauteurTotale - $marge['haut'] - $marge['bas'];

    $valeurs = array_map(static fn(array $m): float => (float) $m['chiffre_affaires'], $donnees);
    $maximum = max($valeurs);
    [$sommet, $pas] = echelle_axe($maximum);
    $nb = count($donnees);
    $pasX = $zoneL / $nb;
    $largeurColonne = min(24.0, $pasX * 0.5);
    $indiceMax = (int) array_search($maximum, $valeurs, true);
    $indiceDernier = $nb - 1;

    $svg = '<svg class="histogramme" viewBox="0 0 ' . $largeurTotale . ' ' . $hauteurTotale . '" role="group" '
        . 'aria-label="Chiffre d\'affaires par mois, en euros">';

    // Lignes horizontales fines et graduations de l'axe vertical
    for ($v = 0.0; $v <= $sommet + 0.001; $v += $pas) {
        $y = $marge['haut'] + $zoneH * (1 - $v / $sommet);
        $svg .= sprintf(
            '<line class="%s" x1="%d" x2="%d" y1="%.1f" y2="%.1f"/>',
            $v == 0.0 ? 'base' : 'grille',
            $marge['gauche'],
            $largeurTotale - $marge['droite'],
            $y,
            $y
        );
        $svg .= sprintf(
            '<text class="graduation" x="%d" y="%.1f" text-anchor="end" dominant-baseline="middle">%s</text>',
            $marge['gauche'] - 8,
            $y,
            e(nombre($v))
        );
    }

    foreach ($donnees as $i => $m) {
        $valeur = $valeurs[$i];
        $centre = $marge['gauche'] + $pasX * ($i + 0.5);
        $hauteur = $zoneH * $valeur / $sommet;
        $y = $marge['haut'] + $zoneH - $hauteur;
        $nbCommandes = (int) $m['nb_commandes'];
        $texte = sprintf('%s : %s, %s', mois_long($m['mois']), money($valeur), pluriel($nbCommandes, 'commande'));

        $svg .= sprintf(
            '<g class="groupe-colonne" tabindex="0" role="img" aria-label="%s" data-tip-valeur="%s" data-tip-libelle="%s">',
            e($texte),
            e(money($valeur)),
            e(sprintf('%s, %s', mois_long($m['mois']), pluriel($nbCommandes, 'commande')))
        );
        $svg .= sprintf(
            '<rect class="zone-survol" x="%.2f" y="%d" width="%.2f" height="%d"/>',
            $centre - $pasX / 2,
            $marge['haut'],
            $pasX,
            $zoneH
        );
        $svg .= sprintf('<path class="colonne-donnee" d="%s"/>', trace_colonne($centre - $largeurColonne / 2, $y, $largeurColonne, $hauteur));
        // Valeur écrite seulement sur la colonne la plus haute et sur la dernière : les autres sont dans l'info-bulle et le tableau.
        if ($i === $indiceMax || $i === $indiceDernier) {
            $svg .= sprintf(
                '<text class="valeur-etiquette" x="%.2f" y="%.2f" text-anchor="middle">%s</text>',
                $centre,
                $y - 6,
                e(euros_entiers($valeur))
            );
        }
        $svg .= '</g>';

        [$annee, $numeroMois] = array_map('intval', explode('-', $m['mois']) + [0, 1]);
        $svg .= sprintf(
            '<text class="graduation" x="%.2f" y="%d" text-anchor="middle">%s</text>',
            $centre,
            $hauteurTotale - 10,
            e(MOIS_COURTS[$numeroMois - 1] ?? '?')
        );
    }

    return $svg . '</svg>';
}
