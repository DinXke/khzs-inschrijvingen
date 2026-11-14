"""App-iconen voor de demo (KHZS Leden Demo): de gewone iconen met een oranje lint 'DEMO'.

Gebruik: python3 tools/logo/maak_demo_iconen.py  (na maak_iconen.py; vereist Pillow)
Schrijft naar wordpress/khzs-leden/assets/img/demo/. De demo gebruikt die automatisch (asset() in de demo).
"""
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

HIER = Path(__file__).resolve().parent
IMG = HIER.parent.parent / "wordpress" / "khzs-leden" / "assets" / "img"
UIT = IMG / "demo"
LETTER = HIER / "Rubik-BlackItalic.ttf"
ORANJE = (242, 140, 40, 255)
WIT = (255, 255, 255, 255)


def lint(im):
    """Diagonaal lint rechtsboven met DEMO."""
    w = im.width
    laag = Image.new("RGBA", (w * 2, w * 2), (0, 0, 0, 0))
    d = ImageDraw.Draw(laag)
    dik = round(w * 0.13)
    d.rectangle([0, w - dik // 2, w * 2, w + dik // 2], fill=ORANJE)
    f = ImageFont.truetype(str(LETTER), round(dik * 0.72))
    d.text((w, w), "DEMO", font=f, fill=WIT, anchor="mm")
    laag = laag.rotate(-45, resample=Image.BICUBIC)
    # Middelpunt van het lint op ± 80% / 20% van het icoon (weg van de letters).
    m = round(w * 0.80)
    im.alpha_composite(laag.crop((w - m, w - (w - m), w - m + w, w - (w - m) + w)))
    return im


def pil(im):
    """Afgeronde badge onderaan, binnen de veilige zone van een maskable icoon."""
    w = im.width
    d = ImageDraw.Draw(im)
    h = round(w * 0.11)
    b = round(w * 0.38)
    y = round(w * 0.815)
    d.rounded_rectangle([(w - b) // 2, y, (w + b) // 2, y + h], radius=h // 2, fill=ORANJE)
    d.text((w // 2, y + h // 2), "DEMO", font=ImageFont.truetype(str(LETTER), round(h * 0.72)), fill=WIT, anchor="mm")
    return im


def main():
    UIT.mkdir(exist_ok=True)
    for naam in ("icon-192.png", "icon-512.png", "apple-touch-icon.png", "favicon-32.png"):
        im = Image.open(IMG / naam).convert("RGBA")
        if im.width < 64:
            # Favicon: te klein voor tekst, enkel een oranje hoek.
            ImageDraw.Draw(im).polygon([(im.width * 0.55, 0), (im.width, 0), (im.width, im.width * 0.45)], fill=ORANJE)
        else:
            lint(im)
        im.save(UIT / naam, optimize=True)
    im = Image.open(IMG / "icon-maskable-512.png").convert("RGBA")
    pil(im).save(UIT / "icon-maskable-512.png", optimize=True)
    print("Klaar:", UIT)


if __name__ == "__main__":
    main()
