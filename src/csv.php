<?php
declare(strict_types=1);

/**
 * Outils CSV partagés par l'import (F7) et l'export (F9).
 *
 * Format écrit : « Excel français » (séparateur point-virgule, virgule décimale, UTF-8 avec BOM,
 * fins de ligne CRLF), qui s'ouvre correctement d'un double-clic dans Excel.
 * Format lu : plus tolérant. Le séparateur (point-virgule, virgule ou tabulation) et l'encodage
 * (UTF-8, Windows-1252 ou UTF-16, ce que produit Excel) sont détectés automatiquement.
 */

/**
 * Convertit le contenu brut d'un fichier en UTF-8 sans BOM et dit quel encodage a été reconnu.
 *
 * @return array{0: string, 1: string} [texte en UTF-8, nom de l'encodage reconnu]
 * @throws RegleGestionException si le fichier contient des données binaires (ce n'est pas du texte)
 */
function csv_decoder(string $brut): array
{
    if (str_starts_with($brut, "\xFF\xFE") || str_starts_with($brut, "\xFE\xFF")) {
        $source = $brut[0] === "\xFF" ? 'UTF-16LE' : 'UTF-16BE';
        $texte = mb_convert_encoding(substr($brut, 2), 'UTF-8', $source);
        $encodage = 'UTF-16';
    } else {
        // Un vrai fichier texte (UTF-8 ou Windows-1252) ne contient jamais d'octet nul ;
        // un classeur .xlsx, un PDF ou une image, si.
        if (str_contains($brut, "\0")) {
            throw new RegleGestionException([
                'Ce fichier n\'est pas un fichier texte : il contient des données binaires. '
                . 'Dans Excel, utilisez « Enregistrer sous » au format « CSV (séparateur : point-virgule) » ou « CSV UTF-8 ».',
            ]);
        }
        if (str_starts_with($brut, "\xEF\xBB\xBF")) {
            $texte = mb_scrub(substr($brut, 3));
            $encodage = 'UTF-8';
        } elseif (mb_check_encoding($brut, 'UTF-8')) {
            $texte = $brut;
            $encodage = 'UTF-8';
        } else {
            $texte = mb_convert_encoding($brut, 'UTF-8', 'Windows-1252');
            $encodage = 'Windows-1252';
        }
    }

    if (str_starts_with($texte, "\u{FEFF}")) {
        $texte = substr($texte, 3);
    }
    return [str_replace(["\r\n", "\r"], "\n", $texte), $encodage];
}

/** Devine le séparateur d'après la première ligne : celui des trois (; tabulation ,) qui apparaît le plus souvent hors guillemets. */
function csv_separateur(string $texte): string
{
    $premiere = '';
    foreach (explode("\n", $texte) as $ligne) {
        if (trim($ligne) !== '') {
            $premiere = $ligne;
            break;
        }
    }

    $comptes = [';' => 0, "\t" => 0, ',' => 0];     // l'ordre départage les égalités
    $entreGuillemets = false;
    for ($i = 0, $n = strlen($premiere); $i < $n; $i++) {
        $c = $premiere[$i];
        if ($c === '"') {
            $entreGuillemets = !$entreGuillemets;
        } elseif (!$entreGuillemets && isset($comptes[$c])) {
            $comptes[$c]++;
        }
    }
    arsort($comptes);
    $meilleur = (string) array_key_first($comptes);
    return $comptes[$meilleur] > 0 ? $meilleur : ';';
}

/**
 * Découpe un texte CSV en enregistrements. Les lignes vides sont ignorées, mais le numéro
 * d'enregistrement les compte : il correspond au numéro de ligne affiché par Excel
 * (la ligne d'en-tête porte le numéro 1).
 *
 * @return list<array{ligne: int, champs: list<string>}>
 */
function csv_lire(string $texte, string $separateur = ';'): array
{
    $flux = fopen('php://temp', 'r+');
    if ($flux === false) {
        throw new RuntimeException('Impossible de créer un flux temporaire.');
    }
    fwrite($flux, $texte);
    rewind($flux);

    $lignes = [];
    $numero = 0;
    // Le dernier argument (caractère d'échappement) est vide : seuls les guillemets doublés ("") protègent un guillemet.
    while (($champs = fgetcsv($flux, 0, $separateur, '"', '')) !== false) {
        $numero++;
        $champs = array_map(static fn($c): string => (string) $c, $champs);
        if (trim(implode('', $champs)) === '') {
            continue;                               // ligne vide, ou faite uniquement de séparateurs
        }
        $lignes[] = ['ligne' => $numero, 'champs' => $champs];
    }
    fclose($flux);
    return $lignes;
}

/** Une ligne de fichier CSV (séparateur « ; », fin de ligne CRLF), avec les guillemets nécessaires. */
function csv_ligne(array $champs): string
{
    $sorties = [];
    foreach ($champs as $champ) {
        $champ = (string) $champ;
        $sorties[] = preg_match('/[;"\r\n]|^\s|\s$/', $champ) === 1
            ? '"' . str_replace('"', '""', $champ) . '"'
            : $champ;
    }
    return implode(';', $sorties) . "\r\n";
}

/**
 * Neutralise l'« injection de formule » : un tableur exécute une cellule qui commence par = + - ou @
 * (par exemple =HYPERLINK(...) saisi comme nom de client). On la préfixe d'une apostrophe,
 * qui la fait traiter comme du texte.
 */
function csv_texte_sur(string $texte): string
{
    $debut = ltrim($texte);
    if ($debut === '') {
        return $texte;
    }
    // Un nombre seul (« -5 », « +3,5 ») ne peut pas être pris pour une formule : on ne le modifie pas.
    if (preg_match('/^[+-]?\d+([.,]\d+)?$/', $debut) === 1) {
        return $texte;
    }
    if (strpbrk($debut[0], '=+-@') !== false || in_array($texte[0], ["\t", "\r"], true)) {
        return "'" . $texte;
    }
    return $texte;
}

/**
 * Met une valeur au format d'une cellule : « entier », « decimal » (2 chiffres, virgule),
 * « degre » (1 chiffre, virgule) ou « texte » (par défaut, neutralisé contre l'injection de formule).
 * Les nombres ne sont jamais préfixés : un nombre n'est pas une formule.
 */
function csv_formater(mixed $valeur, string $type = 'texte'): string
{
    if ($valeur === null) {
        return '';
    }
    return match ($type) {
        'entier'  => (string) (int) $valeur,
        'decimal' => number_format((float) $valeur, 2, ',', ''),
        'degre'   => number_format((float) $valeur, 1, ',', ''),
        default   => csv_texte_sur((string) $valeur),
    };
}

/**
 * Le contenu complet d'un fichier CSV : BOM UTF-8 (qui permet à Excel de reconnaître l'encodage),
 * ligne de titres, puis les lignes déjà formatées (voir csv_formater).
 *
 * @param list<string>       $entetes
 * @param list<list<string>> $lignes
 */
function csv_document(array $entetes, array $lignes): string
{
    $document = "\xEF\xBB\xBF" . csv_ligne($entetes);
    foreach ($lignes as $ligne) {
        $document .= csv_ligne($ligne);
    }
    return $document;
}

/** Envoie un document CSV au navigateur (téléchargement) puis termine le script. */
function csv_envoyer(string $nomFichier, string $document): void
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $nomFichier) . '"');
    header('Content-Length: ' . strlen($document));
    header('Cache-Control: no-store');
    echo $document;
    exit;
}
