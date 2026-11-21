<?php
declare(strict_types=1);

/**
 * F7 : import CSV de clients ou de cuvées, avec rapport des lignes refusées.
 *
 * Principe : chaque ligne est lue, nettoyée puis validée par les MÊMES fonctions que les
 * formulaires (client_valider, cuvee_valider) : les règles de gestion ne sont écrites qu'une fois.
 * Les lignes valides sont enregistrées ensemble, dans UNE transaction ; les lignes en erreur
 * ne sont pas enregistrées, mais listées avec leur motif pour être corrigées puis réimportées.
 */

/** Minuscules sans accents : pour comparer des libellés sans tenir compte de l'orthographe. */
function import_simplifier(string $texte): string
{
    return strtr(mb_strtolower(trim($texte)), [
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ÿ' => 'y', 'ç' => 'c', 'œ' => 'oe', 'æ' => 'ae',
    ]);
}

/** « Adresse e-mail » devient « adresse_e_mail » : forme comparable d'un titre de colonne. */
function import_cle_colonne(string $titre): string
{
    return trim(preg_replace('/[^a-z0-9]+/', '_', import_simplifier($titre)) ?? '', '_');
}

/**
 * Les deux imports possibles : colonnes attendues (avec les titres acceptés pour chacune)
 * et fonctions de validation et de création.
 */
function import_types(): array
{
    return [
        'clients' => [
            'libelle'  => 'Clients',
            'colonnes' => [
                'nom'         => ['titre' => 'Nom', 'obligatoire' => true,
                                  'aide' => 'Nom de la personne ou raison sociale',
                                  'alias' => ['nom', 'raison_sociale', 'nom_ou_raison_sociale', 'societe']],
                'type_client' => ['titre' => 'Type de client', 'obligatoire' => true,
                                  'aide' => 'particulier ou pro',
                                  'alias' => ['type_client', 'type', 'type_de_client']],
                'email'       => ['titre' => 'E-mail', 'obligatoire' => true,
                                  'aide' => 'Adresse e-mail, unique pour chaque client',
                                  'alias' => ['email', 'e_mail', 'mail', 'courriel', 'adresse_e_mail', 'adresse_email']],
                'ville'       => ['titre' => 'Ville', 'obligatoire' => true,
                                  'aide' => 'Ville du client',
                                  'alias' => ['ville', 'commune']],
            ],
        ],
        'cuvees' => [
            'libelle'  => 'Cuvées',
            'colonnes' => [
                'nom'           => ['titre' => 'Nom', 'obligatoire' => true,
                                    'aide' => 'Nom de la cuvée',
                                    'alias' => ['nom', 'cuvee', 'nom_de_la_cuvee', 'libelle']],
                'millesime'     => ['titre' => 'Millésime', 'obligatoire' => true,
                                    'aide' => 'Année de récolte, de 1900 à l\'année prochaine',
                                    'alias' => ['millesime', 'annee', 'annee_de_recolte']],
                'couleur'       => ['titre' => 'Couleur', 'obligatoire' => true,
                                    'aide' => 'rouge, blanc, rosé ou effervescent',
                                    'alias' => ['couleur']],
                'degre'         => ['titre' => 'Degré', 'obligatoire' => true,
                                    'aide' => 'Degré d\'alcool, de 0,1 à 25',
                                    'alias' => ['degre', 'degre_alcool', 'degre_d_alcool', 'alcool']],
                'prix_vente'    => ['titre' => 'Prix de vente', 'obligatoire' => true,
                                    'aide' => 'Prix de vente d\'une bouteille, en euros',
                                    'alias' => ['prix_vente', 'prix', 'prix_de_vente', 'prix_unitaire']],
                'seuil_alerte'  => ['titre' => 'Seuil d\'alerte', 'obligatoire' => false,
                                    'aide' => 'Alerte de stock bas ; 24 si la colonne est absente ou vide',
                                    'alias' => ['seuil_alerte', 'seuil', 'seuil_d_alerte']],
                'stock_initial' => ['titre' => 'Stock initial', 'obligatoire' => false,
                                    'aide' => 'Bouteilles en stock à l\'import ; 0 si la colonne est absente ou vide',
                                    'alias' => ['stock_initial', 'stock', 'quantite']],
            ],
        ],
    ];
}

/**
 * Associe les titres de la première ligne aux colonnes attendues.
 *
 * @param  list<string> $entete
 * @return array{0: array<int,string>, 1: list<string>} [indice de colonne => clé, titres ignorés]
 * @throws RegleGestionException si deux colonnes désignent la même donnée
 */
function import_associer_colonnes(array $entete, array $definition): array
{
    $alias = [];
    foreach ($definition['colonnes'] as $cle => $colonne) {
        foreach ($colonne['alias'] as $titre) {
            $alias[$titre] = $cle;
        }
    }

    $colonnes = [];
    $ignorees = [];
    $dejaVues = [];
    foreach ($entete as $indice => $titre) {
        $cle = import_cle_colonne($titre);
        if ($cle === '') {
            continue;                                 // colonne sans titre (séparateur en fin de ligne) : rien à signaler
        }
        if (!isset($alias[$cle])) {
            $ignorees[] = texte_propre($titre);
            continue;
        }
        if (isset($dejaVues[$alias[$cle]])) {
            throw new RegleGestionException(["La colonne « {$definition['colonnes'][$alias[$cle]]['titre']} » apparaît deux fois dans la première ligne du fichier."]);
        }
        $dejaVues[$alias[$cle]] = true;
        $colonnes[$indice] = $alias[$cle];
    }
    return [$colonnes, $ignorees];
}

/** Rend les valeurs courantes d'un tableur compatibles avec les validateurs (« Rosé », « 12,50 € », « 13 % »...). */
function import_normaliser(string $type, array $saisie): array
{
    if ($type === 'clients') {
        $saisie['type_client'] = match (import_simplifier($saisie['type_client'] ?? '')) {
            'particulier', 'particuliers'                                                      => 'particulier',
            'pro', 'pros', 'professionnel', 'professionnelle', 'professionnels', 'entreprise' => 'pro',
            default                                                                            => $saisie['type_client'] ?? '',
        };
        return $saisie;
    }

    $saisie['couleur'] = match (import_simplifier($saisie['couleur'] ?? '')) {
        'rouge'                                 => 'rouge',
        'blanc'                                 => 'blanc',
        'rose'                                  => 'rose',
        'effervescent', 'petillant', 'mousseux' => 'effervescent',
        default                                 => $saisie['couleur'] ?? '',
    };
    $saisie['prix_vente'] = preg_replace('/\s*(€|eur|euros?)\s*$/iu', '', trim($saisie['prix_vente'] ?? '')) ?? '';
    $saisie['degre'] = preg_replace('/\s*(%\s*vol\.?|%|°)\s*$/iu', '', trim($saisie['degre'] ?? '')) ?? '';
    return $saisie;
}

/** Transforme les erreurs des validateurs en phrases lisibles, qui citent la valeur lue dans le fichier. */
function import_messages(array $erreurs, array $saisie): array
{
    $messages = [];
    foreach ($erreurs as $champ => $message) {
        $valeur = mb_strimwidth(trim((string) ($saisie[$champ] ?? '')), 0, 40, '…');
        $messages[] = match ($champ) {
            'type_client' => $valeur === ''
                ? 'Le type de client est obligatoire (particulier ou pro).'
                : "Type de client inconnu « $valeur » : indiquez particulier ou pro.",
            'couleur' => $valeur === ''
                ? 'La couleur est obligatoire (rouge, blanc, rosé ou effervescent).'
                : "Couleur inconnue « $valeur » : indiquez rouge, blanc, rosé ou effervescent.",
            default => $valeur === '' ? $message : $message . " Valeur lue : « $valeur ».",
        };
    }
    return $messages;
}

/** Clé qui identifie un doublon dans le fichier (même e-mail, ou même cuvée et même millésime). */
function import_cle_doublon(string $type, array $donnees): string
{
    return $type === 'clients'
        ? mb_strtolower((string) $donnees['email'])
        : import_simplifier((string) $donnees['nom']) . '|' . $donnees['millesime'];
}

/** La ligne existe-t-elle déjà dans la base ? Retourne le message de refus, ou null. */
function import_existe_deja(string $type, array $donnees): ?string
{
    if ($type === 'clients') {
        $existe = (int) requete_valeur('SELECT COUNT(*) FROM client WHERE email = ?', [$donnees['email']]) > 0;
        return $existe ? 'Un client avec cette adresse e-mail existe déjà.' : null;
    }
    $existe = (int) requete_valeur(
        'SELECT COUNT(*) FROM cuvee WHERE nom = ? AND millesime = ?',
        [$donnees['nom'], $donnees['millesime']]
    ) > 0;
    return $existe ? 'Cette cuvée existe déjà pour ce millésime.' : null;
}

/**
 * Lit, valide et enregistre un fichier CSV.
 *
 * @param string $brut        contenu du fichier tel que reçu
 * @param string $type        « clients » ou « cuvees »
 * @param bool   $simulation  true : tout est vérifié, rien n'est enregistré
 * @return array{type: string, simulation: bool, encodage: string, separateur: string, entete: list<string>,
 *               colonnes_ignorees: list<string>, nb_lues: int, nb_ok: int,
 *               rejets: list<array{ligne: int, champs: list<string>, motifs: list<string>}>}
 * @throws RegleGestionException si le fichier lui-même est inutilisable (vide, binaire, colonnes manquantes, trop long)
 */
function import_executer(string $brut, string $type, bool $simulation = false): array
{
    $types = import_types();
    if (!isset($types[$type])) {
        throw new RegleGestionException(['Choisissez ce que vous voulez importer : des clients ou des cuvées.']);
    }
    $definition = $types[$type];

    [$texte, $encodage] = csv_decoder($brut);
    if (trim($texte) === '') {
        throw new RegleGestionException(['Le fichier est vide.']);
    }
    $separateur = csv_separateur($texte);
    $enregistrements = csv_lire($texte, $separateur);

    // --- 1. Première ligne : les titres de colonnes ---
    $enteteLigne = array_shift($enregistrements);
    $entete = array_map('strval', $enteteLigne['champs']);
    [$colonnes, $ignorees] = import_associer_colonnes($entete, $definition);

    $manquantes = [];
    foreach ($definition['colonnes'] as $cle => $colonne) {
        if ($colonne['obligatoire'] && !in_array($cle, $colonnes, true)) {
            $manquantes[] = $colonne['titre'];
        }
    }
    if ($manquantes) {
        throw new RegleGestionException([sprintf(
            'Colonne%s obligatoire%s absente%s de la première ligne du fichier : %s. Colonnes trouvées : %s.',
            count($manquantes) > 1 ? 's' : '',
            count($manquantes) > 1 ? 's' : '',
            count($manquantes) > 1 ? 's' : '',
            implode(', ', $manquantes),
            implode(', ', array_map('texte_propre', $entete))
        )]);
    }

    $maximum = (int) config('import.lignes_max', 5000);
    if (!$enregistrements) {
        throw new RegleGestionException(['Le fichier ne contient que la ligne de titres : il n\'y a rien à importer.']);
    }
    if (count($enregistrements) > $maximum) {
        throw new RegleGestionException([sprintf(
            'Le fichier contient %s lignes de données ; la limite est de %s. Découpez-le en plusieurs fichiers.',
            nombre(count($enregistrements)),
            nombre($maximum)
        )]);
    }

    // --- 2. Validation de chaque ligne ---
    $rejets = [];
    $valides = [];
    $dejaVus = [];
    foreach ($enregistrements as $enregistrement) {
        $champs = array_map('strval', $enregistrement['champs']);
        $motifs = [];

        // Plus de valeurs que de titres : le plus souvent un séparateur placé dans une valeur non protégée par des guillemets.
        $enTrop = array_slice($champs, count($entete));
        if (trim(implode('', $enTrop)) !== '') {
            $motifs[] = sprintf(
                'Cette ligne contient %d colonnes alors que la première ligne en annonce %d. '
                . 'Un séparateur se trouve peut-être dans une valeur : entourez-la de guillemets.',
                count($champs),
                count($entete)
            );
        }

        $saisie = [];
        foreach ($colonnes as $indice => $cle) {
            $saisie[$cle] = $champs[$indice] ?? '';
        }
        $saisie = import_normaliser($type, $saisie);
        $donnees = [];
        if (!$motifs) {
            [$donnees, $erreurs] = $type === 'clients' ? client_valider($saisie) : cuvee_valider($saisie);
            $motifs = import_messages($erreurs, $saisie);
        }

        $cleDoublon = '';
        if (!$motifs) {
            $cleDoublon = import_cle_doublon($type, $donnees);
            if (isset($dejaVus[$cleDoublon])) {
                $motifs[] = 'Doublon : la ligne ' . $dejaVus[$cleDoublon] . ' du fichier décrit déjà '
                    . ($type === 'clients' ? 'cette adresse e-mail.' : 'cette cuvée pour ce millésime.');
            } elseif (($existe = import_existe_deja($type, $donnees)) !== null) {
                $motifs[] = $existe;
            }
        }

        if ($motifs) {
            $rejets[] = ['ligne' => $enregistrement['ligne'], 'champs' => $champs, 'motifs' => $motifs];
            continue;
        }
        $dejaVus[$cleDoublon] = $enregistrement['ligne'];
        $valides[] = ['ligne' => $enregistrement['ligne'], 'champs' => $champs, 'donnees' => $donnees];
    }

    // --- 3. Enregistrement : toutes les lignes valides ensemble, ou aucune ---
    $nbOk = count($valides);
    if (!$simulation && $valides) {
        [$nbOk, $refusEcriture] = en_transaction(db(), static function () use ($type, $valides): array {
            $enregistrees = 0;
            $refus = [];
            foreach ($valides as $ligne) {
                try {
                    $type === 'clients' ? client_creer($ligne['donnees']) : cuvee_creer($ligne['donnees']);
                    $enregistrees++;
                } catch (RegleGestionException $erreur) {
                    // Doublon apparu entre la vérification et l'écriture (autre utilisateur, ou même nom à l'accent près).
                    $refus[] = ['ligne' => $ligne['ligne'], 'champs' => $ligne['champs'], 'motifs' => $erreur->erreurs()];
                }
            }
            return [$enregistrees, $refus];
        });
        $rejets = array_merge($rejets, $refusEcriture);
        usort($rejets, static fn(array $a, array $b): int => $a['ligne'] <=> $b['ligne']);
    }

    return [
        'type'              => $type,
        'simulation'        => $simulation,
        'encodage'          => $encodage,
        'separateur'        => $separateur,
        'entete'            => $entete,
        'colonnes_ignorees' => $ignorees,
        'nb_lues'           => count($enregistrements),
        'nb_ok'             => $nbOk,
        'rejets'            => $rejets,
    ];
}

/** Phrase de bilan : « Import terminé : 3 clients ajoutés, 2 lignes refusées. » */
function import_bilan(array $resultat): string
{
    $feminin = $resultat['type'] === 'cuvees';
    $nom = $feminin ? pluriel($resultat['nb_ok'], 'cuvée') : pluriel($resultat['nb_ok'], 'client');
    $nbRefus = count($resultat['rejets']);
    $refus = $nbRefus === 0 ? 'aucune ligne refusée' : pluriel($nbRefus, 'ligne') . ' refusée' . accord($nbRefus);

    if ($resultat['simulation']) {
        return "Simulation : $nom valide" . accord($resultat['nb_ok']) . ", $refus. Rien n'a été enregistré.";
    }
    return 'Import terminé : ' . $nom . ' ajouté' . ($feminin ? 'e' : '') . accord($resultat['nb_ok']) . ", $refus.";
}

/**
 * Le fichier des lignes refusées : mêmes colonnes que le fichier d'origine, plus une colonne « motif_du_rejet ».
 * Une fois les lignes corrigées, il peut être réimporté tel quel (la colonne en plus est ignorée).
 *
 * @return array{0: list<string>, 1: list<list<string>>} [titres, lignes déjà formatées]
 */
function import_rejets_pour_csv(array $resultat): array
{
    $largeur = count($resultat['entete']);
    $entetes = array_merge($resultat['entete'], ['motif_du_rejet']);
    $lignes = [];
    foreach ($resultat['rejets'] as $rejet) {
        $champs = array_slice(array_pad($rejet['champs'], $largeur, ''), 0, $largeur);
        $lignes[] = array_merge(
            array_map(static fn(string $c): string => csv_formater($c), $champs),
            [csv_formater(implode(' ', $rejet['motifs']))]
        );
    }
    return [array_map(static fn(string $t): string => csv_formater($t), $entetes), $lignes];
}

/**
 * Vérifie le fichier reçu par le formulaire d'import et en retourne le nom et le contenu.
 * Chaque refus est expliqué à l'utilisateur : fichier absent, trop gros, vide, mal reçu.
 *
 * @param mixed $fichier une entrée de $_FILES
 * @return array{0: string, 1: string} [nom d'origine nettoyé, contenu brut]
 * @throws RegleGestionException
 */
function import_televersement(mixed $fichier): array
{
    $maximum = (int) config('import.taille_max_octets', 2 * 1024 * 1024);
    $tropGros = 'Le fichier est trop volumineux : la limite est de '
        . rtrim(rtrim(number_format($maximum / 1048576, 1, ',', ''), '0'), ',') . ' Mo. Découpez-le en plusieurs fichiers.';

    if (!is_array($fichier) || !isset($fichier['error']) || is_array($fichier['error'])) {
        throw new RegleGestionException(['Choisissez le fichier CSV à importer.']);
    }
    switch ((int) $fichier['error']) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_NO_FILE:
            throw new RegleGestionException(['Choisissez le fichier CSV à importer.']);
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            throw new RegleGestionException([$tropGros]);
        default:
            throw new RegleGestionException(['Le fichier n\'a pas pu être reçu en entier (erreur ' . (int) $fichier['error'] . '). Réessayez.']);
    }

    $chemin = (string) ($fichier['tmp_name'] ?? '');
    if ($chemin === '' || !is_uploaded_file($chemin)) {
        throw new RegleGestionException(['Le fichier reçu n\'est pas valide. Réessayez.']);
    }
    if ((int) ($fichier['size'] ?? 0) > $maximum) {
        throw new RegleGestionException([$tropGros]);
    }
    $contenu = file_get_contents($chemin);
    if ($contenu === false) {
        throw new RegleGestionException(['Le fichier n\'a pas pu être lu. Réessayez.']);
    }
    if ($contenu === '') {
        throw new RegleGestionException(['Le fichier est vide.']);
    }

    $nom = mb_strimwidth(texte_propre(basename(str_replace('\\', '/', (string) ($fichier['name'] ?? '')))), 0, 80, '…');
    return [$nom !== '' ? $nom : 'fichier.csv', $contenu];
}

/** Nom du séparateur, pour le rapport d'import. */
function import_libelle_separateur(string $separateur): string
{
    return match ($separateur) {
        "\t"    => 'séparateur tabulation',
        ','     => 'séparateur virgule',
        default => 'séparateur point-virgule',
    };
}
