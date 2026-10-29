"""Bouwt de documentatie opnieuw: verse screenshots van de app + PDF's per rol.

Gebruik (vanuit de projectmap):  .venv/bin/python docs/build.py
Vereist: playwright + chromium (.venv/bin/python -m playwright install chromium) en markdown.
Draait de app tijdelijk met een lege demo-database; je eigen db.sqlite3 blijft onaangeroerd.
"""
import os
import re
import subprocess
import sys
import tempfile
import time
import urllib.request
from datetime import date
from pathlib import Path

import markdown
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parent.parent
DOCS = ROOT / "docs"
SHOTS = DOCS / "screenshots"
PDF = DOCS / "pdf"
POORT = 8799
URL = f"http://127.0.0.1:{POORT}"
WACHTWOORD = "demo"
GSM = {"width": 390, "height": 844}
PC = {"width": 1280, "height": 860}

# (bestandsnaam, account, pad, toestel, thema, actie vóór de screenshot)
SCREENSHOTS = [
    ("aanmelden", None, "/account/login/", GSM, "light", None),
    ("registreren", None, "/registreren/", GSM, "light", None),
    ("zwemmer-wedstrijden", "zwemmer1", "/wedstrijden/", GSM, "light", None),
    ("zwemmer-wedstrijd", "zwemmer1", "/wedstrijd/1/?zwemmer=1", GSM, "light", None),
    ("zwemmer-registreren-gezin", None, "/registreren/", GSM, "light", "tweede-zwemmer"),
    ("zwemmer-programma", "zwemmer1", "/wedstrijd/1/?zwemmer=1", GSM, "light", "programma"),
    ("zwemmer-profiel", "zwemmer1", "/zwemmers/1/", GSM, "light", None),
    ("zwemmer-meldingen", "zwemmer1", "/meldingen/", GSM, "light", None),
    ("zwemmer-donker", "zwemmer1", "/wedstrijd/1/?zwemmer=1", GSM, "dark", None),
    ("trainer-nakijken", "trainer1", "/wedstrijd/1/nakijken/", PC, "light", None),
    ("trainer-wijzigen", "trainer1", "/wedstrijd/1/?zwemmer=1", PC, "light", "programma"),
    ("trainer-gsm", "trainer1", "/wedstrijd/1/nakijken/", GSM, "dark", None),
    ("secretaris-wedstrijden", "sportsec1", "/wedstrijden/", PC, "light", None),
    ("secretaris-upload", "sportsec1", "/wedstrijd/nieuw/", PC, "light", None),
    ("secretaris-instellingen", "sportsec1", "/wedstrijd/1/instellingen/", PC, "light", None),
    ("secretaris-gebruikers", "sportsec1", "/gebruikers/", PC, "light", None),
    ("secretaris-donker", "sportsec1", "/wedstrijd/1/instellingen/", PC, "dark", None),
    ("trainer-uitslagen", "trainer1", "/zwemmers/uitslagen/", PC, "light", None),
    ("beheerder-backup", "admin", "/backup/", PC, "light", None),
]

DOCUMENTEN = [
    ("handleiding-zwemmer", "Handleiding zwemmers & ouders"),
    ("handleiding-trainer", "Handleiding trainers"),
    ("handleiding-sportsecretaris", "Handleiding sportsecretaris"),
    ("handleiding-beheerder", "Handleiding beheerder"),
    ("onepager-zwemmer", "Onepager zwemmers"),
    ("onepager-trainer", "Onepager trainers"),
    ("onepager-sportsecretaris", "Onepager sportsecretaris"),
    ("technisch", "Technische documentatie"),
]


def start_server(tmp):
    env = {**os.environ, "DATABASE_URL": f"sqlite:///{tmp}/docs.sqlite3", "DJANGO_DEBUG": "1",
           "DJANGO_MEDIA_ROOT": f"{tmp}/media", "VAPID_FILE": f"{tmp}/vapid.pem"}
    py = sys.executable
    for cmd in (["migrate", "-v0"], ["demodata"]):
        subprocess.run([py, "manage.py", *cmd], cwd=ROOT, env=env, check=True, capture_output=True)
    proc = subprocess.Popen([py, "manage.py", "runserver", str(POORT), "--noreload"], cwd=ROOT, env=env,
                            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    for _ in range(50):
        try:
            urllib.request.urlopen(URL + "/account/login/")
            return proc
        except OSError:
            time.sleep(0.2)
    proc.kill()
    raise SystemExit("server start niet")


def maak_screenshots(browser):
    SHOTS.mkdir(parents=True, exist_ok=True)
    contexten = {}
    for naam, wie, pad, toestel, thema, actie in SCREENSHOTS:
        sleutel = (wie, toestel["width"], thema)
        if sleutel not in contexten:
            ctx = browser.new_context(viewport=toestel, device_scale_factor=2, color_scheme=thema,
                                      service_workers="block", locale="nl-BE")
            if wie:
                p = ctx.new_page()
                p.goto(URL + "/account/login/")
                p.fill("#id_username", wie)
                p.fill("#id_password", WACHTWOORD)
                p.click("form button")
                p.wait_for_url("**/wedstrijden/")
                p.close()
            contexten[sleutel] = ctx
        page = contexten[sleutel].new_page()
        page.goto(URL + pad)
        page.evaluate(f"document.documentElement.dataset.theme = '{thema}'")
        page.evaluate("document.querySelectorAll('.melding.success, .melding.info').forEach(e => e.remove())")
        if actie == "tweede-zwemmer":
            page.fill("#id_naam", "Peeters")
            page.click("#nog-zwemmer")
            page.evaluate("document.querySelector('#zwemmers').scrollIntoView({block: 'start'}); window.scrollBy(0, -70)")
        if actie == "programma":
            page.evaluate("document.querySelector('h2')?.scrollIntoView({block: 'start'}); window.scrollBy(0, -80)")
        page.wait_for_timeout(150)
        page.screenshot(path=SHOTS / f"{naam}.png")
        page.close()
        print("  screenshot", naam)
    for ctx in contexten.values():
        ctx.close()


CSS = """
@page { size: A4; margin: 14mm 14mm 18mm; }
* { box-sizing: border-box; }
:root { --petrol: #0e6b73; --petrol-donker: #0a4f55; --amber: #e8962e; --inkt: #14232c; --zacht: #5d6d77; --lijn: #e0e7ea; --bg: #f4f6f7; }
body { font: 10.3pt/1.55 -apple-system, "Helvetica Neue", Arial, sans-serif; color: var(--inkt); margin: 0;
  -webkit-print-color-adjust: exact; print-color-adjust: exact; }

/* Kopband */
header.kop { position: relative; display: flex; align-items: center; gap: 14px; margin: 0 0 18px;
  padding: 14px 18px 16px; border-radius: 14px; color: #fff;
  background: linear-gradient(135deg, var(--petrol) 0%, var(--petrol-donker) 100%); overflow: hidden; }
header.kop::before { content: ""; position: absolute; right: -30px; bottom: -40px; width: 260px; height: 120px;
  border-radius: 50%; background: rgba(255,255,255,.07); }
header.kop::after { content: ""; position: absolute; left: 0; right: 0; bottom: 0; height: 5px;
  background: repeating-linear-gradient(90deg, var(--amber) 0 22px, #fff 22px 30px); }
header.kop .badge-logo { background: #fff; border-radius: 10px; padding: 4px 8px; display: flex; }
header.kop img { height: 38px; display: block; }
header.kop .titel { font-size: 19pt; font-weight: 800; font-style: italic; letter-spacing: -.01em; }
header.kop .meta { margin-left: auto; font-size: 8.5pt; text-align: right; opacity: .9; }

h1 { display: none; }
h2 { font-style: italic; font-weight: 800; font-size: 13.5pt; color: var(--petrol); margin: 20px 0 8px;
  padding-left: 10px; border-left: 5px solid var(--amber); break-after: avoid; }
h3 { font-size: 11pt; margin: 12px 0 4px; break-after: avoid; color: var(--petrol-donker); }
p, li { orphans: 3; widows: 3; }
ol, ul { padding-left: 1.3em; }
li::marker { color: var(--petrol); font-weight: 700; }
strong { color: #0b3a40; }
a { color: var(--petrol); }

/* Screenshots in een kader */
p > img:only-child { display: block; margin: 10px auto; border-radius: 14px; border: 6px solid #fff;
  box-shadow: 0 0 0 1px var(--lijn), 0 6px 18px rgba(20,35,44,.14); background: #fff; }
img[alt^="gsm"] { max-height: 118mm; width: auto; border-radius: 22px !important; border-width: 7px !important;
  box-shadow: 0 0 0 2px #1d2a30, 0 8px 22px rgba(20,35,44,.2) !important; }
img[alt^="pc"] { max-height: 100mm; width: auto; }

code { background: #e8f2f3; color: var(--petrol-donker); padding: 1px 5px; border-radius: 5px; font-size: 9.3pt; }
pre { background: var(--inkt); color: #e6eef0; padding: 10px 12px; border-radius: 10px; font-size: 8.5pt; white-space: pre-wrap; }
pre code { background: none; color: inherit; }

table { border-collapse: separate; border-spacing: 0; width: 100%; font-size: 9.4pt; margin: 8px 0 12px;
  border: 1px solid var(--lijn); border-radius: 10px; overflow: hidden; break-inside: avoid; }
th { background: var(--petrol); color: #fff; font-weight: 700; text-align: left; padding: 6px 9px; }
td { padding: 6px 9px; border-top: 1px solid var(--lijn); vertical-align: top; }
tr:nth-child(even) td { background: var(--bg); }

blockquote { margin: 10px 0; padding: 8px 14px 8px 40px; border-radius: 10px; position: relative;
  background: #fff4e3; border: 1px solid #f6d7a8; break-inside: avoid; }
blockquote::before { content: "!"; position: absolute; left: 12px; top: 8px; width: 18px; height: 18px; border-radius: 50%;
  background: var(--amber); color: #fff; font-weight: 800; font-size: 11px; text-align: center; line-height: 18px; }
blockquote p { margin: 0; }

.naast { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; align-items: start; }
.naast img { max-height: 112mm; }

/* Onepagers: kaartjes */
.onepager h2 { margin-top: 12px; }
.onepager ol { counter-reset: stap; list-style: none; padding: 0; }
.onepager ol > li { counter-increment: stap; position: relative; padding: 7px 10px 7px 42px; margin: 0 0 6px;
  background: var(--bg); border-radius: 10px; border: 1px solid var(--lijn); }
.onepager ol > li::before { content: counter(stap); position: absolute; left: 10px; top: 7px; width: 22px; height: 22px;
  border-radius: 50%; background: var(--petrol); color: #fff; font-weight: 800; text-align: center; line-height: 22px; font-size: 10pt; }

/* Voorblad bundel */
.voorblad { height: 255mm; display: flex; flex-direction: column; justify-content: center; align-items: center; text-align: center;
  border-radius: 18px; color: #fff; position: relative; overflow: hidden;
  background: linear-gradient(160deg, var(--petrol) 0%, var(--petrol-donker) 70%); }
.voorblad::after { content: ""; position: absolute; left: 0; right: 0; bottom: 0; height: 10px;
  background: repeating-linear-gradient(90deg, var(--amber) 0 30px, #fff 30px 42px); }
.voorblad .badge-logo { background: #fff; border-radius: 16px; padding: 10px 16px; }
.voorblad img { height: 90px; display: block; }
.voorblad h2 { color: #fff; border: 0; padding: 0; font-size: 30pt; margin: 24px 0 4px; }
.voorblad p { opacity: .9; }
.voorblad ol { text-align: left; margin-top: 16mm; background: rgba(255,255,255,.1); padding: 14px 18px 14px 36px; border-radius: 12px; }
.voorblad li::marker { color: var(--amber); }
.voorblad strong { color: #fff; }
"""


def render(slug, titel, logo_uri):
    bron = (DOCS / "bronnen" / f"{slug}.md").read_text()
    html = markdown.markdown(bron, extensions=["tables", "md_in_html", "attr_list"])
    html = html.replace('src="../screenshots/', f'src="{SHOTS.as_uri()}/')
    klasse = "onepager" if slug.startswith("onepager") else ""
    return f"""<!doctype html><html lang="nl"><head><meta charset="utf-8"><style>{CSS}</style></head>
<body class="{klasse}"><header class="kop"><span class="badge-logo"><img src="{logo_uri}"></span><span class="titel">{titel}</span>
<span class="meta">HZS inschrijvingen<br>versie {date.today():%d/%m/%Y}</span></header>{html}</body></html>"""


def maak_pdfs(browser):
    PDF.mkdir(parents=True, exist_ok=True)
    logo = (ROOT / "static" / "img" / "logo.png").as_uri()
    page = browser.new_page()
    delen = []
    voet = ('<div style="font-size:7.5pt;color:#5d6d77;width:100%;padding:0 15mm;display:flex;justify-content:space-between">'
            '<span><b style="color:#0e6b73">Hasseltse Zwemvereniging Spartacus</b> · inschrijvingen.khzs.be</span><span><span class="pageNumber"></span>/<span class="totalPages"></span></span></div>')
    for slug, titel in DOCUMENTEN:
        html = render(slug, titel, logo)
        delen.append((titel, html))
        tmp = DOCS / f".{slug}.html"
        tmp.write_text(html)
        page.goto(tmp.as_uri())
        page.wait_for_load_state("networkidle")
        page.pdf(path=PDF / f"{slug}.pdf", format="A4", print_background=True, display_header_footer=True,
                 header_template="<span></span>", footer_template=voet, margin={"top": "16mm", "bottom": "18mm"})
        tmp.unlink()
        print("  pdf", slug)

    # Volledige bundel
    inhoud = "".join(f"<li>{t}</li>" for t, _ in delen)
    lichamen = []
    for titel, html in delen:
        lichaam = re.search(r"<body[^>]*>(.*)</body>", html, re.S).group(1)
        lichamen.append(f'<section style="break-before: page">{lichaam}</section>')
    voorblad = f"""<section class="voorblad"><span class="badge-logo"><img src="{logo}"></span>
<h2>HZS inschrijvingen</h2><p style="font-size:14pt">Hasseltse Zwemvereniging Spartacus<br>Volledige documentatie</p>
<p>versie {date.today():%d/%m/%Y} · inschrijvingen.khzs.be</p><ol>{inhoud}</ol></section>"""
    bundel = f'<!doctype html><html lang="nl"><head><meta charset="utf-8"><style>{CSS}</style></head><body>{voorblad}{"".join(lichamen)}</body></html>'
    tmp = DOCS / ".bundel.html"
    tmp.write_text(bundel)
    page.goto(tmp.as_uri())
    page.wait_for_load_state("networkidle")
    page.pdf(path=PDF / "HZS-inschrijvingen-documentatie.pdf", format="A4", print_background=True,
             display_header_footer=True, header_template="<span></span>", footer_template=voet,
             margin={"top": "16mm", "bottom": "18mm"})
    tmp.unlink()
    print("  pdf HZS-inschrijvingen-documentatie")


def main():
    with tempfile.TemporaryDirectory() as tmp:
        proc = start_server(tmp)
        try:
            with sync_playwright() as pw:
                browser = pw.chromium.launch()
                print("Screenshots…")
                maak_screenshots(browser)
                print("PDF's…")
                maak_pdfs(browser)
                browser.close()
        finally:
            proc.terminate()


if __name__ == "__main__":
    main()
