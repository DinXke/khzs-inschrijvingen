"""Maakt de app-iconen van KHZS Leden: het HZS-logo met daaronder ‘Leden’ in dezelfde stijl
(zwaar schuin lettertype, lichtblauw boven en donkerblauw onder een witte golf).

    python3 tools/logo/maak_iconen.py

Schrijft naar static/img/ (Django) en wordpress/khzs-leden/assets/img/ (plugin).
Bron: tools/logo/hzs-bron.png (het oorspronkelijke HZS-icoon). Lettertype: Rubik Black Italic (SIL OFL, zie Rubik-OFL.txt).
"""
import math
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

HIER = Path(__file__).resolve().parent
ROOT = HIER.parent.parent
DOELEN = [ROOT / "static" / "img", ROOT / "wordpress" / "khzs-leden" / "assets" / "img"]
LICHT = (0, 159, 224)    # #009FE0
DONKER = (7, 77, 162)    # #074DA2
WIT = (255, 255, 255)


def golf(x_rel: float) -> float:
    """Hoogte (0 = boven, 1 = onder) van de golf, gemeten op het HZS-logo."""
    return 0.68 - 0.14 * math.cos(2 * math.pi * (x_rel - 0.25))


def hzs() -> Image.Image:
    """Het HZS-logo uit de bron, bijgesneden, met transparante achtergrond (wit eruit gerekend)."""
    im = Image.open(HIER / "hzs-bron.png").convert("RGB")
    uit = Image.new("RGBA", im.size, (0, 0, 0, 0))
    src, dst = im.load(), uit.load()
    for y in range(im.height):
        for x in range(im.width):
            p = src[x, y]
            # Pixel = a * kleur + (1 - a) * wit → kies de dichtstbijzijnde logokleur en reken a uit.
            kleur = min((LICHT, DONKER), key=lambda k: sum((p[i] - k[i]) ** 2 for i in range(3)) / max(1, 255 - min(p)))
            a = sum((255 - p[i]) / (255 - kleur[i]) for i in range(3)) / 3
            a = max(0.0, min(1.0, a))
            if a > 0.02:
                dst[x, y] = (*kleur, round(a * 255))
    return uit.crop(uit.getbbox())


def leden(breedte: int, klein: bool = False) -> Image.Image:
    """‘Leden’ in Rubik Black Italic, licht boven en donker onder een witte golf, met transparante achtergrond.
    klein: voor kleine iconen iets meer lucht (geen extra rand, ruimere letters, bredere golf), zodat de e leesbaar blijft."""
    grootte = 600
    font = ImageFont.truetype(str(HIER / "Rubik-BlackItalic.ttf"), grootte)
    tekst, krap = "Leden", (-0.01 if klein else -0.045) * grootte  # letters dicht bij elkaar, zoals in het logo
    w = int(sum(font.getlength(c) for c in tekst) + krap * (len(tekst) - 1) + grootte)
    masker = Image.new("L", (w, int(grootte * 1.5)), 0)
    d = ImageDraw.Draw(masker)
    x = grootte * 0.2
    for c in tekst:
        # Een rand in dezelfde kleur maakt de letters even zwaar als die van HZS.
        d.text((x, grootte * 0.1), c, font=font, fill=255, stroke_width=0 if klein else round(0.008 * grootte), stroke_fill=255)
        x += font.getlength(c) + krap
    masker = masker.crop(masker.getbbox())
    mw, mh = masker.size
    kleur = Image.new("RGBA", masker.size)
    px = kleur.load()
    spleet = (0.075 if klein else 0.045) * mh
    for xx in range(mw):
        g = golf(xx / mw) * mh
        for yy in range(mh):
            if yy < g - spleet / 2:
                px[xx, yy] = (*LICHT, 255)
            elif yy > g + spleet / 2:
                px[xx, yy] = (*DONKER, 255)
            else:
                px[xx, yy] = (0, 0, 0, 0)  # witte golf
    alfa = Image.composite(masker, Image.new("L", masker.size, 0), kleur.getchannel("A"))
    kleur.putalpha(alfa)
    h = round(mh * breedte / mw)
    return kleur.resize((breedte, h), Image.LANCZOS)


def samen(breedte: int, klein: bool = False) -> Image.Image:
    """HZS boven, Leden eronder, even breed."""
    boven = hzs()
    boven = boven.resize((breedte, round(boven.height * breedte / boven.width)), Image.LANCZOS)
    onder = leden(round(breedte * 0.97), klein)
    tussen = round(breedte * 0.07)
    uit = Image.new("RGBA", (breedte, boven.height + tussen + onder.height), (0, 0, 0, 0))
    uit.alpha_composite(boven, (0, 0))
    uit.alpha_composite(onder, ((breedte - onder.width) // 2, boven.height + tussen))
    return uit


def icoon(grootte: int, vulling: float, klein: bool = False) -> Image.Image:
    """Vierkant icoon op wit; vulling = deel van de zijde (of van de veilige cirkel) dat het logo mag innemen."""
    logo = samen(1600, klein)
    # Past binnen een vierkant van vulling × grootte (beide zijden).
    schaal = vulling * grootte / max(logo.width, logo.height)
    logo = logo.resize((round(logo.width * schaal), round(logo.height * schaal)), Image.LANCZOS)
    uit = Image.new("RGBA", (grootte, grootte), (*WIT, 255))
    uit.alpha_composite(logo, ((grootte - logo.width) // 2, (grootte - logo.height) // 2))
    return uit.convert("RGB")


def main():
    bestanden = {
        "icon-512.png": icoon(512, 0.80),
        "icon-192.png": icoon(192, 0.82, klein=True),
        "apple-touch-icon.png": icoon(180, 0.80, klein=True),
        # Maskable: alles binnen de veilige cirkel (diameter 80 %): diagonaal van het logo ≤ 0.78 × zijde.
        "icon-maskable-512.png": icoon(512, 0.78 / math.sqrt(2) * 1.18),
    }
    groot = samen(800)
    for doel in DOELEN:
        doel.mkdir(parents=True, exist_ok=True)
        for naam, im in bestanden.items():
            im.save(doel / naam, optimize=True)
        groot.save(doel / "logo-leden.png", optimize=True)
        print("geschreven:", doel)


if __name__ == "__main__":
    main()
