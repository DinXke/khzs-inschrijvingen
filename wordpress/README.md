# KHZS: WordPress-plugin (clubapp met modules)

Eén plugin **KHZS** met een gedeelde kern en modules:

| Module | Wat |
|---|---|
| **Inschrijvingen** | De inschrijvingsapp, die hetzelfde werkt als de Django-versie: Lenex-import en -export, inschrijven, nakijken, finaliseren, Excel, uitslagen |
| **Trainers** | Trainingsrooster, wie geeft welke training, afmelden en vervangen, gepresteerde uren, tarieven en verloning |

De **kern** bevat wat alle modules delen:
- accounts (de gewone WordPress-gebruikers) en rollen
- het menu en de opmaak
- pushmeldingen, de PWA en de installatiemelding
- backup en instellingen

De app staat op `https://jouwsite.be/club/`. Het oude adres `/inschrijvingen/…` verwijst automatisch door.

## Installeren

1. `./bouw.sh` maakt `khzs.zip`. Met `--met-uitleg` komen de filmpjes en PDF's uit `../docs` mee, maar dan is het bestand ± 40 MB.
2. Upload de zip in WordPress via **Plugins → Nieuwe plugin → Plugin uploaden** en activeer de plugin.
3. Zet de **permalinks** op iets anders dan "Eenvoudig".
4. Stel de plugin in via **Instellingen → KHZS**:
   - welke modules aanstaan
   - clubnaam en clubcode
   - het adres van de app
   - of zelf registreren mag
   - de installatiemelding

Vereisten: WordPress 6.2+, PHP 8.0+ met de extensies `zip`, `simplexml`, `dom` en `openssl`. Er zijn geen andere plugins nodig.

## Rollen

| App-rol | Kan |
|---|---|
| Zwemmer | zijn of haar zwemmers inschrijven (iedereen met een account) |
| Trainer | inschrijvingen nakijken; in *Trainingen* aanduiden welke trainingen hij of zij geeft, afmelden, overnemen, eigen uren bekijken |
| Sportsecretaris | wedstrijden, export, rollen, trainingsrooster beheren, de planning van anderen corrigeren |
| Beheerder | alles, plus backup (WordPress-beheerders zijn dit altijd) |

**Penningmeester** staat los van de app-rol, want het kan ook een ouder zijn. Een beheerder duidt die aan bij *Gebruikers* in de app of op het WordPress-profiel. De penningmeester ziet **Verloning**: de tarieven en het overzicht per maand.

## Zelf zwemmer

Ook volwassenen die zelf zwemmen (bv. masters) kunnen zichzelf toevoegen:
- **Bij registratie**: het vinkje *Ik zwem zelf* vult het eerste zwemmerblok met je eigen voor- en achternaam.
- **Achteraf**: met *+ Mezelf* bij *Mijn zwemmers*. Die knop verdwijnt zodra je jezelf hebt toegevoegd.

## Zwemmer delen met meerdere accounts

Een zwemmer kan bij meerdere accounts horen, bv. bij gescheiden ouders.
- Een ouder maakt bij de zwemmer een **koppelcode** aan (*Ouders & accounts → Koppelcode maken*). Die code is 7 dagen geldig.
- De andere ouder vult de code in bij *Mijn zwemmers → Zwemmer koppelen*, of meteen bij het aanmaken van een profiel. Dan hoeft die ouder het kind niet opnieuw toe te voegen.
- Beide accounts zien dezelfde inschrijvingen en krijgen dezelfde meldingen.
- Iedereen kan zichzelf loskoppelen. Het eerste account en de staf kunnen ook anderen loskoppelen.
- De staf kan een account ook rechtstreeks koppelen via het e-mailadres.
- Wordt een account verwijderd, dan blijft een gedeelde zwemmer bij de andere ouder.

## Module Trainers

- **Rooster** (*Trainingen → Trainingen beheren*, door het sportsecretariaat): vaste trainingen per week met naam, dag, uur en plaats, en een start- en einddatum. Afgelaste periodes (vakantie, zwembad dicht) voor één training of voor alle trainingen.
- **Vaste trainers**: elke trainer duidt in *Trainingen* zelf aan welke trainingen hij of zij normaal geeft. Het secretariaat kan dat ook doen, met een begin- en einddatum.
- **Afmelden en vervangen**:
  - Een trainer die niet kan, tikt *Kan niet* en geeft eventueel een reden. Collega's krijgen een pushmelding en zien de training bij *Vervanger gezocht*.
  - Wie *Ik neem over* tikt, wordt vervanger. De afgemelde trainer krijgt daarvan een melding.
  - Wie wil, kan ook *meehelpen* als extra trainer.
- **Uren**: elke training tot en met vandaag waarop iemand aanwezig was, telt mee, behalve afgelaste. Trainers zien hun eigen uren en vergoeding.
- **Verloning** (penningmeester):
  - Een tarief per trainer, per uur of per training, met een datum *geldig vanaf*. Een nieuw tarief verandert niets aan vroegere trainingen.
  - Een overzicht per maand met trainingen, uren en bedrag per trainer, met detail per training.
  - Per trainer en maand *✓ Uitbetaald* markeren. Wijzigt er daarna nog iets (bv. een correctie), dan toont het overzicht *gewijzigd na uitbetaling*.
  - Export naar Excel: een blad per trainer en een blad met detail.

De loonberekening zelf (bv. wettelijke plafonds van vrijwilligersvergoedingen of het statuut verenigingswerk) gebeurt niet in de plugin. De export is bedoeld voor de boekhouding of het sociaal secretariaat.

## App installeren (PWA)

Bezoekers op gsm zien op de website en in de app een melding *"Installeer de HZS-app"*.
- In de app zelf opent *Installeren* het installatievenster van de browser.
- Op de rest van de website gaat de knop naar `/club/installeer/`. Daar installeer je met één tik op Android; op iPhone legt de pagina stap voor stap uit hoe het moet.
- Wie op *Niet nu* tikt, ziet de melding 30 dagen niet.

## Overstappen vanuit de Django-versie

```bash
python manage.py export_wordpress --uit hzs-backup-export.zip
```

Daarna in de app (als WordPress-beheerder): **Backup → Backup terugzetten**, kies de zip en typ `HERSTEL`.
- Alle gegevens, bestanden, rollen en voorkeuren komen mee.
- Ontbrekende accounts worden aangemaakt, en **bestaande wachtwoorden blijven werken**.
- Toestellen moeten pushmeldingen opnieuw aanzetten.

## Verschillen met de Django-versie

- Accounts en "wachtwoord vergeten" lopen via WordPress.
- Geplande taken (herinneringen 08:00, backup 02:30) gebruiken WP-Cron. Op een rustige site stel je best een echte cron in die `wp-cron.php` oproept.
- De backup bevat de gegevens van de app, niet de WordPress-accounts zelf.
- Je werkt de plugin bij door een nieuwe zip te uploaden; de automatische updates via git in `deploy/` gelden niet.

## Opbouw

```
khzs/
  khzs.php                    opstart, laadt kern en actieve modules, cron
  includes/                   kern
    kern.php                  rollen, penningmeester, modules, datums, bedragen
    db.php                    gedeelde tabellen (zwemmers, push), privémap, hulpfuncties
    app.php                   routes, menu, weergave, meldingen, toegang
    kern-acties.php           aanmelden, gebruikers, meldingen, backup, PWA
    push.php  backup.php  xlsx.php  velden.php  admin.php  pwa.php
  views/                      sjablonen van de kern (_layout, aanmelden, gebruikers…)
  modules/<id>/
    db.php                    tabellen (altijd geladen, zodat gegevens bewaard blijven)
    module.php                routes, menu, taken (enkel als de module aanstaat)
    …                         eigen code en views/
  assets/                     app.css en iconen
```

Een nieuwe module voeg je toe met een map `modules/<id>/` (`db.php` met `schema_<id>()`, en `module.php` die via de filters `khzs_routes`, `khzs_menu`, `khzs_tabellen`, `khzs_voorkeuren` en `khzs_taken` inhaakt) en een regel in `modules()` in `includes/kern.php`.

## Testen

`tests/test_e2e.py` doet echte HTTP-verzoeken naar een testsite. Op die testsite moet `tests/khzs-test-helper.php` als mu-plugin staan. **Zet die helper nooit op een echte site.**

```bash
KHZS_URL=http://localhost:8080 python3 tests/test_e2e.py
```
