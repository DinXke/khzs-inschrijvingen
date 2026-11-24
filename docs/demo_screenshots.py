"""Screenshots van de schermen die enkel in de WordPress-plugin bestaan (berichten, trainingen, aanwezigheid, verloning).

Ze komen van een lokale demo-installatie van de plugin (wordpress/demo/, met Docker), niet van de publieke demo:
    cd wordpress/demo && cp .env.voorbeeld .env   # DEMO_URL=http://localhost:8095/leden-demo, DEMO_POORT=8095
    ./installeer.sh
    .venv/bin/python docs/demo_screenshots.py      # Windows: .venv/Scripts/python docs/demo_screenshots.py
Daarna docs/build.py voor de PDF's. De bestanden heten wp-<naam>.png in docs/screenshots/.
De demo-accounts hebben als wachtwoord hun gebruikersnaam (demo heeft alle rollen).
"""
import os
from pathlib import Path

from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parent.parent
SHOTS = ROOT / "docs" / "screenshots"
URL = os.environ.get("KHZS_DEMO_URL", "http://localhost:8095/leden-demo").rstrip("/")
GSM = {"width": 390, "height": 844}
PC = {"width": 1280, "height": 860}

# (bestandsnaam, account, pad, toestel, actie vóór de screenshot)
SCREENSHOTS = [
    ("wp-berichten", "demo", "berichten/", GSM, None),
    ("wp-kanaal", "demo", "berichten/kanaal/9/", GSM, "onderaan"),
    ("wp-hulpvraag", "demo", "berichten/aankondiging/3/", GSM, None),
    ("wp-trainingen", "trainer", "trainers/", GSM, None),
    ("wp-zwemmers-aanduiden", "trainer", "trainers/", GSM, "aanduiden"),
    ("wp-kalender", "demo", "trainers/kalender/?weergave=week&wie=alle", PC, None),
    ("wp-zwemmers-statistiek", "demo", "trainers/zwemmers/", PC, None),
    ("wp-aanwezigheid-zwemmer", "ouder", "zwemmers/", GSM, "statistiek"),
    ("wp-overzicht-trainers", "bestuur", "trainers/overzicht/", PC, None),
    ("wp-trainingen-beheren", "demo", "trainers/beheer/", PC, "verlof"),
    ("wp-verloning", "demo", "trainers/uren/", PC, None),
]


def aanmelden(browser, wie, toestel):
    ctx = browser.new_context(viewport=toestel, device_scale_factor=2, color_scheme="light",
                              service_workers="block", locale="nl-BE")
    p = ctx.new_page()
    p.goto(URL + "/aanmelden/")
    p.fill("#id_username", wie)
    p.fill("#id_password", wie)
    p.click("form button")
    p.wait_for_load_state("networkidle")
    if "/aanmelden" in p.url:
        raise SystemExit(f"aanmelden als {wie} mislukt")
    p.close()
    return ctx


def main():
    SHOTS.mkdir(parents=True, exist_ok=True)
    with sync_playwright() as pw:
        browser = pw.chromium.launch()
        contexten = {}
        for naam, wie, pad, toestel, actie in SCREENSHOTS:
            sleutel = (wie, toestel["width"])
            if sleutel not in contexten:
                contexten[sleutel] = aanmelden(browser, wie, toestel)
            page = contexten[sleutel].new_page()
            page.goto(f"{URL}/{pad}")
            if actie == "aanduiden":
                href = page.locator("a:has-text('Wie was er')").first.get_attribute("href")
                page.goto(href)
                page.evaluate("[...document.querySelectorAll('h2')].find(h => h.textContent.includes('Zwemmers'))"
                              "?.scrollIntoView({block: 'start'}); window.scrollBy(0, -70)")
            if actie == "statistiek":
                page.locator("main a[href*='/zwemmers/']").filter(has_text="Finn").first.click()
                page.wait_for_load_state("networkidle")
                page.goto(page.locator("a[href*='/aanwezigheid/']").first.get_attribute("href"))
            if actie == "onderaan":
                page.evaluate("window.scrollTo(0, document.body.scrollHeight)")
            if actie == "verlof":
                page.evaluate("[...document.querySelectorAll('h2')].find(h => /verlof|afgelast/i.test(h.textContent))"
                              "?.scrollIntoView({block: 'start'}); window.scrollBy(0, -120)")
            # Melding om de privacyverklaring te lezen en andere meldingen horen niet in de handleiding.
            # De demobalk en "Demo" in de titel ook niet: de handleiding gaat over de echte app.
            page.evaluate("document.querySelectorAll('.melding.success, .melding.info, .demo-balk').forEach(e => e.remove());"
                          "document.querySelectorAll('.logo-tekst').forEach(e => e.textContent = 'KHZS Leden')")
            page.wait_for_timeout(250)
            page.screenshot(path=SHOTS / f"{naam}.png")
            page.close()
            print("  screenshot", naam)
        for ctx in contexten.values():
            ctx.close()
        browser.close()


if __name__ == "__main__":
    main()
