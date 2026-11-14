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

Vereisten: WordPress 6.2+, PHP 8.0+ met de extensies `zip`, `simplexml`, `dom` en `openssl`. Er zijn geen andere plugins nodig. Met The Events Calendar komen de wedstrijden ook op de kalender van de website.

## Updates vanaf GitHub

De plugin haalt nieuwe versies zelf op uit de GitHub-releases van deze repo (`includes/update.php`). Je hoeft de zip enkel de eerste keer zelf te uploaden.

- WordPress toont een nieuwe versie zoals bij elke andere plugin, onder **Dashboard → Updates** en in de pluginlijst.
- Klik in de pluginlijst bij KHZS Leden op **Automatische updates inschakelen**. Dan installeert WordPress elke nieuwe versie vanzelf.
- WordPress kijkt twee keer per dag. Wil je meteen kijken? Klik dan in de pluginlijst op **Op updates controleren**.

Een nieuwe versie uitbrengen:

1. Verhoog de versie in `khzs-leden/khzs-leden.php`, zowel op de regel `Version:` als bij `const VERSIE`.
2. Merge naar `main`.
3. `git tag plugin-v2.1.0 && git push origin plugin-v2.1.0`

De GitHub Action `.github/workflows/plugin-release.yml` controleert dan of de versies kloppen, bouwt `khzs-leden.zip` met `bouw.sh` en maakt de release. Enkel releases met een tag `plugin-v…` en de bijlage `khzs-leden.zip` tellen mee. Concepten en pre-releases worden genegeerd.

## Rollen

Een account kan **meerdere rollen** hebben (bv. ouder én trainer); de rechten tellen samen.

| App-rol | Kan |
|---|---|
| Zwemmer | zijn of haar zwemmers inschrijven |
| Ouder | hetzelfde als zwemmer: de eigen zwemmers inschrijven |
| Trainer competitie | inschrijvingen nakijken, uitslagen; in *Trainingen* aanduiden welke trainingen hij of zij geeft, afmelden, overnemen, eigen uren bekijken; alle ploegen zien (sleutel `trainer`) |
| Trainer zwemschool | enkel *Trainingen* (schema, aanwezigheid, uren, kalender) en de ploegen van de zwemschool; geen wedstrijden, nakijken of uitslagen (sleutel `trainer_zwemschool`) |
| Bestuurder | leden en rollen beheren: rolaanvragen goedkeuren of afkeuren, rollen toekennen en afnemen (geen wedstrijden of inschrijvingen) |
| Sportsecretaris | wedstrijden, export, rollen, trainingsrooster beheren, de planning van anderen corrigeren |
| Beheerder | alles, plus backup (WordPress-beheerders zijn dit altijd) |

**Rollen aanvragen**: bij het registreren of later via *Mijn rollen* (`/leden/rollen/`). Zwemmer en ouder krijg je meteen; trainer, sportsecretaris en bestuurder worden een aanvraag. Bestuurders, sportsecretarissen en beheerders zien de aanvragen bovenaan *Gebruikers* en kunnen goedkeuren, afkeuren (met reden) of een andere rol geven. Het lid krijgt een melding in de app en een pushmelding. Enkel een beheerder kan beheerders aanstellen of wijzigen.

**Twee soorten trainers.** Een trainer van de zwemschool is niet met wedstrijden bezig. Wie enkel die rol heeft (geen zwemmer, ouder of wedstrijdstaf), ziet geen menu *Wedstrijden* en start bij *Trainingen* (`zonder_wedstrijden()` in `includes/kern.php`). Wie beide doet, krijgt beide rollen. Bestaande trainers zijn trainer van de competitie: de sleutel `trainer` bleef dezelfde, enkel de naam veranderde. Wedstrijdrechten gaan via `STAF` (zonder zwemschool), de trainersmodule via `TR_STAF` (beide soorten).

**Ploegen** (A-groep, B-groep, masters…) beheer je in de app onder *Ploegen* (`/leden/ploegen/`): bestuurders, sportsecretarissen en beheerders maken ploegen aan en duiden de trainers aan. De zwemmer of ouder kiest een ploeg bij de zwemmer; een trainer van die ploeg of het bestuur keurt goed of af. Een ploeg is van de **competitie** of van de **zwemschool** (kolom `soort`). Trainers van de competitie, bestuur en beheer zien alle ploegen; een trainer van de zwemschool ziet de ploegen van de zwemschool en de ploegen die hij of zij traint; leden enkel de ploegen van hun zwemmers. Tabellen: `khzs_ploegen`, `khzs_ploeg_trainers`, `khzs_ploegleden`.

**Registratie in stappen**: het formulier vraagt enkel het account. Daarna volgt `/leden/welkom/`: zwem je zelf, heb je kinderen die zwemmen (met ploeg of koppelcode), help je mee bij de club. Een nieuw account start zonder rol; zwemmer of ouder volgt uit de antwoorden.

De rollen staan in de user meta `khzs_rollen` (komma-gescheiden); `khzs_rol` houdt de hoofdrol bij. Een account met enkel `khzs_rol` (van vóór de rollen) houdt die rol. Aanvragen staan in de tabel `khzs_rolaanvragen`.

**Penningmeester** staat los van de app-rol, want het kan ook een ouder zijn. Een beheerder duidt die aan bij *Gebruikers* in de app of op het WordPress-profiel. De penningmeester ziet **Verloning**: de tarieven en het overzicht per maand.

**Bestuur** in de module Trainers: de penningmeester, de beheerder en (zodra de rollen er zijn) de bestuurder (functie `tr_is_bestuur()` in `modules/trainers/regels.php`). Het bestuur en het sportsecretariaat zien en wijzigen de schema's, aanwezigheden en uren van alle trainers. Wie in het bestuur zit en ook traint (en eventueel zelf zwemt), heeft bij *Trainingen* zijn of haar eigen trainingen én de knop *Overzicht trainers*. Voor de **eigen** trainingen gelden altijd de regels van een trainer: na uitbetaling corrigeert een ander lid van het bestuur of het secretariaat, zodat niemand zijn eigen uren achteraf goedkeurt.

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

- **Rooster** (*Trainingen → Trainingen beheren*, door het bestuur en het sportsecretariaat): vaste trainingen per week met naam, dag, uur en plaats, een start- en einddatum, en optioneel de **ploegen** waarvoor de training is (een training zonder ploeg is voor iedereen).
- **Seizoen**: op dezelfde pagina een begin en einde per seizoen (bv. 1/9/2026 – 30/6/2027).
  - Buiten een seizoen zijn er geen trainingen: ze staan niet in de planning of bij *Mijn trainingen*, tellen niet voor de uren en de trainers krijgen geen vraag *Was je aanwezig?*. De planning toont *Buiten het seizoen*.
  - Zonder seizoen lopen de trainingen het hele jaar door. Dagen vóór het begin van het eerste seizoen blijven zoals ze waren, zodat vroegere (en uitbetaalde) uren niet veranderen.
  - Is het laatste seizoen voorbij, dan waarschuwt de beheerpagina om het volgende toe te voegen. Seizoenen mogen niet overlappen.
- **Verlof en afgelast** (vakantie, verlof, zwembad dicht of een andere reden, met een periode en een optionele reden): voor alle trainingen, voor **een ploeg** (al haar trainingen) of voor één training.
  - Een training voor meerdere ploegen gaat door zolang één van die ploegen traint; ze valt pas weg als al haar ploegen verlof hebben.
  - Afgelaste trainingen blijven zichtbaar in de planning (met de reden), tellen nooit mee voor de uren en krijgen geen vraag *Was je aanwezig?*.
  - De trainers van de komende trainingen die daardoor wegvallen, krijgen één pushmelding *Training afgelast*; wordt het verlof verwijderd, dan krijgen ze *Training gaat toch door*. Uit te zetten bij *Meldingen*.
- **Eigen schema**: elke trainer duidt in *Trainingen* zelf aan welke trainingen hij of zij normaal geeft, met een datum *vanaf* (starten) of *niet meer vanaf* (stoppen).
  - Een datum in het verleden kan tot het begin van vorige maand, maar niet in een maand die voor die trainer al uitbetaald is. Die voorbije trainingen moet de trainer daarna nog bevestigen.
  - Het bestuur en het secretariaat kunnen het schema van elke trainer aanpassen, zonder grens in de tijd.
- **Kalender** (*Trainingen → Kalender*, `/leden/trainers/kalender/`): de trainingen per **dag**, **week** (standaard) of **maand**, met wie ingepland is, wie zich afmeldde (doorstreept), wie vervangt (*Lies voor Tom*), *vervanger gezocht*, afgelaste trainingen en de bevestiging van aanwezigheid (✓ aanwezig, ✗ niet aanwezig, ? niet bevestigd).
  - Een trainer ziet standaard de eigen trainingen en kan naar *Alle trainingen*. Het bestuur en het secretariaat zien standaard alles en filteren op een trainer of een ploeg.
  - Een training openen (tik op het blokje) gaat naar de trainingspagina om te bevestigen, af te melden of aan te passen. In de dagweergave staan die knoppen meteen bij de training.
  - Op de gsm staan de dagen van de week onder elkaar (lege dagen verborgen) en toont de maand per dag gekleurde streepjes; tik op een dag voor de dagweergave. Geen externe bibliotheken: eigen HTML en CSS (`views/kalender.php`, `views/_stijl.php`).
- **Aanwezigheid van zwemmers** (*wie was er?*): een trainer duidt bij een training aan welke zwemmers er waren. Zwemmers en ouders bevestigen zelf niets, krijgen geen vraag of melding en zien hun aanwezigheden niet in de app (wel in *Mijn gegevens*, voor de AVG).
  - De lijst komt uit de ploegen die aan de training gekoppeld zijn (*Trainingen beheren*). Een training zonder ploeg heeft geen lijst.
  - Aanduiden mag **eender welke** trainer van de competitie voor de ploegen van de competitie, eender welke trainer van de zwemschool voor die van de zwemschool, en het bestuur, het secretariaat en de beheerder voor alles. Niet enkel wie op de training ingepland staat. Bij een training voor ploegen van beide soorten ziet elke trainer enkel de eigen soort.
  - Snel op de gsm: op de trainingspagina (*Zwemmers*) staat iedereen aangevinkt. Vink af wie er niet was en tik *Bewaar*. Er zijn ook knoppen *Iedereen aanwezig* en *Niemand*. Dat kan vanaf de dag van de training, niet op een afgelaste training. Opnieuw bewaren past de lijst aan; *Aanduiding wissen* zet ze terug op niet aangeduid.
  - In *Mijn trainingen* staat *Zwemmers aanduiden* met de eigen trainingen van de voorbije week waarop dat nog niet gebeurde. De lijsten en de kalender tonen per training *🏊 12/15* of *Zwemmers aanduiden*.
  - **Overzicht** (*Trainingen → 🏊 Zwemmers*, `/leden/trainers/zwemmers/`): per ploeg het aantal aangeduide trainingen en de gemiddelde aanwezigheid, en per zwemmer aanwezig, afwezig en een percentage, over een periode (standaard het lopende seizoen) en voor één of alle ploegen. Enkel aangeduide trainingen tellen mee. Met een **Excel-export** (bladen *Per zwemmer*, *Per ploeg* en *Detail*).
- **Afmelden en vervangen** (één specifieke training):
  - Een trainer die niet kan, tikt *Kan niet* en geeft eventueel een reden. Collega's krijgen een pushmelding en zien de training bij *Vervanger gezocht*.
  - Wie *Ik neem over* tikt, wordt vervanger. De afgemelde trainer krijgt daarvan een melding.
  - Wie wil, kan ook *meehelpen* als extra trainer.
- **Was je aanwezig?**: na elke training krijgt elke ingeplande trainer een pushmelding met de knoppen *✓ Ja* en *✗ Nee*.
  - Eén tik op een knop volstaat; de app hoeft niet open. Tik je op de melding zelf (of ondersteunt het toestel geen knoppen, zoals iPhone), dan opent een eenvoudig scherm met twee grote knoppen. Dat werkt ook zonder aangemeld te zijn: de melding bevat een persoonlijke sleutel die enkel voor die trainer en die training geldt, 60 dagen lang.
  - Geen antwoord? Dan blijft de training op *niet bevestigd* en volgt er één herinnering, de volgende ochtend vanaf 8 uur (minstens 12 uur na de vraag, nooit 's nachts).
  - In *Trainingen* staat bovenaan *Was je aanwezig?* met de open trainingen (en *Ik was overal aanwezig*), en onderaan de afgelopen 4 weken, waar de trainer een antwoord nog kan wijzigen.
  - Achteraf erbij komen kan ook: op een voorbije training betekent *Ik was er ook*, *Ik heb overgenomen* of *Ik was er toch* meteen *aanwezig*.
  - Een trainer wijzigt zijn of haar eigen voorbije trainingen zolang die maand voor hem of haar niet als uitbetaald gemarkeerd is. Daarna kan enkel het bestuur nog corrigeren.
  - De vraag komt van een geplande taak die elk kwartier draait (WP-Cron). Een trainer kan ze uitzetten bij *Meldingen*.
- **Uren**: enkel trainingen tot en met vandaag die als **aanwezig** bevestigd zijn, tellen mee; afgelaste nooit. *Niet aanwezig* telt niet. *Niet bevestigd* telt (nog) niet mee, maar staat apart in alle overzichten en in de Excel, tot de trainer of het bestuur ze bevestigt. Trainers zien hun eigen uren en vergoeding.
  - Bij een installatie die al trainingen had vóór deze versie, tellen de trainingen vóór de dag van de update mee zonder bevestiging (optie `khzs_tr_bevestigen_vanaf`).
- **Overzicht trainers** (bestuur en secretariaat, *Trainingen → Overzicht trainers*): per maand per trainer het schema, het aantal trainingen aanwezig, niet aanwezig en niet bevestigd, en de uren. Per trainer:
  - alle trainingen van de maand met wie bevestigde en wanneer, met *Aanwezig* / *Niet aanwezig* per training en *Alle niet bevestigde als aanwezig bevestigen*
  - het schema (vaste trainingen toevoegen of stoppen vanaf een datum)
  - de wijzigingen die het bestuur en het secretariaat al deden
- **Melding bij elke aanpassing door het bestuur**: verandert iemand anders iets aan de planning of aanwezigheid van een trainer, dan krijgt die trainer een pushmelding *Je planning is aangepast*. In de app staat bovenaan *Aangepast door het bestuur* met wat er veranderde, door wie en wanneer, en *Wijzigingen aan mijn planning* toont alles.
- **Verloning** (penningmeester):
  - Een tarief per trainer, per uur of per training, met een datum *geldig vanaf*. Een nieuw tarief verandert niets aan vroegere trainingen.
  - Een overzicht per maand met trainingen, uren en bedrag per trainer, met detail per training.
  - Per trainer en maand *✓ Uitbetaald* markeren. Wijzigt er daarna nog iets (bv. een correctie), dan toont het overzicht *gewijzigd na uitbetaling*.
  - Export naar Excel: een blad per trainer en een blad met detail.

De loonberekening zelf (bv. wettelijke plafonds van vrijwilligersvergoedingen of het statuut verenigingswerk) gebeurt niet in de plugin. De export is bedoeld voor de boekhouding of het sociaal secretariaat.

## Websitekalender (The Events Calendar)

Staat op de site de plugin **The Events Calendar**, dan zet de app de wedstrijden automatisch op de evenementenkalender en houdt ze bij.
Zonder die plugin gebeurt er niets. In de demo nooit.

- **Instellen**: in de app via *Wedstrijden → 📅 Websitekalender* (sportsecretariaat en beheerders). In wp-admin (*Instellingen → KHZS Leden*) staan de stand en de knop *Nu alles synchroniseren*.
  - synchronisatie aan of uit (standaard **uit**)
  - welke statussen op de website komen (standaard open, gesloten en doorgestuurd; concept niet)
  - de evenementcategorie (standaard *Wedstrijden*; wordt aangemaakt als ze niet bestaat)
  - nieuwe evenementen meteen publiceren of als concept, zodat de webmaster ze eerst nakijkt
  - of de *info voor zwemmers* mee op de website mag (standaard niet)
  - de knop **Nu alles synchroniseren**
- **Wanneer**: bij een nieuwe Lenex of een nieuwe versie, bij het bewaren van de instellingen of de info van een wedstrijd, bij een bijlage die bijkomt, verdwijnt of publiek wordt, en bij het verwijderen.
- **Wat gaat mee** (enkel publieke informatie):
  - de naam, de sessies met datum, inzwemmen en start, het zwembad als locatie (met adres uit de Lenex), de badlengte en de organisator;
  - het begin is de start van de eerste sessie; het einde is het einduur uit de Lenex, of anders de start van de laatste sessie + 3 uur;
  - bijlagen die je bij de wedstrijd als **publiek** (🌐) aanduidt; die kan iedereen dan zonder aanmelden downloaden;
  - een link *Inschrijven via de ledenapp*.
  - **Nooit**: inschrijvingen, zwemmers, de deadlines van zwemmers, trainers of het secretariaat, of kosten.
- **Met de hand gewijzigd op de website**: de app overschrijft dat niet. Het evenement verschijnt bij *Websitekalender* met de keuze *Overschrijven met de app* of *Niet meer bijwerken*.
- **Niet meer publiek of verwijderd**:
  - een gepubliceerd evenement dat nog moet komen, krijgt de status *Geannuleerd*;
  - een evenement dat nooit gepubliceerd was, gaat naar de prullenbak;
  - een voorbij evenement blijft staan.
  - Komt de wedstrijd terug op een publieke status, dan wordt hetzelfde evenement opnieuw actief.
- Bestaande locaties en organisatoren worden nooit aangepast, enkel gekoppeld op naam.

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
  Accounts met precies **één rol**, met als wachtwoord telkens de gebruikersnaam:
  - `trainer` / `trainer`: trainer van Competitie 2
  - `zwemmer` / `zwemmer`: een zwemster die zelf haar account heeft
  - `ouder` / `ouder`: ouder van twee kinderen
  - `bestuur` / `bestuur`: bestuurslid. Zolang er geen rollenmodule is (PR #7), is dat een penningmeester (verloning); daarna de rol bestuurder.

  Andere demo-accounts, met wachtwoord `demo`:
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

## Privacy (AVG)

De app heeft een privacyverklaring op `/leden/privacy/`, voor iedereen leesbaar (ook zonder aanmelden) en gelinkt vanaf *Aanmelden*, *Profiel aanmaken* en *Uitleg*.
- **Gegevens van de club**: vul ze in onder *Instellingen → KHZS Leden → Privacyverklaring (AVG)*: verwerkingsverantwoordelijke, adres, ondernemingsnummer, contactadres en eventueel een aanvullende tekst.
- **Sjabloon**: de tekst (`views/privacy.php`) is een sjabloon in duidelijk Vlaams. **Laat hem juridisch nakijken** voor je de app openstelt.
- **Versie**: verander je de inhoud, zet dan een nieuwe *Versie*. Leden zien dan een melding om de verklaring opnieuw te lezen en te bevestigen.
- **Registratie**: een vinkje voor de privacyverklaring (datum en versie in de user meta `khzs_privacy_akkoord` en `khzs_privacy_versie`). Wie een zwemmer jonger dan 18 jaar toevoegt, bevestigt de toestemming van een ouder of voogd (`toestemming_op` en `toestemming_door` bij de zwemmer). Voor bestaande minderjarige zwemmers vraagt de zwemmerpagina die toestemming achteraf.
- **Mijn gegevens** (`/leden/privacy/mijn-gegevens/`, link bij *Meldingen*):
  - alles downloaden als JSON (account, rollen, voorkeuren, zwemmers, ploegen, tijden, inschrijvingen, meldingen, en voor trainers hun trainingen, uren en vergoedingen);
  - het account verwijderen, met het wachtwoord en het woord VERWIJDER. Een gedeelde zwemmer blijft bij het andere account; de andere zwemmers verdwijnen mee.
  - **Bewaarplicht**: gaf iemand trainingen of kreeg die een vergoeding, dan wordt het account *afgesloten* in plaats van verwijderd. Aanmelden kan niet meer, e-mail en andere gegevens verdwijnen, enkel de naam blijft bij de uren en uitbetalingen. Na 10 jaar na het boekjaar (`BOEKHOUDING_JAREN`) wist de opkuis alles.
  - Beheerders van de website, auteurs van berichten en de demo kunnen hun account niet zelf verwijderen.
- **Exporttool van WordPress**: *Extra → Persoonsgegevens exporteren* neemt de gegevens van de app mee.
- **Opkuis** (dagelijks om 03:00, WP-Cron `khzs_opkuis`):
  - wijzigingen aan inschrijvingen (meldingen) na 365 dagen;
  - behandelde rolaanvragen en afgekeurde ploegaanvragen na 365 dagen;
  - pushabonnementen van accounts die 365 dagen niet aanmeldden;
  - verlopen koppelcodes;
  - het logboek van verstuurde herinneringen na 365 dagen;
  - afgesloten accounts na hun bewaartermijn.
- **Cookies**: de app zet enkel strikt noodzakelijke cookies (aanmelden van WordPress, `khzs_f` voor een melding na afmelden, in de demo `khzs_demo_sessie`) en gebruikt de lokale opslag voor het thema en voor ‘Niet nu’ bij de installatiemelding. Er zijn geen trackers of statistieken, dus er is geen cookiebanner nodig voor de app. **De rest van de website** (thema, andere plugins, bv. statistieken of ingesloten video's) kan wel cookies zetten waarvoor toestemming nodig is: dat valt buiten de plugin.

## Verschillen met de Django-versie

- Accounts en "wachtwoord vergeten" lopen via WordPress.
- Geplande taken (herinneringen 08:00, backup 02:30, de vraag *Was je aanwezig?* elk kwartier) gebruiken WP-Cron. Op een rustige site stel je best een echte cron in die `wp-cron.php` oproept.
- De backup bevat de gegevens van de app, niet de WordPress-accounts zelf.
- Je werkt de plugin bij door een nieuwe zip te uploaden; de automatische updates via git in `deploy/` gelden niet.

## Opbouw

```
khzs-leden/
  khzs-leden.php                    opstart, laadt kern en actieve modules, cron
  includes/                   kern
    kern.php                  rollen, penningmeester, modules, datums, bedragen
    rollen.php                rolaanvragen: aanvragen, goedkeuren, afkeuren, andere rol; Mijn rollen
    ploegen.php               ploegen, ploegaanvragen, wie hoort bij welke ploeg (basis voor communicatie)
    db.php                    gedeelde tabellen (zwemmers, push), privémap, hulpfuncties
    app.php                   routes, menu, weergave, meldingen, toegang
    kern-acties.php           aanmelden, gebruikers, meldingen, backup, PWA
    avg.php                   privacyverklaring, akkoord, mijn gegevens, account verwijderen, opkuis
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
