<?php
declare(strict_types=1);

/** Lit une valeur de configuration avec une notation pointée : config('db.host'). */
function config(string $cle, mixed $defaut = null): mixed
{
    $valeur = $GLOBALS['config'];
    foreach (explode('.', $cle) as $morceau) {
        if (!is_array($valeur) || !array_key_exists($morceau, $valeur)) {
            return $defaut;
        }
        $valeur = $valeur[$morceau];
    }
    return $valeur;
}

/** Échappe une valeur avant de l'afficher en HTML (protection contre les failles XSS). */
function e(mixed $valeur): string
{
    return htmlspecialchars((string) $valeur, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Construit une adresse relative : url('cuvees.php', ['q' => 'rouge']) => cuvees.php?q=rouge */
function url(string $page, array $params = []): string
{
    $params = array_filter($params, static fn($v) => $v !== null && $v !== '');
    return $page . ($params ? '?' . http_build_query($params) : '');
}

function redirect(string $page, array $params = []): void
{
    header('Location: ' . url($page, $params));
    exit;
}

/** Montant en euros au format français : 1 234,50 € */
function money(mixed $valeur): string
{
    return number_format((float) $valeur, 2, ',', "\u{00A0}") . "\u{00A0}€";
}

/** Entier avec séparateur de milliers : 1 234 */
function nombre(mixed $valeur): string
{
    return number_format((int) $valeur, 0, ',', "\u{00A0}");
}

/** Quantité avec son unité accordée : pluriel(1, 'bouteille') => « 1 bouteille », pluriel(12, 'bouteille') => « 12 bouteilles ». */
function pluriel(mixed $quantite, string $singulier, ?string $pluriel = null): string
{
    $n = (int) $quantite;
    return nombre($n) . "\u{00A0}" . ($n > 1 ? ($pluriel ?? $singulier . 's') : $singulier);
}

/** Suffixe d'accord d'un adjectif ou d'un participe : 's' à partir de 2, rien sinon. */
function accord(mixed $quantite): string
{
    return (int) $quantite > 1 ? 's' : '';
}

/** Date SQL (2026-11-14 10:30:00) => 14/11/2026 ou 14/11/2026 10:30 */
function date_fr(?string $valeur, bool $avecHeure = false): string
{
    if ($valeur === null || $valeur === '') {
        return '';
    }
    $date = DateTime::createFromFormat('Y-m-d H:i:s', $valeur) ?: DateTime::createFromFormat('Y-m-d', $valeur);
    if ($date === false) {
        return $valeur;
    }
    return $date->format($avecHeure ? 'd/m/Y H:i' : 'd/m/Y');
}

/** Valeur d'un champ de formulaire texte (POST), nettoyée des espaces superflus. */
function champ_post(string $nom, string $defaut = ''): string
{
    $valeur = $_POST[$nom] ?? $defaut;
    return is_string($valeur) ? trim($valeur) : $defaut;
}

/** Valeur d'un paramètre d'URL (GET) texte, nettoyée. */
function champ_get(string $nom, string $defaut = ''): string
{
    $valeur = $_GET[$nom] ?? $defaut;
    return is_string($valeur) ? trim($valeur) : $defaut;
}

/** Entier lu dans l'URL (0 si absent ou invalide). */
function entier_get(string $nom): int
{
    $valeur = $_GET[$nom] ?? '';
    return is_string($valeur) && ctype_digit($valeur) ? (int) $valeur : 0;
}

/** Convertit « 12,5 » ou « 12.50 » en nombre ; null si ce n'est pas un nombre. */
function lire_decimal(string $texte): ?float
{
    $texte = str_replace([' ', "\u{00A0}"], '', trim($texte));
    $texte = str_replace(',', '.', $texte);
    return preg_match('/^\d+(\.\d+)?$/', $texte) === 1 ? (float) $texte : null;
}

/** Convertit un texte en entier positif ou nul ; null si ce n'est pas un entier. */
function lire_entier(string $texte): ?int
{
    $texte = str_replace([' ', "\u{00A0}"], '', trim($texte));
    return preg_match('/^\d{1,9}$/', $texte) === 1 ? (int) $texte : null;
}

/** Valide une date au format AAAA-MM-JJ (celui des champs <input type="date">) ; null si invalide. */
function lire_date(string $texte): ?string
{
    $date = DateTime::createFromFormat('!Y-m-d', trim($texte));
    return ($date !== false && $date->format('Y-m-d') === trim($texte)) ? $date->format('Y-m-d') : null;
}

/** Prépare un texte pour un LIKE : les caractères % et _ saisis par l'utilisateur perdent leur sens spécial. */
function echapper_like(string $texte): string
{
    return addcslashes($texte, '\\%_');
}

/** Formate un nombre pour une colonne DECIMAL (point comme séparateur). */
function decimal_sql(float $valeur, int $decimales = 2): string
{
    return number_format($valeur, $decimales, '.', '');
}

/**
 * Exécute $action dans une transaction : validation (COMMIT) si tout va bien,
 * annulation complète (ROLLBACK) à la moindre exception.
 *
 * Si deux utilisateurs se bloquent mutuellement (interblocage MySQL, code 1213) ou si
 * un verrou dure trop longtemps (code 1205), la transaction est rejouée jusqu'à 3 fois.
 */
function en_transaction(PDO $pdo, callable $action, int $tentatives = 3): mixed
{
    // Appelée À L'INTÉRIEUR d'une transaction déjà ouverte (cas de l'import CSV, qui enregistre
    // toutes les lignes valides ensemble) : un point de sauvegarde (SAVEPOINT) permet d'annuler
    // seulement cette opération si elle échoue, sans perdre le reste de la transaction englobante.
    if ($pdo->inTransaction()) {
        static $niveau = 0;
        $point = 'sp_' . (++$niveau);
        $pdo->exec('SAVEPOINT ' . $point);
        try {
            $resultat = $action($pdo);
            $pdo->exec('RELEASE SAVEPOINT ' . $point);
            return $resultat;
        } catch (Throwable $erreur) {
            try {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . $point);
            } catch (Throwable) {
                // Transaction déjà annulée par le serveur (interblocage) : l'erreur d'origine prime.
            }
            throw $erreur;
        } finally {
            $niveau--;
        }
    }

    for ($essai = 1; ; $essai++) {
        $pdo->beginTransaction();
        try {
            $resultat = $action($pdo);
            $pdo->commit();
            return $resultat;
        } catch (Throwable $erreur) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $interblocage = $erreur instanceof PDOException
                && in_array(code_erreur_sql($erreur), [1213, 1205], true);
            if (!$interblocage || $essai >= $tentatives) {
                throw $erreur;
            }
            usleep(random_int(50, 150) * 1000);
        }
    }
}

/** Code d'erreur MySQL/MariaDB d'une exception PDO (1062 = doublon, 1451/1452 = clé étrangère...). */
function code_erreur_sql(PDOException $erreur): int
{
    return (int) ($erreur->errorInfo[1] ?? 0);
}

/** Vrai si l'erreur PDO est une violation de contrainte d'unicité (doublon). */
function est_doublon(PDOException $erreur): bool
{
    return code_erreur_sql($erreur) === 1062;
}

/** Vrai si l'erreur PDO est une violation de clé étrangère (la ligne est utilisée ailleurs). */
function est_cle_etrangere(PDOException $erreur): bool
{
    return in_array(code_erreur_sql($erreur), [1451, 1452], true);
}

/** Nettoie un texte saisi : octets invalides remplacés, caractères de contrôle retirés, espaces réduits. */
function texte_propre(string $texte): string
{
    $texte = mb_scrub($texte);
    $texte = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $texte) ?? '';
    return trim(preg_replace('/\s+/u', ' ', $texte) ?? '');
}

function est_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

const COULEURS = [
    'rouge'        => 'Rouge',
    'blanc'        => 'Blanc',
    'rose'         => 'Rosé',
    'effervescent' => 'Effervescent',
];

const TYPES_CLIENT = [
    'particulier' => 'Particulier',
    'pro'         => 'Professionnel',
];

const STATUTS_COMMANDE = [
    'validee' => 'Validée',
    'annulee' => 'Annulée',
];

function libelle_couleur(string $code): string
{
    return COULEURS[$code] ?? $code;
}
