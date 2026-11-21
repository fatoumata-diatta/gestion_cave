#!/usr/bin/env python3
"""
Génère le schéma MCD (modèle conceptuel de données, notation Merise) : docs/img/mcd.svg

Aucune dépendance : le script écrit directement du SVG.
    python3 tools/generer_mcd.py
Pour obtenir aussi une image PNG (si rsvg-convert est installé) :
    rsvg-convert -w 2000 docs/img/mcd.svg -o docs/img/mcd.png

Lecture d'une cardinalité (min,max) placée près d'une entité :
  « (0,N) du côté CLIENT de PASSER » = un client passe de 0 à N commandes.
  « (1,1) du côté COMMANDE de PASSER » = une commande est passée par un et un seul client.
"""
from pathlib import Path

VERT, VERT_CLAIR = "#12332d", "#e4ece8"
LIE, LIE_PALE = "#7a1f3d", "#f6e9ee"
ENCRE, TRAIT = "#17221f", "#46544f"
POLICE = "Inter, 'DejaVu Sans', Arial, sans-serif"

elements = []


def ajouter(svg: str) -> None:
    elements.append(svg)


def texte(x, y, contenu, taille=14, poids=400, couleur=ENCRE, ancre="start", souligne=False, italique=False):
    deco = ' text-decoration="underline"' if souligne else ""
    style = ' font-style="italic"' if italique else ""
    ajouter(f'<text x="{x}" y="{y}" font-size="{taille}" font-weight="{poids}" fill="{couleur}" '
            f'text-anchor="{ancre}"{deco}{style}>{contenu}</text>')


def entite(x, y, largeur, nom, attributs, identifiant=0):
    """Rectangle à deux étages : le nom, puis les attributs (l'identifiant est souligné)."""
    haut_titre, haut_ligne = 34, 24
    hauteur = haut_titre + 12 + haut_ligne * len(attributs)
    ajouter(f'<rect x="{x}" y="{y}" width="{largeur}" height="{hauteur}" rx="6" fill="#fff" stroke="{VERT}" stroke-width="2"/>')
    ajouter(f'<path d="M{x},{y + haut_titre} V{y + 6} Q{x},{y} {x + 6},{y} H{x + largeur - 6} Q{x + largeur},{y} {x + largeur},{y + 6} V{y + haut_titre} Z" fill="{VERT}"/>')
    texte(x + largeur / 2, y + 23, nom, 15, 600, "#fff", "middle")
    for i, attribut in enumerate(attributs):
        texte(x + 16, y + haut_titre + 12 + haut_ligne * i + 16, attribut, 14, 400, ENCRE, souligne=(i == identifiant))
    return hauteur


def association(cx, cy, largeur, nom, attributs=()):
    """Rectangle arrondi (bordeaux) : le nom de l'association, puis ses attributs éventuels."""
    hauteur = 40 + 22 * len(attributs)
    x, y = cx - largeur / 2, cy - 20
    ajouter(f'<rect x="{x}" y="{y}" width="{largeur}" height="{hauteur}" rx="{20 if not attributs else 14}" fill="{LIE_PALE}" stroke="{LIE}" stroke-width="2"/>')
    texte(cx, cy + 5, nom, 14, 600, LIE, "middle")
    if attributs:
        ajouter(f'<line x1="{x + 10}" x2="{x + largeur - 10}" y1="{cy + 16}" y2="{cy + 16}" stroke="{LIE}" stroke-width="1"/>')
        for i, attribut in enumerate(attributs):
            texte(cx, cy + 36 + 22 * i, attribut, 13, 400, ENCRE, "middle")
    return hauteur


def lien(points, etiquette, position, ancre="middle"):
    """Trait (éventuellement coudé) avec sa cardinalité."""
    chemin = " ".join(f"{px},{py}" for px, py in points)
    ajouter(f'<polyline points="{chemin}" fill="none" stroke="{TRAIT}" stroke-width="1.6"/>')
    ex, ey = position
    ajouter(f'<rect x="{ex - 21}" y="{ey - 13}" width="42" height="20" rx="4" fill="#fff"/>')
    texte(ex, ey + 2, etiquette, 13, 600, ENCRE, ancre)


# ------------------------------------------------------------------ mise en page
LARGEUR, HAUTEUR = 1240, 860
ajouter(f'<rect width="{LARGEUR}" height="{HAUTEUR}" fill="#f7f9f6"/>')
texte(30, 42, "Gestion de cave : modèle conceptuel de données (MCD)", 22, 600, VERT)
texte(30, 66, "Notation Merise. L'identifiant de chaque entité est souligné. Une cardinalité (min,max) se lit du côté de l'entité à laquelle elle est collée.", 13, 400, TRAIT)

# Ligne 1 : CLIENT - PASSER - COMMANDE - CONTENIR - CUVEE
entite(30, 110, 220, "CLIENT", ["id", "nom", "type_client", "email", "ville"])
association(345, 190, 110, "PASSER")
entite(440, 110, 220, "COMMANDE", ["id", "date_commande", "statut"])
association(795, 190, 150, "CONTENIR", ["quantite", "prix_unitaire"])
entite(930, 110, 220, "CUVEE", ["id", "nom", "millesime", "couleur", "degre", "prix_vente"])

lien([(250, 190), (290, 190)], "0,N", (270, 178))
lien([(400, 190), (440, 190)], "1,1", (420, 178))
lien([(660, 190), (720, 190)], "1,N", (690, 178))
lien([(870, 190), (930, 190)], "0,N", (900, 178))

# CUVEE - POSSEDER - STOCK
association(1040, 400, 120, "POSSEDER")
entite(930, 500, 220, "STOCK", ["id", "quantite", "seuil_alerte"])
lien([(1040, 300), (1040, 380)], "1,1", (1064, 326))
lien([(1040, 420), (1040, 500)], "1,1", (1064, 476))

# COMMANDE - GENERER - MOUVEMENT_STOCK ; CUVEE - CONCERNER - MOUVEMENT_STOCK
entite(440, 540, 240, "MOUVEMENT_STOCK", ["id", "date_mouvement", "quantite", "type_mouvement", "motif"])
association(550, 400, 120, "GENERER")
lien([(550, 228), (550, 380)], "1,N", (574, 254))
lien([(550, 420), (550, 540)], "0,1", (574, 516))

association(805, 620, 130, "CONCERNER")
lien([(960, 300), (960, 345), (805, 345), (805, 600)], "0,N", (986, 326))
lien([(740, 620), (680, 620)], "1,1", (704, 608))

# ------------------------------------------------------------------ lecture des cardinalités
y0 = 742
texte(30, y0, "Comment lire le schéma", 14, 600, VERT)
colonne_1 = [
    "PASSER : un client passe 0 à N commandes ; une commande est passée par 1 seul client.",
    "CONTENIR : une commande a 1 à N lignes (jamais vide) ; une cuvée est dans 0 à N commandes.",
    "CONTENIR porte les attributs quantite et prix_unitaire : elle devient la table LIGNE_COMMANDE.",
    "POSSEDER : une cuvée a un seul stock ; un stock concerne une seule cuvée (relation 1-1).",
]
colonne_2 = [
    "CONCERNER : un mouvement concerne 1 seule cuvée ; une cuvée a 0 à N mouvements.",
    "GENERER : une commande génère 1 à N mouvements (sorties, ou entrées si annulée) ;",
    "un mouvement vient de 0 ou 1 commande (0 pour une mise en bouteille).",
]
for i, phrase in enumerate(colonne_1):
    texte(30, y0 + 26 + 20 * i, phrase, 12, 400, ENCRE)
for i, phrase in enumerate(colonne_2):
    texte(630, y0 + 26 + 20 * i, phrase, 12, 400, ENCRE)

svg = (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {LARGEUR} {HAUTEUR}" width="{LARGEUR}" height="{HAUTEUR}" '
       f'font-family="{POLICE}" role="img" aria-label="Modèle conceptuel de données de la gestion de cave">\n'
       + "\n".join(elements) + "\n</svg>\n")

sortie = Path(__file__).resolve().parent.parent / "docs" / "img" / "mcd.svg"
sortie.parent.mkdir(parents=True, exist_ok=True)
sortie.write_text(svg, encoding="utf-8")
print(f"Écrit : {sortie}")
