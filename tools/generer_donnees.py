#!/usr/bin/env python3
"""
Génère le jeu de données de test de la base gestion_cave.

Produit :
  - database/03_donnees_test.sql   (INSERT uniquement)
  - database/install_complet.sql   (schéma + vues + données : à importer d'un seul coup)

Les données sont fictives et déterministes (graine fixe) : relancer le script
redonne exactement les mêmes fichiers. Elles sont construites pour être COHÉRENTES :
  - le stock de chaque cuvée est égal à la somme de ses mouvements (entrées - sorties) ;
  - aucune vente ne dépasse le stock disponible au moment où elle a lieu ;
  - le prix d'une ligne de commande est le prix en vigueur à la date de la commande
    (quelques cuvées ont augmenté en juin 2026, donc l'historique garde l'ancien prix).

Usage :  python3 tools/generer_donnees.py
"""
import datetime as dt
import pathlib
import random

SEED = 20261118
RACINE = pathlib.Path(__file__).resolve().parent.parent
BDD = RACINE / "database"

DATE_CHANGEMENT_PRIX = dt.datetime(2026, 6, 1)

# ---------------------------------------------------------------------------
#  Cuvées : stock_cible = stock restant aujourd'hui ; tirage2 = 2e mise en bouteille
#  poids = popularité (0 = jamais vendue) ; ancien = prix avant le 1er juin 2026
# ---------------------------------------------------------------------------
def cuvee(id, nom, millesime, couleur, degre, prix, seuil, cible, poids, bouteille,
          ancien=None, tirage2=None):
    return dict(id=id, nom=nom, millesime=millesime, couleur=couleur, degre=degre,
                prix=prix, ancien=ancien, seuil=seuil, cible=cible, poids=poids,
                bouteille=dt.datetime.fromisoformat(bouteille + " 09:30:00"),
                tirage2=(dt.datetime.fromisoformat(tirage2[0] + " 10:00:00"), tirage2[1]) if tirage2 else None)

CUVEES = [
    cuvee(1,  "Clos des Alouettes",    2019, "rouge", 13.5, 28.50, 24, 18,  4, "2025-09-15", ancien=26.00),
    cuvee(2,  "Les Hauts de Combe",    2020, "rouge", 14.0, 19.80, 36, 210, 6, "2025-09-15", tirage2=("2026-05-12", 120)),
    cuvee(3,  "Réserve du Pigeonnier", 2018, "rouge", 14.5, 34.00, 24, 96,  3, "2025-09-22", ancien=32.00),
    cuvee(4,  "Terre Rouge",           2021, "rouge", 13.0, 11.90, 48, 380, 9, "2025-10-06", ancien=10.90, tirage2=("2026-04-20", 240)),
    cuvee(5,  "Cuvée des Maîtres",     2019, "rouge", 14.0, 42.00, 12, 36,  2, "2025-09-22"),
    cuvee(6,  "Le Grand Chêne",        2020, "rouge", 13.5, 24.50, 24, 0,   5, "2025-10-06"),
    cuvee(7,  "Vieilles Vignes",       2018, "rouge", 14.0, 31.00, 24, 60,  3, "2025-09-29"),
    cuvee(8,  "Sentier des Loups",     2022, "rouge", 12.5, 9.90,  48, 520, 8, "2025-10-13", tirage2=("2026-06-15", 240)),
    cuvee(9,  "Pierre Dorée",          2022, "blanc", 12.5, 14.90, 36, 30,  6, "2025-10-13"),
    cuvee(10, "Les Calcaires",         2023, "blanc", 12.0, 11.50, 36, 340, 7, "2025-11-03", tirage2=("2026-05-05", 180)),
    cuvee(11, "Clos de la Source",     2022, "blanc", 13.0, 18.50, 24, 120, 4, "2025-10-20"),
    cuvee(12, "Fleur de Sable",        2023, "blanc", 11.5, 9.50,  48, 410, 7, "2025-11-03", ancien=8.90, tirage2=("2026-06-02", 240)),
    cuvee(13, "Cuvée des Oliviers",    2021, "blanc", 13.5, 22.00, 24, 70,  3, "2025-10-20"),
    cuvee(14, "Brume d'Automne",       2022, "blanc", 13.0, 26.50, 12, 24,  2, "2025-10-27"),
    cuvee(15, "Rosé des Garrigues",    2024, "rose",  12.5, 8.90,  48, 44,  7, "2025-11-10"),
    cuvee(16, "Aurore",                2025, "rose",  12.0, 9.50,  36, 260, 6, "2026-01-05", tirage2=("2026-04-14", 120)),
    cuvee(17, "Pétale",                2025, "rose",  12.5, 10.90, 24, 150, 4, "2026-01-05"),
    cuvee(18, "Brut Nature",           2021, "effervescent", 12.0, 17.50, 24, 84, 4, "2025-11-17"),
    cuvee(19, "Brut Rosé",             2022, "effervescent", 12.0, 18.90, 12, 72, 0, "2025-11-17"),
    cuvee(20, "Cuvée Étincelle",       2020, "effervescent", 12.5, 15.50, 24, 9,  3, "2025-11-24", ancien=14.50),
]

# (id, nom, type, email, ville)  -- adresses en example.com / example.org (domaines réservés, fictifs)
CLIENTS = [
    (1,  "Camille Durand",                "particulier", "camille.durand@example.com",     "Bordeaux"),
    (2,  "Julien Moreau",                 "particulier", "julien.moreau@example.com",      "Lyon"),
    (3,  "Sophie Lambert",                "particulier", "sophie.lambert@example.com",     "Nantes"),
    (4,  "Thomas Girard",                 "particulier", "thomas.girard@example.com",      "Toulouse"),
    (5,  "Élodie Fontaine",               "particulier", "elodie.fontaine@example.com",    "Lille"),
    (6,  "Nicolas Roche",                 "particulier", "nicolas.roche@example.com",      "Strasbourg"),
    (7,  "Marie Lefèvre",                 "particulier", "marie.lefevre@example.com",      "Rennes"),
    (8,  "Antoine Mercier",               "particulier", "antoine.mercier@example.com",    "Dijon"),
    (9,  "Isabelle Chevalier",            "particulier", "isabelle.chevalier@example.com", "Montpellier"),
    (10, "Restaurant Le Bouchon Gourmand", "pro",        "bouchon.gourmand@example.org",   "Lyon"),
    (11, "Cave des Halles",               "pro",         "cave.des.halles@example.org",    "Paris"),
    (12, "Hôtel du Parc",                 "pro",         "hotel.du.parc@example.org",      "Annecy"),
    (13, "Bistrot de la Place",           "pro",         "bistrot.place@example.org",      "Bordeaux"),
    (14, "Épicerie Fine Les Saveurs",     "pro",         "les.saveurs@example.org",        "Nice"),
    (15, "Brasserie Le Marais",           "pro",         "brasserie.marais@example.org",   "Paris"),
]

# Nombre de commandes par mois (janvier à novembre 2026) : total = 50
COMMANDES_PAR_MOIS = [3, 3, 4, 4, 5, 5, 6, 3, 4, 6, 7]
NB_ANNULATIONS = 3


def prix_a_la_date(c, date):
    """Prix de la cuvée à la date donnée (l'ancien prix avant le changement de juin 2026)."""
    if c["ancien"] is not None and date < DATE_CHANGEMENT_PRIX:
        return c["ancien"]
    return c["prix"]


def generer_dates(rng):
    """50 dates ouvrées réparties selon COMMANDES_PAR_MOIS, triées par ordre chronologique."""
    dates = []
    for i, nb in enumerate(COMMANDES_PAR_MOIS):
        mois = i + 1
        debut = dt.date(2026, mois, 12 if mois == 1 else 1)
        fin = dt.date(2026, mois + 1, 1) - dt.timedelta(days=1) if mois < 12 else dt.date(2026, 12, 31)
        if mois == 11:
            fin = dt.date(2026, 11, 14)
        jours = [debut + dt.timedelta(days=n) for n in range((fin - debut).days + 1)]
        jours = [j for j in jours if j.weekday() < 5]
        for j in sorted(rng.sample(jours, nb)):
            heure = dt.time(rng.randint(9, 17), rng.choice([0, 7, 12, 18, 24, 31, 38, 44, 51, 57]), rng.randint(0, 59))
            dates.append(dt.datetime.combine(j, heure))
    return sorted(dates)


def generer_commandes(rng):
    """Retourne la liste des commandes avec leurs lignes (sans tenir compte du stock)."""
    cuvees_vendables = [c for c in CUVEES if c["poids"] > 0]
    commandes = []
    for numero, date in enumerate(generer_dates(rng), start=1):
        client = rng.choices(CLIENTS, weights=[3.0 if c[2] == "pro" else 1.0 for c in CLIENTS])[0]
        est_pro = client[2] == "pro"
        nb_lignes = rng.randint(2, 5) if est_pro else rng.randint(1, 3)
        choisies, restantes = [], list(cuvees_vendables)
        for _ in range(nb_lignes):
            c = rng.choices(restantes, weights=[x["poids"] for x in restantes])[0]
            restantes.remove(c)
            choisies.append(c)
        lignes = []
        for c in sorted(choisies, key=lambda x: x["id"]):
            cher = c["prix"] >= 30
            if est_pro:
                qte = rng.choice([6, 12] if cher else [6, 12, 12, 24, 24, 36, 48])
            else:
                qte = rng.choice([1, 2, 3, 6] if cher else [1, 2, 3, 6, 6, 12])
            lignes.append(dict(cuvee=c["id"], quantite=qte, prix=prix_a_la_date(c, date)))
        commandes.append(dict(id=numero, client=client[0], date=date, lignes=lignes, annulee=False, date_annulation=None))
    return commandes


def construire_evenements(commandes):
    """Tous les mouvements de stock sauf les mises en bouteille : (date, ordre, cuvée, type, qté, motif, commande)."""
    ev = []
    for c in commandes:
        for l in c["lignes"]:
            ev.append((c["date"], 1, l["cuvee"], "sortie", l["quantite"], f"Vente commande n°{c['id']}", c["id"]))
            if c["annulee"]:
                ev.append((c["date_annulation"], 2, l["cuvee"], "entree", l["quantite"], f"Annulation commande n°{c['id']}", c["id"]))
    return ev


def ventes_nettes(commandes):
    net = {c["id"]: 0 for c in CUVEES}
    for c in commandes:
        if not c["annulee"]:
            for l in c["lignes"]:
                net[l["cuvee"]] += l["quantite"]
    return net


def mises_en_bouteille(commandes):
    """Calcule le tirage initial de chaque cuvée pour que le stock final soit exactement la cible."""
    net = ventes_nettes(commandes)
    entrees = []
    for c in CUVEES:
        tirage2 = c["tirage2"][1] if c["tirage2"] else 0
        initial = c["cible"] + net[c["id"]] - tirage2
        entrees.append((c["bouteille"], 0, c["id"], "entree", initial, "Mise en bouteille", None))
        if c["tirage2"]:
            entrees.append((c["tirage2"][0], 0, c["id"], "entree", tirage2, "Mise en bouteille (2e tirage)", None))
    return entrees


def stock_valide(commandes):
    """Vrai si le stock reste >= 0 à tout instant et finit exactement sur la cible."""
    entrees = mises_en_bouteille(commandes)
    if any(e[4] <= 0 for e in entrees):
        return False
    tous = sorted(entrees + construire_evenements(commandes), key=lambda e: (e[0], e[1]))
    solde = {c["id"]: 0 for c in CUVEES}
    for _, _, id_cuvee, type_mvt, qte, _, _ in tous:
        solde[id_cuvee] += qte if type_mvt == "entree" else -qte
        if solde[id_cuvee] < 0:
            return False
    return all(solde[c["id"]] == c["cible"] for c in CUVEES)


def choisir_annulations(rng, commandes):
    """Annule NB_ANNULATIONS commandes, en gardant seulement celles qui laissent le stock cohérent."""
    candidats = [c for c in commandes if c["date"] < dt.datetime(2026, 10, 15)]
    rng.shuffle(candidats)
    nb = 0
    for c in candidats:
        if nb == NB_ANNULATIONS:
            break
        c["annulee"] = True
        c["date_annulation"] = c["date"] + dt.timedelta(days=rng.randint(1, 3), hours=1)
        if stock_valide(commandes):
            nb += 1
        else:
            c["annulee"] = False
            c["date_annulation"] = None
    if nb != NB_ANNULATIONS:
        raise SystemExit("Impossible de placer toutes les annulations : changer la graine.")


# ---------------------------------------------------------------------------
#  Écriture SQL
# ---------------------------------------------------------------------------
class Brut(str):
    """Valeur SQL déjà formatée (nombre décimal à écrire tel quel)."""


def dec(valeur, decimales):
    return Brut(f"{valeur:.{decimales}f}")


def q(valeur):
    """Littéral SQL : texte entre apostrophes (doublées), nombre tel quel, NULL."""
    if valeur is None:
        return "NULL"
    if isinstance(valeur, Brut):
        return str(valeur)
    if isinstance(valeur, dt.datetime):
        return "'" + valeur.strftime("%Y-%m-%d %H:%M:%S") + "'"
    if isinstance(valeur, int):
        return str(valeur)
    return "'" + str(valeur).replace("\\", "\\\\").replace("'", "''") + "'"


def bloc_insert(table, colonnes, lignes):
    corps = ",\n".join("    (" + ", ".join(q(v) for v in ligne) + ")" for ligne in lignes)
    return f"INSERT INTO {table} ({', '.join(colonnes)}) VALUES\n{corps};\n"


def main():
    rng = random.Random(SEED)
    commandes = generer_commandes(rng)
    choisir_annulations(rng, commandes)
    assert stock_valide(commandes)

    mouvements = sorted(mises_en_bouteille(commandes) + construire_evenements(commandes), key=lambda e: (e[0], e[1], e[2]))
    solde = {c["id"]: 0 for c in CUVEES}
    for _, _, id_cuvee, type_mvt, qte, _, _ in mouvements:
        solde[id_cuvee] += qte if type_mvt == "entree" else -qte

    sql = []
    sql.append("""-- =====================================================================
--  GESTION DE CAVE : données de test (fictives)
--  Fichier 3/3 : 20 cuvées, 15 clients, 50 commandes, stock et mouvements
--  Généré par tools/generer_donnees.py (graine %d) : ne pas modifier à la main.
--  Les adresses e-mail utilisent example.com / example.org (domaines réservés).
-- =====================================================================

USE gestion_cave;
SET NAMES utf8mb4;
""" % SEED)

    sql.append("-- Cuvées\n" + bloc_insert(
        "cuvee", ["id", "nom", "millesime", "couleur", "degre", "prix_vente"],
        [(c["id"], c["nom"], c["millesime"], c["couleur"], dec(c["degre"], 1), dec(c["prix"], 2)) for c in CUVEES]))
    sql.append("-- Stock actuel et seuil d'alerte (égal à la somme des mouvements)\n" + bloc_insert(
        "stock", ["id", "id_cuvee", "quantite", "seuil_alerte"],
        [(c["id"], c["id"], solde[c["id"]], c["seuil"]) for c in CUVEES]))
    sql.append("-- Clients\n" + bloc_insert("client", ["id", "nom", "type_client", "email", "ville"], CLIENTS))
    sql.append("-- Commandes\n" + bloc_insert(
        "commande", ["id", "id_client", "date_commande", "statut"],
        [(c["id"], c["client"], c["date"], "annulee" if c["annulee"] else "validee") for c in commandes]))

    lignes, n = [], 0
    for c in commandes:
        for l in c["lignes"]:
            n += 1
            lignes.append((n, c["id"], l["cuvee"], l["quantite"], dec(l["prix"], 2)))
    sql.append("-- Lignes de commande (prix_unitaire = prix à la date de la commande)\n" + bloc_insert(
        "ligne_commande", ["id", "id_commande", "id_cuvee", "quantite", "prix_unitaire"], lignes))

    sql.append("-- Journal des mouvements de stock\n" + bloc_insert(
        "mouvement_stock", ["id", "id_cuvee", "id_commande", "date_mouvement", "quantite", "type_mouvement", "motif"],
        [(i, e[2], e[6], e[0], e[4], e[3], e[5]) for i, e in enumerate(mouvements, start=1)]))

    donnees = "\n".join(sql)
    (BDD / "03_donnees_test.sql").write_text(donnees, encoding="utf-8", newline="\n")

    entete = """-- =====================================================================
--  GESTION DE CAVE : INSTALLATION COMPLÈTE EN UN SEUL FICHIER
--  = 01_schema.sql + 02_vues.sql + 03_donnees_test.sql
--
--  phpMyAdmin > onglet « Importer » > choisir ce fichier > Exécuter.
--  ATTENTION : supprime et recrée toutes les tables (réinitialise la démo).
-- =====================================================================

"""
    morceaux = [(BDD / nom).read_text(encoding="utf-8") for nom in ("01_schema.sql", "02_vues.sql", "03_donnees_test.sql")]
    (BDD / "install_complet.sql").write_text(entete + "\n\n".join(morceaux), encoding="utf-8", newline="\n")

    # --- résumé pour contrôle ---
    valides = [c for c in commandes if not c["annulee"]]
    ca = sum(l["quantite"] * l["prix"] for c in valides for l in c["lignes"])
    par_client = {}
    for c in valides:
        par_client[c["client"]] = par_client.get(c["client"], 0) + sum(l["quantite"] * l["prix"] for l in c["lignes"])
    print(f"cuvées={len(CUVEES)} clients={len(CLIENTS)} commandes={len(commandes)} (annulées={len(commandes)-len(valides)})")
    print(f"lignes={len(lignes)} mouvements={len(mouvements)} CA validé={ca:.2f} €")
    print("clients avec commande :", len(par_client), "sur", len(CLIENTS))
    top = sorted(par_client.items(), key=lambda kv: -kv[1])[:5]
    print("top 5 clients :", [(CLIENTS[i-1][1], round(v, 2)) for i, v in top])
    bas = [(c["nom"], solde[c["id"]], c["seuil"]) for c in CUVEES if solde[c["id"]] <= c["seuil"]]
    print("stock bas / rupture :", bas)
    print("annulées :", [c["id"] for c in commandes if c["annulee"]])


if __name__ == "__main__":
    main()
