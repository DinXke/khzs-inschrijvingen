"""Instructiefilmpjes met voice-over (zwemmer, trainer, sportsecretaris).

Gebruik (vanuit de projectmap, op macOS):  .venv/bin/python docs/video.py [zwemmer|trainer|sportsecretaris]
Vereist: playwright + chromium, imageio-ffmpeg en de macOS-stem 'Ellen' (nl_BE).
De app draait tijdelijk met demodata; resultaat in docs/video/<rol>.mp4 (+ .srt ondertitels).
"""
import base64
import importlib.util
import re
import subprocess
import sys
import tempfile
import time
from pathlib import Path

import imageio_ffmpeg
from playwright.sync_api import sync_playwright

DOCS = Path(__file__).resolve().parent
UIT = DOCS / "video"
spec = importlib.util.spec_from_file_location("docsbuild", DOCS / "build.py")
build = importlib.util.module_from_spec(spec)
spec.loader.exec_module(build)
URL, ROOT = build.URL, build.ROOT
FFMPEG = imageio_ffmpeg.get_ffmpeg_exe()
STEM, TEMPO = "Ellen", 172
GSM, PC = {"width": 390, "height": 844}, {"width": 1280, "height": 800}

# Zichtbare cursor + markering van het aangeklikte element (headless Chromium toont geen cursor).
INIT = """
addEventListener('DOMContentLoaded', () => {
  const c = document.createElement('div'); c.id = '__cursor';
  c.style.cssText = 'position:fixed;z-index:99999;width:22px;height:22px;margin:-11px 0 0 -11px;border-radius:50%;'
    + 'background:rgba(232,150,46,.55);border:2px solid #e8962e;pointer-events:none;transition:transform .15s;left:-50px;top:-50px';
  document.body.appendChild(c);
  addEventListener('mousemove', e => { c.style.left = e.clientX + 'px'; c.style.top = e.clientY + 'px'; });
  addEventListener('mousedown', () => c.style.transform = 'scale(.6)');
  addEventListener('mouseup', () => c.style.transform = 'scale(1)');
  const s = document.createElement('style');
  s.textContent = '.__markeer{outline:3px solid #e8962e!important;outline-offset:3px;border-radius:8px;transition:outline .2s}'
    + 'body{animation:__in .45s ease-out}@keyframes __in{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}';
  document.head.appendChild(s);
});
"""


def splash(titel, sub, logo):
    """Geanimeerd HZS-splashscreen: golven, logo dat inzoomt, lijnkoord dat binnenglijdt, titel die opkomt."""
    return f"""<html><head><style>
    body{{margin:0;height:100vh;overflow:hidden;font-family:-apple-system,Segoe UI,Arial;color:#fff;text-align:center;
      background:radial-gradient(circle at 50% 35%,#13838c,#0a4f55 70%);display:flex;flex-direction:column;align-items:center;justify-content:center}}
    .golf{{position:absolute;left:-50%;width:200%;height:46%;bottom:-8%;border-radius:45%;background:rgba(255,255,255,.07);
      animation:golf 7s linear infinite}}
    .golf.b{{bottom:-14%;background:rgba(255,255,255,.05);animation-duration:11s;animation-direction:reverse}}
    @keyframes golf{{to{{transform:rotate(360deg)}}}}
    .ring{{position:absolute;top:34%;left:50%;width:10px;height:10px;margin:-5px;border-radius:50%;border:2px solid rgba(255,255,255,.5);
      animation:ring 2.6s ease-out infinite}}
    .ring.b{{animation-delay:.9s}} .ring.c{{animation-delay:1.8s}}
    @keyframes ring{{from{{transform:scale(1);opacity:.9}}to{{transform:scale(60);opacity:0}}}}
    .logo{{position:relative;background:#fff;border-radius:22px;padding:12px 22px;box-shadow:0 20px 60px rgba(0,0,0,.3);
      animation:pop 1.1s cubic-bezier(.2,1.4,.4,1) both}}
    .logo img{{height:96px;display:block}}
    @keyframes pop{{from{{transform:scale(.3) rotate(-8deg);opacity:0}}to{{transform:none;opacity:1}}}}
    h1{{position:relative;font-style:italic;font-weight:800;font-size:44px;margin:30px 24px 6px;animation:op .9s .7s both}}
    p{{position:relative;font-size:20px;opacity:.9;margin:0 24px;animation:op .9s 1.1s both}}
    @keyframes op{{from{{transform:translateY(24px);opacity:0}}to{{transform:none;opacity:1}}}}
    .koord{{position:absolute;left:0;right:0;bottom:0;height:14px;background:repeating-linear-gradient(90deg,#e8962e 0 30px,#fff 30px 42px);
      animation:koord 1.4s .3s cubic-bezier(.3,.9,.3,1) both, schuif 3s 1.7s linear infinite}}
    @keyframes koord{{from{{transform:translateX(-100%)}}to{{transform:none}}}}
    @keyframes schuif{{to{{background-position:84px 0}}}}
    </style></head><body>
    <div class="golf"></div><div class="golf b"></div><div class="ring"></div><div class="ring b"></div><div class="ring c"></div>
    <div class="logo"><img src="data:image/png;base64,{logo}"></div><h1>{titel}</h1><p>{sub}</p><div class="koord"></div>
    </body></html>"""


HOOFDSTUK_JS = """([nr, titel, ms]) => {
  const o = document.createElement('div');
  o.innerHTML = `<div style="font-size:15px;letter-spacing:.2em;text-transform:uppercase;opacity:.85">Stap ${nr}</div>
    <div style="font-style:italic;font-weight:800;font-size:40px;margin-top:8px;padding:0 24px">${titel}</div>`;
  o.style.cssText = 'position:fixed;inset:0;z-index:100000;display:flex;flex-direction:column;align-items:center;justify-content:center;'
    + 'text-align:center;color:#fff;font-family:-apple-system,Segoe UI,Arial;background:linear-gradient(135deg,#0e6b73,#0a4f55);'
    + 'clip-path:inset(0 100% 0 0);transition:clip-path .55s cubic-bezier(.7,0,.3,1)';
  const k = document.createElement('div');
  k.style.cssText = 'position:absolute;left:0;right:0;bottom:0;height:10px;background:repeating-linear-gradient(90deg,#e8962e 0 30px,#fff 30px 42px)';
  o.appendChild(k); document.body.appendChild(o);
  requestAnimationFrame(() => requestAnimationFrame(() => o.style.clipPath = 'inset(0 0 0 0)'));
  setTimeout(() => { o.style.clipPath = 'inset(0 0 0 100%)'; setTimeout(() => o.remove(), 700); }, ms);
}"""


def kaart(titel, sub, logo):
    return f"""<html><body style="margin:0;height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;
    font-family:-apple-system,Segoe UI,Arial;color:#fff;text-align:center;background:linear-gradient(160deg,#0e6b73,#0a4f55);position:relative">
    <div style="background:#fff;border-radius:18px;padding:10px 18px"><img src="data:image/png;base64,{logo}" style="height:80px;display:block"></div>
    <h1 style="font-style:italic;font-weight:800;font-size:44px;margin:26px 20px 6px">{titel}</h1>
    <p style="font-size:20px;opacity:.9;margin:0 20px">{sub}</p>
    <div style="position:absolute;left:0;right:0;bottom:0;height:12px;background:repeating-linear-gradient(90deg,#e8962e 0 30px,#fff 30px 42px)"></div>
    </body></html>"""


class Opname:
    def __init__(self, page, tmp):
        self.page, self.tmp, self.stappen, self.t0 = page, Path(tmp), [], time.monotonic()

    def hoofdstuk(self, nr, titel):
        """Hoofdstukkaart die over het huidige scherm veegt terwijl de stem het hoofdstuk aankondigt."""
        self._spreek(f"Stap {nr}. {titel}.", lambda p, duur: p.evaluate(HOOFDSTUK_JS, [nr, titel, int(duur * 1000) + 500]),
                     extra=1.0)

    def stap(self, tekst, actie=None):
        self._spreek(tekst, (lambda p, duur: actie(p)) if actie else None)

    def _spreek(self, tekst, actie, extra=0.7):
        """Spreekt de tekst in en voert ondertussen de actie uit; wacht tot de zin uitgesproken is."""
        nr = len(self.stappen)
        wav = self.tmp / f"s{nr:02d}.aiff"
        subprocess.run(["say", "-v", STEM, "-r", str(TEMPO), "-o", str(wav), tekst], check=True)
        duur = float(re.search(r"duration: ([\d.]+)", subprocess.run(["afinfo", str(wav)], capture_output=True,
                                                                     text=True).stdout).group(1))
        start = time.monotonic() - self.t0
        if actie:
            actie(self.page, duur)
        rest = duur + extra - (time.monotonic() - self.t0 - start)
        if rest > 0:
            self.page.wait_for_timeout(int(rest * 1000))
        self.stappen.append((start, duur, tekst, wav))


def beweeg_klik(page, doel, klik=True):
    loc = page.locator(doel).first if isinstance(doel, str) else doel.first
    if loc.count() == 0:  # demodata is willekeurig: ontbreekt een element, dan slaan we de klik over
        print("    (niet gevonden:", doel, ")")
        return
    loc.scroll_into_view_if_needed()
    box = loc.bounding_box()
    if box:
        page.mouse.move(box["x"] + box["width"] / 2, box["y"] + box["height"] / 2, steps=18)
    loc.evaluate("e => e.classList.add('__markeer')")
    page.wait_for_timeout(450)
    if klik:
        loc.click()
        page.wait_for_load_state("networkidle")
    else:
        loc.evaluate("e => e.classList.remove('__markeer')")


def typ(page, doel, tekst):
    beweeg_klik(page, doel)
    page.locator(doel).first.fill("")
    page.locator(doel).first.press_sequentially(tekst, delay=70)


def scroll(page, pixels):
    for _ in range(abs(pixels) // 40):
        page.mouse.wheel(0, 40 if pixels > 0 else -40)
        page.wait_for_timeout(25)


def ga(page, pad):
    page.goto(URL + pad)
    page.wait_for_load_state("networkidle")


def login(page, wie):
    ga(page, "/account/login/")
    typ(page, "#id_username", wie)
    typ(page, "#id_password", "demo")
    beweeg_klik(page, "main form button")


def open_wedstrijd(page, naam):
    ga(page, "/wedstrijden/")
    beweeg_klik(page, page.get_by_text(naam, exact=True))


# ----------------------------------------------------------------------------------------------- scripts

def zwemmer(o, logo):
    p = o.page
    p.set_content(splash("Inschrijven als zwemmer", "Voor zwemmers en ouders · Hasseltse Zwemvereniging Spartacus", logo))
    o.stap("Hallo! Welkom bij de inschrijvingen van de Hasseltse Zwemvereniging Spartacus. "
           "In dit filmpje leggen we stap voor stap uit hoe je je inschrijft voor een zwemwedstrijd. "
           "Het filmpje is bedoeld voor zwemmers, en voor ouders die hun kinderen inschrijven.")
    o.hoofdstuk(1, "De app openen")
    o.stap("Alles gebeurt in de app op inschrijvingen punt k h z s punt b e. "
           "Je kan die openen in de browser op je gsm, je tablet of je computer.", lambda p: ga(p, "/account/login/"))
    o.stap("Een handige tip: zet de app op het beginscherm van je gsm. Op een iPhone tik je op de deelknop, en dan op "
           "zet op beginscherm. Op Android kies je app installeren. Daarna open je de app met één tik, net als een gewone app.")
    o.hoofdstuk(2, "Een account aanmaken")
    o.stap("Heb je nog geen account? Tik dan op maak een profiel aan. We werken met één account per gezin. "
           "Bovenaan vul je de gegevens in van de persoon die het account beheert, meestal een ouder: "
           "je e-mailadres, je naam en een wachtwoord.", lambda p: (ga(p, "/registreren/"), scroll(p, 250)))
    o.stap("Daaronder vul je de gegevens van de zwemmer in: voornaam, naam, geboortedatum en of het een jongen of meisje is. "
           "De geboortedatum is belangrijk, want die bepaalt in welke categorie je zwemt, en dus op welke nummers je mag inschrijven.",
           lambda p: scroll(p, 450))
    o.stap("Zwemmen er meerdere kinderen in het gezin? Tik dan op nog een zwemmer, en vul ook hun gegevens in. "
           "Zwem je als ouder zelf ook? Voeg jezelf dan gewoon toe als zwemmer. Het licentienummer is niet verplicht.",
           lambda p: (beweeg_klik(p, "#nog-zwemmer"), scroll(p, 500)))
    o.stap("Heb je al een account? Dan meld je je gewoon aan met je e-mailadres en je wachtwoord. "
           "Wachtwoord vergeten? Via de link onder de knop kan je een nieuw wachtwoord aanvragen.",
           lambda p: login(p, "zwemmer1"))
    o.hoofdstuk(3, "Een wedstrijd kiezen")
    o.stap("Na het aanmelden kom je bij de lijst met wedstrijden. Je ziet alle komende wedstrijden zodra het sportsecretariaat "
           "ze heeft opgeladen. Staat er nog niet open? Dan kan je nog niet inschrijven, maar wel al laten weten of je wil meedoen.",
           lambda p: scroll(p, 200))
    o.stap("Bij elke wedstrijd zie je ook tot wanneer je kan inschrijven. Dat is jouw persoonlijke deadline. "
           "Die ligt altijd een paar dagen vóór de echte deadline van de organisator, zodat je trainer en het sportsecretariaat "
           "nog tijd hebben om alles na te kijken en door te sturen.", lambda p: scroll(p, 250))
    o.hoofdstuk(4, "Inschrijven")
    o.stap("Laten we een wedstrijd openen. Heb je meerdere kinderen in je account? Dan zie je bovenaan een knop per kind. "
           "Tik op het kind dat je wil inschrijven. Elk kind heeft zijn eigen inschrijving.",
           lambda p: (open_wedstrijd(p, "Regiocup 1 Bree"), beweeg_klik(p, ".kind >> nth=0")))
    o.stap("Lees eerst de info van de club, in het blauwe vak. Daar staat bijvoorbeeld voor wie de wedstrijd bedoeld is, "
           "of praktische afspraken zoals het uur van vertrek.", lambda p: beweeg_klik(p, ".info-wedstrijd", klik=False))
    o.stap("Laat dan weten of je meedoet. Kies interesse als je twijfelt, ik doe mee als je zeker wil zwemmen, "
           "of ik doe niet mee. Je trainer en het sportsecretariaat zien je keuze meteen. "
           "Met interesse of ik doe mee krijg je ook herinneringen voor de deadline.",
           lambda p: beweeg_klik(p, "button[value=doet_mee]"))
    o.stap("Daaronder staat het programma, per sessie. Je ziet enkel de nummers die passen bij jouw leeftijd en of je een "
           "jongen of meisje bent. Je hoeft dus niet zelf uit te zoeken in welke categorie je zwemt.",
           lambda p: (p.locator("h2").first.scroll_into_view_if_needed(), scroll(p, 100)))
    o.stap("Bij elk nummer zie je je beste tijd. Heeft de wedstrijd limieten, dan zie je ook de limiettijd. "
           "Een groen label betekent dat je de limiet haalt. Een rood label betekent dat je ze nog niet haalt, "
           "en dan kan je dat nummer niet aanvinken.", lambda p: scroll(p, 150))
    o.stap("Let op: een limiet geldt voor een bepaalde badlengte. Een tijd die je zwom in een bad van vijfentwintig meter, "
           "telt niet voor een limiet in een bad van vijftig meter, en omgekeerd.")
    o.stap("Vink nu de nummers aan die je wil zwemmen, en tik onderaan op bewaren. "
           "Je krijgt bovenaan een bevestiging met het aantal toegevoegde nummers.",
           lambda p: (beweeg_klik(p, "label.nummer:not(.uit) input[type=checkbox]:not(:checked):not([disabled])"),
                      beweeg_klik(p, ".vaste-actie button")))
    o.hoofdstuk(5, "Je inschrijving opvolgen")
    o.stap("Je inschrijving staat nu op ingediend. Dat betekent: ze is goed ontvangen, maar je trainer moet ze nog nakijken. "
           "Daarna wordt ze goedgekeurd, of afgekeurd met een opmerking waarom.",
           lambda p: (p.locator("label.nummer input:checked").first.scroll_into_view_if_needed(), p.wait_for_timeout(300)))
    o.stap("Tot je deadline kan je nog alles wijzigen: nummers bijvoegen of schrappen, zelfs als ze al goedgekeurd zijn. "
           "Wanneer je trainer je inschrijving finaliseert, ligt ze vast. Wil je dan nog iets veranderen, "
           "vraag het dan aan je trainer.")
    o.stap("Past de club iets aan, bijvoorbeeld je inschrijftijd, of keurt de trainer een nummer af? "
           "Dan zie je dat bovenaan de wedstrijd, onder wijzigingen door de club. In de lijst met wedstrijden staat er dan "
           "een belletje bij.", lambda p: p.evaluate("window.scrollTo({top: 0, behavior: 'smooth'})"))
    o.hoofdstuk(6, "Profiel en meldingen")
    o.stap("Onder profiel vind je al je zwemmers, en per zwemmer de beste tijden. Die tijden vul je niet zelf in: "
           "de club werkt ze bij na elke wedstrijd, op basis van de officiële uitslagen. Klopt er iets niet? Laat het je trainer weten.",
           lambda p: (ga(p, "/zwemmers/"), beweeg_klik(p, "a.kaart >> nth=0")))
    o.stap("Je kan bij je gegevens ook je Swimrankings nummer invullen. Dan krijg je een knop die meteen je pagina op "
           "Swimrankings opent.", lambda p: scroll(p, 900))
    o.stap("Zet tot slot de meldingen aan. Tik op meldingen, en dan op meldingen aanzetten. Je kiest zelf waarvoor je een "
           "bericht wil krijgen: een nieuwe wedstrijd, de herinnering een dag voor je deadline, "
           "of een wijziging door je trainer.", lambda p: ga(p, "/meldingen/"))
    o.stap("Op een iPhone werken meldingen enkel als je de app eerst op je beginscherm hebt gezet, zoals we in het begin "
           "uitlegden. Met de knop naast het logo kies je trouwens tussen een licht en een donker thema.",
           lambda p: beweeg_klik(p, "#thema", klik=False))
    p.set_content(splash("Veel succes!", "Vragen? Spreek je trainer aan.", logo))
    o.stap("Dat is alles! Kort samengevat: kies je wedstrijd, laat weten of je meedoet, vink je nummers aan, en bewaar. "
           "Veel zwemplezier, en veel succes op de wedstrijd!")


def trainer(o, logo):
    p = o.page
    p.set_content(splash("Handleiding voor trainers", "Hasseltse Zwemvereniging Spartacus", logo))
    o.stap("Welkom. Dit filmpje is bedoeld voor trainers. We tonen hoe je de inschrijvingen van je zwemmers opvolgt, "
           "nakijkt, aanpast en finaliseert, zodat het sportsecretariaat ze vlot kan doorsturen naar de organisator.")
    o.hoofdstuk(1, "Aanmelden en deadlines")
    o.stap("Je meldt je aan met je eigen account. Maak eerst gewoon een profiel aan, en vraag dan aan de sportsecretaris "
           "om je de rol trainer te geven. Pas dan zie je de extra schermen.", lambda p: login(p, "trainer1"))
    o.stap("In de lijst met wedstrijden zie je per wedstrijd drie deadlines. De deadline van de organisator komt uit het "
           "lenex-bestand. Het sportsecretariaat neemt daar een paar dagen marge op: dat is de deadline voor trainers. "
           "En jij neemt daar nog eens een paar dagen marge op: dat is de deadline voor de zwemmers.",
           lambda p: scroll(p, 200))
    o.stap("Je ziet ook hoeveel inschrijvingen er zijn, en hoeveel er nog nagekeken moeten worden. "
           "Zo zie je in één oogopslag waar er werk ligt.", lambda p: scroll(p, 300))
    o.stap("We openen een wedstrijd. Bovenaan zie je het blok finaliseren: hoeveel zwemmers je al gefinaliseerd hebt, "
           "en hoeveel het secretariaat al heeft ingegeven. Daaronder zie je per keuze welke zwemmers interesse hebben, "
           "meedoen, niet meedoen, of geweigerd zijn. Klik op een naam om die zwemmer te openen.",
           lambda p: (open_wedstrijd(p, "Kerstprijs ZCT"), scroll(p, 200)))
    o.hoofdstuk(2, "Deadline en info instellen")
    o.stap("Klap het blok deadline en info open. Hier vul je in hoeveel dagen vóór de trainersdeadline de zwemmers "
           "ingeschreven moeten zijn. De app rekent de datum voor de zwemmers meteen uit.",
           lambda p: (beweeg_klik(p, "details.kaart summary"), beweeg_klik(p, "#id_dagen_trainer", klik=False)))
    o.stap("In hetzelfde blok kan je info voor de zwemmers toevoegen. Bijvoorbeeld: deze wedstrijd is enkel voor de A-groep, "
           "of: we vertrekken om half zeven aan het zwembad. Die tekst staat bovenaan de wedstrijd, voor iedereen zichtbaar.",
           lambda p: beweeg_klik(p, "#id_info", klik=False))
    o.hoofdstuk(3, "Inschrijvingen nakijken")
    o.stap("Nu het nakijken. Met de filters bovenaan de lijst toon je enkel wat nog na te kijken is, wat al goedgekeurd is, "
           "of wat afgekeurd werd.", lambda p: (beweeg_klik(p, "details.kaart summary"), beweeg_klik(p, "a.badge.ingediend")))
    o.stap("Vink de inschrijvingen aan die je wil beoordelen. Met het vakje naast de naam selecteer je meteen alle nummers "
           "van die zwemmer. Onderaan vul je eventueel een opmerking in, en klik je op goedkeuren of afkeuren. "
           "De zwemmer ziet je beslissing en je opmerking, en krijgt een melding.",
           lambda p: (beweeg_klik(p, "input[name=inschrijving]"), beweeg_klik(p, "input[name=opmerking]", klik=False)))
    o.hoofdstuk(4, "Inschrijvingen aanpassen")
    o.stap("Wil je de inschrijving zelf aanpassen? Klik bij de zwemmer op wijzigen. Je komt dan op hetzelfde scherm als de "
           "zwemmer, maar met meer mogelijkheden.",
           lambda p: (ga(p, "/wedstrijden/"), open_wedstrijd(p, "Kerstprijs ZCT"), beweeg_klik(p, "text=wijzigen >> nth=0"),
                      p.locator("h2").first.scroll_into_view_if_needed()))
    o.stap("Als trainer kan je ook inschrijven op een nummer waarvoor de limiet nog niet gehaald is, en kan je naast elk "
           "nummer de inschrijftijd aanpassen. Wat jij toevoegt is meteen goedgekeurd. Na bewaren krijgt de zwemmer een "
           "melding met precies wat er veranderd is.", lambda p: beweeg_klik(p, ".tijd-invoer", klik=False))
    o.hoofdstuk(5, "Finaliseren en weigeren")
    o.stap("Is de inschrijving van een zwemmer helemaal in orde? Klik dan op finaliseren. Drie dingen gebeuren: openstaande "
           "inschrijvingen worden goedgekeurd, de zwemmer kan niets meer wijzigen, ook niet vóór de deadline, "
           "en het sportsecretariaat ziet dat deze zwemmer klaar is, zodat die al kan worden ingegeven.",
           lambda p: (p.go_back(), p.wait_for_load_state("networkidle"),
                      beweeg_klik(p, "button:has-text('Finaliseren'):not(:has-text('Alles'))", klik=False)))
    o.stap("Met alles finaliseren doe je dat in één keer voor alle zwemmers. En met heropenen kan een zwemmer opnieuw wijzigen. "
           "Pas je iets aan nadat het secretariaat de zwemmer al heeft ingegeven, dan krijgt het secretariaat automatisch "
           "een waarschuwing.", lambda p: beweeg_klik(p, "button:has-text('Alles finaliseren')", klik=False))
    o.stap("Mag een zwemmer niet deelnemen, bijvoorbeeld omdat hij of zij nog niet in de wedstrijdgroep zit? Open de zwemmer, "
           "vul een reden in en klik op weigeren. De zwemmer krijgt een melding met de reden, openstaande inschrijvingen worden "
           "afgekeurd, en inschrijven voor deze wedstrijd lukt niet meer. Met opnieuw toestaan draai je dat terug.",
           lambda p: (beweeg_klik(p, ".finaal ~ .kaart a >> nth=0"), beweeg_klik(p, "#reden", klik=False)))
    o.hoofdstuk(6, "Overzicht voor de training")
    o.stap("Voor de laatste controle op training is er het overzicht. Per zwemmer zie je elk nummer met sessie, inschrijftijd "
           "en status, met een vakje om af te vinken en ruimte voor notities.",
           lambda p: (p.go_back(), p.wait_for_load_state("networkidle"),
                      beweeg_klik(p, "text=Overzicht / afdrukken"), scroll(p, 400)))
    o.stap("Je kan ook per nummer kijken, dan staan de zwemmers per nummer gesorteerd op inschrijftijd. Kies bovenaan of je "
           "afgekeurde inschrijvingen wil zien, en druk het overzicht af of bewaar het als PDF.",
           lambda p: (beweeg_klik(p, "text=Per nummer"), scroll(p, 400)))
    o.hoofdstuk(7, "Uitslagen importeren")
    o.stap("Na de wedstrijd werk je de beste tijden bij. Ga naar zwemmers, en dan uitslagen importeren. Laad het "
           "uitslagen-lenex-bestand van de wedstrijd op. De app koppelt de resultaten aan onze zwemmers, via licentienummer of "
           "via naam en geboortedatum, en bewaart per nummer de beste tijd. Zo kloppen de limietcontroles bij de volgende wedstrijd.",
           lambda p: ga(p, "/zwemmers/uitslagen/"))
    p.set_content(splash("Klaar!", "De volledige handleiding staat in de PDF.", logo))
    o.stap("Samengevat: stel je deadline in, kijk de inschrijvingen na, pas aan waar nodig, en finaliseer tijdig. "
           "De volledige handleiding vind je ook als PDF. Veel succes met je zwemmers!")


def sportsecretaris(o, logo):
    p = o.page
    vk = ROOT / "inschrijvingen" / "tests" / "fixtures" / "vk_kb_jeugd.lxf"
    p.set_content(splash("Handleiding voor de sportsecretaris", "Hasseltse Zwemvereniging Spartacus", logo))
    o.stap("Welkom. Dit filmpje is bedoeld voor de sportsecretaris. We doorlopen het volledige proces: een wedstrijd opladen, "
           "de deadlines en selectiecriteria instellen, de inschrijvingen opvolgen, en ze doorsturen naar de organisator.")
    o.stap("Meld je aan met je account. De rol sportsecretaris geeft je alle rechten van een trainer, en daarbovenop het "
           "opladen van wedstrijden, de instellingen, de export en het toekennen van rollen.", lambda p: login(p, "sportsec1"))
    o.hoofdstuk(1, "Een wedstrijd opladen")
    o.stap("Alles begint met het lenex-bestand van de organisator. Dat is het digitale voorprogramma, meestal een bestand op "
           "punt l x f, gemaakt met bijvoorbeeld Splash Meet Manager. Klik op Lenex uploaden en kies het bestand.",
           lambda p: (beweeg_klik(p, "text=+ Lenex uploaden"), p.set_input_files("#id_bestand", str(vk)),
                      beweeg_klik(p, "#id_bestand", klik=False)))
    o.stap("Klik op inlezen. De app haalt automatisch alles uit het bestand: de sessies met hun uren, alle nummers met "
           "leeftijdsgroepen en inschrijfgeld, de laatste inschrijfdatum, de datum waarop de leeftijd berekend wordt, "
           "de kwalificatieperiode en de limietlijsten.", lambda p: beweeg_klik(p, "main form button"))
    o.hoofdstuk(2, "Deadlines, limieten en status")
    o.stap("Je komt meteen op de instellingen van de wedstrijd. Controleer eerst de laatste inschrijfdatum van de organisator. "
           "Ontbreekt die in het bestand, dan zet de app voorlopig zeven dagen voor de wedstrijd, en krijg je een waarschuwing.",
           lambda p: beweeg_klik(p, "#id_deadline", klik=False))
    o.stap("Vul daarna bij dagen secretaris in hoeveel dagen vroeger alles bij jou binnen moet zijn. Dat is de deadline voor "
           "de trainers. De trainers nemen daar zelf nog marge op voor de zwemmers. Zo krijg je: deadline zwemmers is deadline "
           "organisator, min jouw dagen, min de dagen van de trainer.", lambda p: beweeg_klik(p, "#id_dagen_secretaris", klik=False))
    o.stap("Bij selectiecriteria kies je welke limietlijsten gehaald moeten zijn. Elke lijst bestaat per badlengte. "
           "Vink je niets aan, dan zijn er geen limieten en kan iedereen op elk nummer inschrijven.",
           lambda p: (scroll(p, 300), beweeg_klik(p, "input[name=limietgroepen] >> nth=0")))
    o.stap("De status bepaalt wat zwemmers kunnen. Bij concept zien ze de wedstrijd al en kunnen ze interesse opgeven, maar "
           "nog niet inschrijven. Bij open kunnen ze inschrijven tot hun deadline. Gesloten betekent dat er niets meer kan "
           "wijzigen, en doorgestuurd zet je wanneer alles bij de organisator zit.",
           lambda p: (p.select_option("#id_status", "open"), beweeg_klik(p, "#id_status", klik=False)))
    o.stap("Bewaar. Zet je een wedstrijd op open, dan krijgen alle zwemmers met interesse of ik doe mee een pushmelding. "
           "Onderaan de pagina zie je het volledige programma ter controle.",
           lambda p: (beweeg_klik(p, "form.kaart button"), scroll(p, 1100)))
    o.stap("Stuurt de organisator later een aangepaste versie van het voorprogramma? Klik dan op lenex opnieuw uploaden. "
           "Bestaande inschrijvingen en je gekozen limieten blijven behouden.",
           lambda p: (scroll(p, -1100), beweeg_klik(p, "text=Lenex opnieuw uploaden", klik=False)))
    o.hoofdstuk(3, "Opvolgen en al ingeven")
    o.stap("Tijdens de inschrijfperiode kijken de trainers de inschrijvingen na en finaliseren ze hun zwemmers. "
           "Op het nakijkscherm zie je bovenaan hoeveel zwemmers al gefinaliseerd zijn.",
           lambda p: (open_wedstrijd(p, "Kerstprijs ZCT"), p.goto(p.url.replace("instellingen", "nakijken")),
                      beweeg_klik(p, ".finaal", klik=False)))
    o.stap("Een gefinaliseerde zwemmer kan je al ingeven, bijvoorbeeld in Splash. Klik daarna bij die zwemmer op ingegeven. "
           "Wijzigt een trainer daarna nog iets, dan verdwijnt het vinkje en zie je in het rood: gewijzigd na ingave. "
           "Zo weet je precies wat je nog moet aanpassen.",
           lambda p: beweeg_klik(p, "button:has-text('✓ Ingegeven')", klik=False))
    o.stap("Wil je enkel de gefinaliseerde zwemmers exporteren? Gebruik dan de links lenex en excel in het blok finaliseren.",
           lambda p: beweeg_klik(p, ".finaal a >> nth=0", klik=False))
    o.stap("Het overzicht geeft je een afdrukbare lijst per zwemmer of per nummer, met inschrijftijden en inschrijfgeld. "
           "Handig om alles nog eens na te lopen.", lambda p: (beweeg_klik(p, "text=Overzicht / afdrukken"), scroll(p, 300)))
    o.hoofdstuk(4, "Exporteren en doorsturen")
    o.stap("Na de deadline van de trainers zet je de wedstrijd op gesloten. Met de knop lenex exporteer je alle goedgekeurde "
           "inschrijvingen: zwemmers, licenties en inschrijftijden, in een bestand dat de organisator rechtstreeks in Splash "
           "importeert. Met excel krijg je een overzicht per nummer en per zwemmer, inclusief de kosten.",
           lambda p: (p.go_back(), p.wait_for_load_state("networkidle"), beweeg_klik(p, "text=Instellingen & export"),
                      beweeg_klik(p, "text=⬇ Lenex (.lxf)", klik=False)))
    o.stap("Stuur het bestand naar de organisator, en zet de status op doorgestuurd. Na de wedstrijd importeer je de uitslagen "
           "via zwemmers, uitslagen importeren, zodat de beste tijden kloppen voor de volgende keer.",
           lambda p: beweeg_klik(p, "#id_status", klik=False))
    o.hoofdstuk(5, "Rollen en kosten")
    o.stap("Onder gebruikers ken je de rollen toe: zwemmer, trainer of sportsecretaris. Nieuwe accounts zijn altijd eerst "
           "zwemmer. Backups en het technische beheer zijn voorbehouden aan de beheerder.", lambda p: ga(p, "/gebruikers/"))
    o.stap("Inschrijfgeld zie je als sportsecretaris en als trainer overal staan. Zwemmers en ouders zien geen bedragen.")
    p.set_content(splash("Klaar!", "De volledige handleiding staat in de PDF.", logo))
    o.stap("Samengevat: lenex opladen, deadline en limieten instellen, openzetten, gefinaliseerde zwemmers ingeven, "
           "en na de deadline exporteren en doorsturen. De volledige handleiding vind je ook als PDF. Veel succes!")


ROLLEN = {"zwemmer": (zwemmer, GSM), "trainer": (trainer, PC), "sportsecretaris": (sportsecretaris, PC)}


def monteer(naam, webm, stappen, tmp, duur):
    UIT.mkdir(exist_ok=True)
    tmp = Path(tmp)
    # Ondertitels
    def ts(s):
        return f"{int(s // 3600):02d}:{int(s % 3600 // 60):02d}:{int(s % 60):02d},{int(s * 1000 % 1000):03d}"
    srt = UIT / f"{naam}.srt"
    srt.write_text("\n".join(f"{i}\n{ts(s)} --> {ts(s + d)}\n{t}\n" for i, (s, d, t, _) in enumerate(stappen, 1)))
    # WebVTT voor de video-speler in de app
    (UIT / f"{naam}.vtt").write_text("WEBVTT\n\n" + "\n".join(
        f"{ts(s).replace(',', '.')} --> {ts(s + d).replace(',', '.')}\n{t}\n" for s, d, t, _ in stappen))
    # Audio: elke zin op zijn starttijd
    args, filters = [], []
    for i, (s, _, _, wav) in enumerate(stappen):
        args += ["-i", str(wav)]
        filters.append(f"[{i}:a]adelay={int(s * 1000)}|{int(s * 1000)}[a{i}]")
    mix = "".join(f"[a{i}]" for i in range(len(stappen)))
    filters.append(f"{mix}amix=inputs={len(stappen)}:normalize=0[uit]")
    audio = tmp / f"{naam}.m4a"
    subprocess.run([FFMPEG, "-y", "-loglevel", "error", *args, "-filter_complex", ";".join(filters), "-map", "[uit]",
                    "-c:a", "aac", "-b:a", "128k", str(audio)], check=True)
    mp4 = UIT / f"{naam}.mp4"
    subprocess.run([FFMPEG, "-y", "-loglevel", "error", "-i", str(webm), "-i", str(audio), "-i", str(srt),
                    "-map", "0:v", "-map", "1:a", "-map", "2:s",
                    "-vf", f"fade=t=in:st=0:d=0.8,fade=t=out:st={duur - 1.2:.2f}:d=1.2",
                    "-af", f"afade=t=in:st=0:d=0.5,afade=t=out:st={duur - 1.2:.2f}:d=1.2", "-c:v", "libx264", "-preset", "slow", "-crf", "26",
                    "-pix_fmt", "yuv420p", "-c:a", "aac", "-b:a", "128k", "-c:s", "mov_text", "-metadata:s:s:0", "language=dut",
                    "-movflags", "+faststart", "-shortest", str(mp4)], check=True)
    return mp4


def main():
    gekozen = sys.argv[1:] or list(ROLLEN)
    logo = base64.b64encode((ROOT / "static" / "img" / "logo.png").read_bytes()).decode()
    with tempfile.TemporaryDirectory() as tmp:
        proc = build.start_server(tmp)
        try:
            with sync_playwright() as pw:
                browser = pw.chromium.launch()
                for naam in gekozen:
                    script, toestel = ROLLEN[naam]
                    vid = Path(tmp) / naam
                    # Opnamegrootte = schermgrootte: Playwright neemt op in CSS-pixels; een groter frame geeft grijze randen.
                    ctx = browser.new_context(viewport=toestel, record_video_dir=str(vid), record_video_size=toestel,
                                              color_scheme="light", service_workers="block", locale="nl-BE")
                    ctx.add_init_script(INIT)
                    page = ctx.new_page()
                    o = Opname(page, tmp)
                    script(o, logo)
                    page.wait_for_timeout(800)
                    webm = page.video.path()
                    ctx.close()
                    duur = time.monotonic() - o.t0
                    mp4 = monteer(naam, webm, o.stappen, tmp, duur)
                    print(f"  {mp4.name}: {duur / 60:.1f} min, {mp4.stat().st_size / 1e6:.1f} MB")
                browser.close()
        finally:
            proc.terminate()


if __name__ == "__main__":
    main()
