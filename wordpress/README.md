# KHZS Leden: WordPress-plugin (ledenapp met modules)

Eén plugin **KHZS Leden** met een gedeelde kern en modules:

| Module | Wat |
|---|---|
| **Inschrijvingen** | De inschrijvingsapp, die hetzelfde werkt als de Django-versie: Lenex-import en -export, inschrijven, nakijken, finaliseren, Excel, uitslagen |
| **Trainers** | Trainingsrooster, wie geeft welke training, afmelden en vervangen, gepresteerde uren, tarieven en verloning |

De **kern** bevat wat alle modules delen:
- accounts (de gewone WordPress-gebruikers) en rollen
- het menu en de opmaak
- pushmeldingen, de PWA en de installatiemelding
- backup en instellingen

De app staat op `https://jouwsite.be/leden/`. De oudere adressen `/inschrijvingen/…` en `/club/…` verwijzen automatisch door.

## Installeren

1. `./bouw.sh` maakt `khzs-leden.zip`. Met `--met-uitleg` komen de filmpjes en PDF's uit `../docs` mee, maar dan is het bestand ± 40 MB.
2. Upload de zip in WordPress via **Plugins → Nieuwe plugin → Plugin uploaden** en activeer de plugin.
3. Zet de **permalinks** op iets anders dan "Eenvoudig".
4. Stel de plugin in via **Instellingen → KHZS Leden**:
   - welke modules aanstaan
   - clubnaam en clubcode
   - het adres van de app, en eventueel een eigen domein (zie *Eigen domein*)
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

Bezoekers op gsm zien op de website en in de app een melding *"Installeer de app KHZS Leden"*.
- In de app zelf opent *Installeren* het installatievenster van de browser.
- Op de rest van de website gaat de knop naar `/leden/installeer/`. Daar installeer je met één tik op Android; op iPhone legt de pagina stap voor stap uit hoe het moet.
- Wie op *Niet nu* tikt, ziet de melding 30 dagen niet.

## Eigen domein (bv. leden.khzs.be)

De app kan ook op een eigen domein draaien in plaats van op `jouwsite.be/leden/`:
- Een **Cloudflare Worker** (`leden-domein/worker.js`) stuurt `leden.khzs.be/…` door naar dezelfde WordPress.
- Het blijft dezelfde WordPress, dus met dezelfde accounts en gegevens.
- In *Instellingen → KHZS Leden → Eigen domein* vul je `https://leden.khzs.be` in. Bij het bewaren controleert de plugin of de Worker werkt.

Daarna:
- wijzen alle links, het manifest, de service worker, de pushmeldingen en de installatiemelding naar het domein;
- verwijst `jouwsite.be/leden/` door.

Hoe je dat instelt in Cloudflare, wat er gebeurt met de aanmelding (cookies) en hoe je het terugdraait, staat in [`leden-domein/README.md`](leden-domein/README.md).

## Demo op /leden-demo/

De demo staat op **https://khzs.be/leden-demo/**, maar draait op een **aparte WordPress-installatie** in het homelab, volledig los van de site van de club.
- Een Cloudflare Worker stuurt enkel dat pad door, via een tunnel.
- Alles staat in `demo/`: `docker-compose.yml`, `installeer.sh`, `update.sh` (houdt de demo automatisch bij met `main`) en `worker.js`. De uitleg staat in `demo/README.md`.
- De demo-modus staat enkel aan met `define('KHZS_DEMO', true);` in `wp-config.php`. Op een gewone site wordt ze nooit vanzelf actief.
- Met `KHZS_DEMO_ENKEL` is de hele site de demo: de app staat dan op het adres van de site zelf (behalve wp-admin).

- **Aanmelden** met `demo` / `demo`. Dat account heeft alle rollen: beheerder (dus ook sportsecretaris), trainer, penningmeester, ouder van drie kinderen en zelf zwemmer.
  Andere demo-accounts, ook met wachtwoord `demo`:
  - `katrien` (sportsecretaris)
  - `lotte`, `bram`, `tom`, `wout`, `annelies` (competitie) en `nina`, `ruben`, `elise`, `jasper` (zwemschool): trainers
  - `koen`, `an`, `pieter`, `hilde`, `sofie`, `marc`: ouders
- **Zelfde schermen en werking** als de app. Ook installeerbaar als app, maar duidelijk herkenbaar als demo: ze heet *KHZS Leden Demo*, de iconen dragen een oranje lint "DEMO" (`tools/logo/maak_demo_iconen.py`) en de scope is `/leden-demo/`. Er gaan geen pushmeldingen of mails uit.
- **Demodata** (verzonnen personen):
  - zes wedstrijden in alle stadia: twee doorgestuurd met uitslagen, één gesloten, twee open (één met bijlage) en één concept;
  - zwemmers met beste tijden, inschrijvingen, afgekeurde en geweigerde inschrijvingen, meldingen, en een zwemmer die gedeeld is met de andere ouder;
  - de **ploegen en trainingsuren van de competitie** van www.khzs.be (Competitie 3, Competitie 2, Beloften, Competitie 1 provinciaal en nationaal, Kern). Ploegen die op hetzelfde uur trainen, vormen één training;
  - de **zwemschool**: de lesmomenten van de website, met de groepen Startersgroep, Groep 1 (grijs) t.e.m. Groep 5 (groen), Recreatie en Pre-competitie 1 en 2. Welke groep wanneer zwemt, is verzonnen;
  - trainersschema's met afmeldingen, vervangers, extra hulp en een *vervanger gezocht*, afgelaste lessen in de schoolvakanties, tarieven, uren, en uitbetaalde maanden (één met *gewijzigd na uitbetaling*).
  - De datums hangen af van vandaag, dus de demo blijft actueel.
- **Terugzetten** naar de begintoestand:
  - elke nacht om 03:30 (WP-Cron);
  - na elke update van de plugin (`update.sh`, en ook vanzelf bij een nieuwe versie);
  - met de knop *Demo terugzetten* in de demo (voor beheerders);
  - met *Demo nu terugzetten* in wp-admin.
- **Ook binnen één installatie gescheiden** van gewone gegevens (extra veiligheid):
  - eigen tabellen (`wp_khzsdemo_…`) en een eigen privémap;
  - eigen accounts in `wp_khzsdemo_users` en `wp_khzsdemo_usermeta`, met id's vanaf 900000001;
  - een eigen aanmeldcookie dat enkel binnen `/leden-demo/` geldt.

  Een demo-account komt dus niet in wp-admin of in de gewone app.
- In de demo kan je **geen backup terugzetten** en **niet zelf registreren**. Zo komen er geen echte gegevens in de demo.

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
khzs-leden/
  khzs-leden.php                    opstart, laadt kern en actieve modules, cron
  includes/                   kern
    kern.php                  rollen, penningmeester, modules, datums, bedragen
    db.php                    gedeelde tabellen (zwemmers, push), privémap, hulpfuncties
    app.php                   routes, menu, weergave, meldingen, toegang
    kern-acties.php           aanmelden, gebruikers, meldingen, backup, PWA
    push.php  backup.php  xlsx.php  velden.php  admin.php  pwa.php
    demo.php                  demo op /leden-demo/: eigen tabellen, accounts en cookie, terugzetten
    demo-data.php             demodata (ploegen en uren van khzs.be, zwemschool, wedstrijden, trainers)
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

De scenario's voor het eigen domein (`EigenDomeinTest`) bootsen de Cloudflare Worker na met Node (`tests/worker_sim.mjs`). Zonder Node worden ze overgeslagen.
