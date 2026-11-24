# Overdracht: KHZS Leden (WordPress-plugin)

Dit document is de overdracht voor wie (mens of Claude Code) verder werkt aan KHZS Leden, ook op een andere pc: de stand van zaken, wat nog openstaat, en de beslissingen die je moet kennen voor je iets verandert. Lees daarna `wordpress/README.md` voor de werking, installatie en tests. De geschiedenis per pull request staat in GitHub (PR #1 tot #25).

Laatst bijgewerkt: 24/11/2026.

## Stand van zaken

- **Django** (`inschrijvingen/`) draait in productie op `inschrijvingen.khzs.be`. Elke commit op `main` komt binnen 10 minuten op de server (`deploy/auto_update.sh`, met terugrol als `update.sh` faalt). **Vraag akkoord vóór je naar `main` merget.**
- **WordPress-plugin** `wordpress/khzs-leden/`, versie **2.0.0**, alles op `main`:
  - kern: accounts (WordPress-gebruikers), meerdere rollen per account met aanvragen, ploegen, stappenplan na registratie, pushmeldingen, PWA, backup, AVG, eigen domein, automatische updates;
  - module **Inschrijvingen** (gelijk aan Django) met de **websitekalender** (The Events Calendar);
  - module **Trainers**: rooster, seizoenen, verlof per ploeg, eigen schema, *Was je aanwezig?*, kalender, uren, verloning, aanwezigheid van zwemmers met statistiek;
  - module **Communicatie**: secties, kanalen per ploeg en per wedstrijd, threads, vastpinnen, aankondigingen (ook voor meerdere kanalen) en vragen naar hulp.
- **Django volgt** de plugin voor inschrijvingen, rollen, trainer zwemschool, ploegen, stappenplan, AVG en de webshopknop. Trainers, communicatie en de websitekalender bestaan **enkel in de plugin** (beslist).
- **Tests**: Django 125 (`manage.py test inschrijvingen`), WordPress 152 end-to-end (`wordpress/tests/test_e2e.py`), alle geslaagd.
- **Demo** live op https://khzs.be/leden-demo/ (CT 139 `leden-demo` op pve01, Cloudflare Worker + tunnel). Ze volgt `main` en zet zichzelf terug (elke nacht en na elke update). Accounts `demo` (alle rollen), `ouder`, `zwemmer`, `trainer`, `bestuur`, met de gebruikersnaam als wachtwoord.
- **Documentatie** in `docs/` is bijgewerkt voor rollen, ploegen, stappenplan, AVG en de modules van de plugin (zie `docs/bronnen/technisch.md`). De instructiefilmpjes zijn verouderd.

## Open punten

**Bij de eigenaar**
1. **Plugin op www.khzs.be installeren.** Er bestaat nog geen release.
   - Eerste release maken: tag `plugin-v2.0.0` pushen (zie *Releases*), of de zip met `wordpress/bouw.sh` maken en uploaden via *Plugins → Nieuwe plugin*.
   - Permalinks controleren, *zelf registreren* eerst uitzetten, de modules kiezen, *Automatische updates inschakelen* aanzetten.
   - De gegevens van de club invullen voor de privacyverklaring (zie *AVG*).
   - Daarna de gegevens uit Django overzetten (`manage.py export_wordpress`, terugzetten via *Backup*).
   - Websitekalender: eerst met *Nieuwe evenementen: als concept* proberen, de categorie nakijken, dan *Nu alles synchroniseren*.
2. **Eigen domein** `leden.khzs.be`: niets uitgerold in Cloudflare. Stappen in `wordpress/leden-domein/README.md`.
3. **AVG**: juridische naam (vzw), adres, ondernemingsnummer en contactadres invullen (Django: `PRIVACY_*` in `/etc/khzs.env`; WordPress: *Instellingen → KHZS Leden*); de tekst laten nakijken; de bewaartermijn van 10 jaar voor de boekhouding laten bevestigen; afstemmen met een eventuele privacyverklaring op www.khzs.be; verwerkersovereenkomsten (hosting, Cloudflare) en het register van verwerkingen aanvullen. Zet een **nieuwe versie** van de privacyverklaring als je de plugin uitrolt (de aanwezigheden van zwemmers kwamen erbij), zodat de leden ze opnieuw bevestigen.
4. **Repo hernoemen** van `khzs-inschrijvingen` naar `khzs-leden` (Settings → General), daarna op de server `git -C /opt/khzs/app remote set-url origin https://github.com/DinXke/khzs-leden`. GitHub stuurt het oude adres door.
5. **Demo**: het tunneltoken van `leden-demo` vernieuwen als dat nog niet gebeurd is (het stond ooit in een chat). Het wachtwoord van wp-admin van de demo (gebruiker `beheer`) staat in `/var/log/khzs-demo-firstboot.log` op CT 139.

**Technisch**
- **Echte toestellen**: installeren op Android en iPhone, pushmeldingen (ook met knoppen en `tag`), de knoppen *Ja/Nee* bij *Was je aanwezig?* (iPhone toont geen knoppen en opent dan het scherm).
- **Instructiefilmpjes** (`docs/video.py`): tonen nog de registratie in één scherm en geen rollen, ploegen of modules. Het script verwijst nog naar `#nog-zwemmer`, dat niet meer bestaat.
- **Django, pagina van een ploeg** (`templates/ploeg.html`): `base.html` zet `{% with p=request.resolver_match.url_name %}` rond de inhoud, waardoor de ploeg `p` overschreven wordt. Gevolg: geen naam, altijd *gearchiveerd*, *Trainers: nog niemand*, en het bewerkformulier toont niets van de ploeg. Dezelfde fout zat in WordPress (`views/_layout.php`) en is daar opgelost door de variabele te hernoemen.
- **Uitleg in Django** toont een bestuurder en een trainer van de zwemschool enkel de documenten voor zwemmers; de nieuwe *Handleiding bestuur* staat nog niet in de lijst (`views.uitleg`, `templatetags/zwem.py`, in WordPress `modules/inschrijvingen/acties.php`).

## Verdergaan op een andere pc

```bash
git clone https://github.com/DinXke/khzs-inschrijvingen && cd khzs-inschrijvingen   # na het hernoemen: khzs-leden
git checkout -b mijn-werk            # nooit rechtstreeks op main werken
# Django
uv venv --python 3.12 .venv && uv pip install --python .venv -r requirements-dev.txt
.venv/bin/python manage.py migrate && .venv/bin/python manage.py test inschrijvingen
# WordPress (vereist php, composer, mariadb-server; op Windows in een Docker-container met LF-regeleindes)
wordpress/tests/testsite.sh && KHZS_URL=http://localhost:8080 python3 wordpress/tests/test_e2e.py
```

Start Claude Code in de map van de repo. Claude leest dan `CLAUDE.md`, dat naar dit document verwijst.

## Werken aan de plugin

```bash
wordpress/tests/testsite.sh                     # lokale WordPress + MariaDB + plugin op http://localhost:8080
KHZS_URL=http://localhost:8080 python3 wordpress/tests/test_e2e.py
wordpress/bouw.sh                               # khzs-leden.zip voor WordPress
```

- **Nieuwe module**: maak `modules/<id>/` met een `db.php` die `schema_<id>()` bevat en een `module.php`. Haak in via de filters `khzs_routes`, `khzs_menu`, `khzs_tabellen`, `khzs_voorkeuren` en `khzs_taken`, en voeg een regel toe aan `modules()` in `includes/kern.php`. Een module met eigen tabellen krijgt een eigen versie (zoals `TR_DB_VERSIE`, `COM_DB_VERSIE`), zodat `DB_VERSIE` van de kern (nu 6) niet hoeft te veranderen. Voeg die versie ook toe aan `demo_module_versies()`, anders krijgt de demo de tabellen niet.
- **Sjablonen**: `toon('module/naam', …)` laadt `modules/<module>/views/naam.php`. Sjablonen die met `include` herbruikt worden in een lus, gebruiken variabelen met een `_`-prefix. `_layout.php` gebruikt `$_pagina` (niet `$p`).
- **Datum in tests**: de header `X-Vandaag` simuleert een andere datum (enkel via de testhulp). In de code loopt alles via `vandaag()`.
- **Bewaren**: tijden als gehele honderdsten, bedragen in cent.
- **Haken voor andere modules**: `khzs_wedstrijd_bewaard`, `khzs_wedstrijd_verwijderd`, `khzs_bijlage_verwijderd`, `khzs_ploeg_verwijderd`, `khzs_zwemmer_verwijderd`, `khzs_zwemmer_pagina`, `khzs_wedstrijd_knoppen`, `khzs_demo_vullen`, `khzs_start`, `khzs_zonder_nonce`; voor de AVG `khzs_mijn_zwemmer`, `khzs_mijn_gegevens`, `khzs_bewaarplicht`, `khzs_opkuis_aantallen`, `khzs_bewaartermijn_voorbij`.
- **Samenvoegen**: verhoogt een andere branch ook `DB_VERSIE` of een moduleversie, neem dan de hoogste en controleer dat het schema alle kolommen heeft. Django-migraties lopen nu tot `0015_avg`.

## Releases

1. Verhoog de versie in `wordpress/khzs-leden/khzs-leden.php`, op de regel `Version:` én bij `const VERSIE`.
2. Merge naar `main` (na akkoord; ook Django en de demo nemen `main` over).
3. `git tag plugin-vX.Y.Z && git push origin plugin-vX.Y.Z`.

`.github/workflows/plugin-release.yml` controleert dat de tag en beide versies gelijk zijn, bouwt de zip met `bouw.sh` en maakt de release. De plugin (`includes/update.php`) kijkt hoogstens om de 6 uur naar de laatste release met een tag `plugin-v…` en de bijlage `khzs-leden.zip`; concepten en pre-releases tellen niet.

## Beslissingen

### Algemeen
- **Volledige port naar PHP**, met dezelfde schermen (`app.css` gedeeld), regels, Lenex-import en -export. **Geen externe bibliotheken**: de Excel-schrijver (`maak_xlsx`) en de Web Push-versleuteling (VAPID, aes128gcm) zijn zelf geschreven.
- **Eigen adres** `/leden/` met eigen opmaak, los van het thema, zodat de app een PWA blijft. `/club/` en `/inschrijvingen/` verwijzen door (301). De installatiemelding op de rest van de website gaat naar `/leden/installeer/`.
- **Naam**: plugin *KHZS Leden* (map `khzs-leden/`). Interne namen bleven: namespace `KHZS`, tabellen en opties met `khzs_`.
- **Logo**: HZS met *Leden* eronder (Rubik Black Italic, `#009FE0` en `#074DA2`). Opnieuw maken met `tools/logo/maak_iconen.py`; demo-iconen met `tools/logo/maak_demo_iconen.py`.
- **Migratie**: `manage.py export_wordpress` maakt een zip in het backupformaat van de plugin. Accounts worden gekoppeld op gebruikersnaam of e-mail; Django-wachtwoordhashes (`khzs_django_hash`) worden bij de eerste aanmelding omgezet, dus oude wachtwoorden werken.
- **Webshop**: knop naar de clubwinkel bij Robsport (`khzs_webshop`, Django `CLUB_WEBSHOP`; leeg = geen knop).

### Rollen, ploegen en stappenplan (Django én WordPress)
- **Rollen** van hoog naar laag: beheerder, sportsecretaris, trainer (competitie), trainer zwemschool, bestuurder, ouder, zwemmer. De rechten tellen samen; de hoogste rol is de *hoofdrol* (enkel weergave).
- **Bestuurder** beheert leden, rollen en ploegen, maar ziet geen wedstrijden, inschrijvingen, uitslagen of backup. Het sportsecretariaat behoudt *Gebruikers*. Zwemmer en ouder krijg je zonder goedkeuring; beheerder kan je niet aanvragen.
- **Trainer zwemschool** is een aparte rol (sleutel `trainer_zwemschool`), geen soort bij de rol `trainer`: zo bleven `heeft_rol()` en de backups ongewijzigd. Wedstrijdrechten via `STAF` (zonder zwemschool). Wie enkel trainer zwemschool is, ziet geen wedstrijden (`zonder_wedstrijden()`).
- **Ploegen** hebben een `soort` (competitie of zwemschool). De staf keurt een gekozen ploeg goed (trainer van die ploeg, bestuur, secretariaat, beheer). Trainers en bestuur zien alle ploegen, een trainer zwemschool de ploegen van de zwemschool en de eigen ploegen, leden enkel die van hun zwemmers. Alles gebeurt in de app, niet in `/beheer/` of wp-admin.
- **Stappenplan** (`/welkom/`): een nieuw account start zonder rol; zwemmer of ouder volgt uit de antwoorden. Het allereerste Django-account wordt sportsecretaris (in WordPress bestaat er altijd een beheerder).
- **WordPress-opslag**: rollen in de user meta `khzs_rollen` (en `khzs_rol` voor de hoofdrol), tabellen `khzs_rolaanvragen`, `khzs_ploegen`, `khzs_ploeg_trainers`, `khzs_ploegleden`. **Penningmeester** is een vlag (`khzs_penningmeester`), geen rol.

### Zwemmer bij meerdere accounts, zelf zwemmer
- Koppelcode van 8 tekens zonder O/0/I/1, 7 dagen geldig, meermaals te gebruiken; na 10 foute pogingen per uur geblokkeerd. Meldingen gaan naar alle accounts, *gelezen* geldt per melding. Wordt het eerste account verwijderd, dan neemt de andere ouder over.
- *+ Mezelf* bij *Mijn zwemmers*, en de stap *Zwem je zelf?* in het stappenplan.

### Module Trainers (enkel WordPress)
- **Planning**: vaste trainers per moment (`tr_vast`, met periode) plus wijzigingen per datum (`tr_wijzigingen`: afwezig, vervanger, extra). Ploegen per training in `tr_moment_ploegen`.
- **Seizoenen** in `tr_seizoenen`, apart van afgelastingen: buiten een seizoen bestaat een training niet. Zonder seizoenen verandert er niets; dagen vóór het eerste seizoen tellen als *in seizoen*, zodat uitbetaalde uren niet verdwijnen.
- **Verlof en afgelast** in `tr_afgelast` (voor één training, een ploeg of alles). Een gedeelde training gaat door zolang één ploeg traint. Betrokken trainers krijgen één melding.
- **Was je aanwezig?**: geplande taak elk kwartier (`khzs_tr_vragen`), webpush met *Ja/Nee*; antwoord via een HMAC-token (60 dagen geldig), zonder aanmelden. Eén herinnering na minstens 12 uur, enkel tussen 8 en 21 uur. **Enkel bevestigde trainingen tellen voor de uren**; *niet bevestigd* staat apart. Trainingen van vóór de invoering tellen zonder bevestiging (`khzs_tr_bevestigen_vanaf`).
- **Uitbetaald** bewaart een momentopname; een latere wijziging toont *gewijzigd na uitbetaling*. Een trainer wijzigt eigen trainingen tot de maand uitbetaald is; voor hun **eigen** trainingen volgen bestuurders de trainersregels. Wijzigingen door anderen komen in `tr_log` en geven de melding *Je planning is aangepast*.
- **Geen loonberekening**: wettelijke plafonds blijven buiten de plugin; de Excel is voor de boekhouding.
- **Aanwezigheid van zwemmers** (`tr_zwemmers`, met `status` leeg/`laat`/`vroeger`/`ziek`): elke trainer van de juiste soort mag aanduiden, niet enkel wie ingepland is. Zwemmers bevestigen zelf niets. Standaard staat iedereen aangevinkt. Statistiek voor staf (`trainers/zwemmers/`) en per zwemmer (`aanwezigheid/<id>/`) voor de zwemmer zelf en de ouders; andere zwemmers zijn nooit zichtbaar. Eigen kleurtokens `--zw-*`, altijd met legende en cijfers.

### Module Communicatie (enkel WordPress)
- **Eén tabel** `com_kanalen` voor kanalen en threads (`ouder_id`), met `toegang` (`iedereen`, `competitie`, `zwemschool`, `ploeg`, `wedstrijd`, `staf`, `erven`) en `functie` (`ploeg`, `wedstrijden`). Standaardopbouw bij het eerste gebruik.
- **Synchroon zonder haken in de inschrijvingen** (`com_sync()`): elke ploeg krijgt een kanaal, elke wedstrijd buiten concept een thread; deelnemers komen in `com_wedstrijdzwemmers` en **blijven** erin, ook na *Ik doe niet mee*. Wedstrijdthreads gaan een jaar na de wedstrijd naar het archief.
- **Trainer zwemschool** ziet niets van de competitie. **Leden posten**: instelbaar per kanaal. **Foto's en pdf's** enkel voor trainers en bestuur (5 MB). Moderatie door wie het kanaal als staf ziet.
- **Meldingen** enkel voor wie bij het kanaal hoort (`com_is_lid()`); meekijkers kunnen volgen (🔔), iedereen kan dempen. `@vermelden` enkel van wie het kanaal ziet (AVG). Polling elke 8 seconden, geen websockets.
- **Aankondigingen**: kolom `kanalen` (ids, leeg = iedereen; `COM_DB_VERSIE` 2). Vraag naar hulp met Ja/Misschien/Nee, aantal personen en Excel voor bestuur en maker.
- **Bewaren** 2 jaar; vastgepinde berichten blijven.

### Websitekalender (enkel WordPress)
- Via de PHP-API van **The Events Calendar**, standaard **uit**; nooit in de demo. Koppeling via post-meta `_khzs_wedstrijd_id`.
- **Handmatige wijzigingen** worden niet overschreven (vingerafdruk `_khzs_vingerafdruk`, conflict in de app). Niet meer publiek: gepubliceerd en toekomstig → *Geannuleerd*; nooit gepubliceerd → prullenbak; voorbij → blijft.
- Enkel publieke informatie; info voor zwemmers en bijlagen enkel als je ze aanzet of als publiek aanduidt.
- **Django synchroniseert niet** (beslist): twee schrijvers op één kalender zouden botsen.

### AVG (Django én WordPress)
- Privacyverklaring op `/privacy/` (`/leden/privacy/`), sjabloon in duidelijk Vlaams, met **versie**: wie de huidige versie niet aanvaardde, ziet een melding.
- Verplicht vinkje bij registratie; **toestemming van een ouder of voogd** voor zwemmers jonger dan 18 jaar.
- *Mijn gegevens*: download als JSON en account verwijderen (wachtwoord + VERWIJDER). In WordPress worden trainers met uren of vergoedingen *afgesloten* in plaats van verwijderd (bewaarplicht boekhouding, `BOEKHOUDING_JAREN` = 10).
- **Opkuis** dagelijks (Django `manage.py opkuis` mee met de herinneringen; WordPress `khzs_opkuis` om 03:00): meldingen en behandelde aanvragen na 365 dagen, pushabonnementen na 365 dagen zonder aanmelden, verlopen koppelcodes, berichten na 2 jaar.
- **Cookies**: enkel strikt noodzakelijk, dus geen cookiebanner voor de app. De rest van www.khzs.be valt daarbuiten.

**Inventaris van de persoonsgegevens**

| Gegevens | Django | WordPress | Wie ziet het |
|---|---|---|---|
| Account: naam, e-mail, wachtwoordhash, laatste aanmelding | `auth_user` | `wp_users`, user meta `khzs_laatst_aangemeld` | zelf, rolbeheer, beheer |
| Rollen, rolaanvragen (met motivatie en reden), penningmeester | `Rol`, `Rolaanvraag`, `Profiel.rol` | user meta `khzs_rollen`, tabel `khzs_rolaanvragen` | zelf, rolbeheer |
| Akkoord privacyverklaring (datum, versie) | `Profiel.privacy_*` | user meta `khzs_privacy_*` | beheer |
| Zwemmers: naam, geboortedatum (vaak minderjarig), geslacht, licentie, Swimrankings-ID, toestemming ouder | `Zwemmer` | `khzs_zwemmers` | gekoppelde accounts, staf |
| Gekoppelde accounts en koppelcodes | `extra_accounts`, `koppelcode` | `khzs_zwemmer_accounts` | gekoppelde accounts, staf |
| Ploegen, trainers van een ploeg, ploegaanvragen | `Ploeg`, `PloegLid` | `khzs_ploegen`, `_ploeg_trainers`, `_ploegleden` | zie *Rollen en ploegen* |
| Beste tijden, deelname, inschrijvingen, meldingen van wijzigingen | `BesteTijd`, `Deelname`, `Inschrijving`, `Melding` | `khzs_tijden`, `_deelnames`, `_inschrijvingen`, `_meldingen` | gekoppelde accounts, staf; inschrijvingen ook naar organisator en federatie (Lenex) |
| Pushmeldingen: endpoint, sleutels, browser, voorkeuren | `PushAbonnement`, `Profiel.push_*` | `khzs_push`, user meta `khzs_push_*` | beheer; pushdiensten krijgen versleutelde berichten |
| Trainers: schema, afmeldingen, aanwezigheid, uren, tarieven, uitbetalingen | (niet in Django) | `khzs_tr_*` | staf; tarieven en verloning enkel penningmeester en beheer |
| Aanwezigheid van zwemmers op trainingen | (niet in Django) | `khzs_tr_zwemmers` | trainers van de ploeg, bestuur; de zwemmer en de ouders |
| Berichten, aankondigingen, antwoorden op hulpvragen | (niet in Django) | `khzs_com_*` | wie het kanaal ziet; namen bij hulpvragen enkel bestuur en maker |
| Backups | zip + `pg_dump`, 30 dagen | zip in de privémap, 30 dagen | beheer |
| Logboeken (IP-adressen) | nginx/gunicorn, logrotate | hosting van de website | beheer, hosting |

### Eigen domein (enkel WordPress)
- Een **Cloudflare Worker** (`wordpress/leden-domein/worker.js`) stuurt `leden.khzs.be/…` door naar `khzs.be/leden/…` met de header `X-KHZS-Domein`. Optie `khzs_app_domein`; de plugin bewaart het domein enkel als `<domein>/domein-test/` correct antwoordt.
- Assets via het domein (de service worker cachet niet cross-origin). Op het oude adres schrijft de service worker zich uit; leden installeren de app opnieuw. Cookies standaard enkel op het domein (`COOKIE_DOMAIN` is optioneel). De Worker stopt met 508 bij een lus.
- Voorrang in `app_url()`: demo → eigen domein → gewoon adres.

### Demo (enkel WordPress)
- **Aparte WordPress** met eigen database (`wordpress/demo/`: docker-compose, `installeer.sh`, `update.sh`), los van www.khzs.be. Een Worker op `khzs.be/leden-demo*` stuurt naar de tunnel `leden-demo.khzs.be`; enkel de cookies van de demo gaan door.
- Demo-modus enkel met `define('KHZS_DEMO', true)` in `wp-config.php` (geen optie in wp-admin). Eigen tabellen `wp_khzsdemo_…`, eigen accounts (id's vanaf 900000001) en een eigen sessiecookie. Geen push, mail, registratie of backup terugzetten.
- **Altijd actueel**: `update.sh` (cron, elke 10 minuten) volgt `main`, bouwt de demo opnieuw op en gaat terug bij een fout. De plugin bouwt de demo ook opnieuw op als `demo_code_versie()` verandert, en maakt nieuwe moduletabellen aan (`demo_module_versies()`).
- **Demodata** (`includes/demo-data.php`): ploegen en trainingsuren van de competitie en de zwemschool van www.khzs.be, verzonnen personen, wedstrijden via de gewone Lenex-import, datums relatief tegenover vandaag. Uitbreiden via de actie `khzs_demo_vullen`.
- Installeerbaar als *KHZS Leden Demo* met iconen met een lint "DEMO".

## Mogelijke volgende stappen

- Een vervangingsvraag enkel naar de trainers van dezelfde soort sturen (nu naar alle trainers).
- RSVP voor zwemmers (afmelden voor een training of activiteit), een opmerking per zwemmer bij de aanwezigheid, en een melding aan de trainer als de zwemmers na een dag nog niet aangeduid zijn.
- Verlof per zwemmer en een kalenderweergave van de vakanties.
- Waarschuwing als een trainer een jaarbedrag nadert (plafond vrijwilligersvergoeding), instelbaar in wp-admin.
- Inschrijvingen per ploeg beperken (een wedstrijd enkel voor bepaalde ploegen).
- Communicatie: reacties en antwoorden op een bericht, *gezien door*, zoeken, threads door leden, officials of chauffeurs in een wedstrijdkanaal.
- *Gelezen* per ouder in plaats van per melding.
- De instructiefilmpjes opnieuw opnemen met het stappenplan, de ploegen en de modules van de plugin.
