# Overdracht: KHZS Leden (WordPress-plugin)

Dit document vat samen wat er in de sessie met Claude Code (14/11/2026) beslist en gebouwd is, zodat iemand anders of een volgende sessie meteen verder kan, ook op een andere pc. Lees daarna `wordpress/README.md` voor installatie en gebruik.

## Voorgeschiedenis en beslissingen

1. **Vertrekpunt**: de club heeft een WordPress-site (www.khzs.be) en deze Django-app (`inschrijvingen/`, draait in een Debian-LXC met Postgres, automatische updates vanaf `main`).
2. **Vraag**: kan de inschrijvingsapp een WordPress-plugin worden die exact hetzelfde werkt, met de WordPress-gebruikers als accounts? **Ja.** De plugin is een volledige port naar PHP:
   - zelfde schermen (`app.css` is ongewijzigd overgenomen), zelfde regels, zelfde Lenex-import en -export
   - **geen externe bibliotheken**: de Excel-schrijver en de Web Push-versleuteling (VAPID, aes128gcm) zijn zelf geschreven met PHP/OpenSSL
3. **Eigen adres in plaats van shortcode**: de app draait op een eigen URL met eigen opmaak, los van het thema. Zo blijft ze een installeerbare app (PWA) met service worker en pushmeldingen.
4. **Rollen**:
   - De app-rol (zwemmer, trainer, sportsecretaris, beheerder) staat in de user meta `khzs_rol`, los van de WordPress-rol. Een redacteur van de site kan dus ouder zijn in de app.
   - WordPress-beheerders zijn altijd beheerder.
   - "Het eerste account wordt sportsecretaris" uit Django is weggelaten: in WordPress bestaat er altijd al een beheerder.
5. **Migratie**:
   - `python manage.py export_wordpress` maakt een zip in het backupformaat van de plugin. Je zet ze terug via *Backup → Backup terugzetten*.
   - Accounts worden aangemaakt of gekoppeld op gebruikersnaam of e-mail.
   - Django-wachtwoordhashes (`pbkdf2_sha256`) worden bewaard in de user meta `khzs_django_hash`. Bij de eerste login worden ze gecontroleerd en omgezet naar een WordPress-wachtwoord. Zo blijven de bestaande wachtwoorden werken.
6. **Installatiemelding (PWA)**:
   - Op de website en in de app verschijnt een melding "Installeer de app KHZS Leden".
   - Browsers tonen hun installatievenster alleen binnen de scope van de app. Daarom gaat de knop op de rest van de website naar `/leden/installeer/`.
7. **Modules**: de plugin heette eerst `khzs-inschrijvingen` en is daarna omgebouwd tot één plugin **KHZS** met een kern en modules. Het adres werd `/club/`; het oude `/inschrijvingen/` verwijst door (301).
8. **Module Trainers**, op vraag van de club: trainers duiden aan welke trainingen ze normaal geven en melden zich af als ze niet kunnen. De penningmeester koppelt bedragen aan trainers en ziet per maand de uren en de verloning. Gekozen werking:
   - vaste trainers per trainingsmoment (`tr_vast`, met periode) plus wijzigingen per datum (`tr_wijzigingen`: afwezig, vervanger, extra)
   - pushmelding naar collega's bij een afmelding, en naar de afgemelde trainer als iemand overneemt
   - uren = trainingen tot en met vandaag waarop iemand aanwezig was, behalve afgelaste
   - tarief per trainer, per uur of per training, met *geldig vanaf*, zodat oude maanden niet veranderen
   - *uitbetaald* bewaart een momentopname; bij een latere correctie toont het overzicht "gewijzigd na uitbetaling"
   - de penningmeester is een vlag (`khzs_penningmeester`) los van de rol en kan ook een ouder zijn; enkel een beheerder kan die vlag zetten
   - **geen loonberekening**: wettelijke plafonds (vrijwilligersvergoeding, verenigingswerk) zitten niet in de plugin; de Excel-export is voor de boekhouding

9. **Zwemmer bij meerdere accounts** (gescheiden ouders), in Django én WordPress:
   - Het eerste account blijft `account`. Extra accounts staan in `extra_accounts` (Django) en in de tabel `khzs_zwemmer_accounts` (WordPress).
   - Een koppelcode van 8 tekens zonder O/0/I/1 is 7 dagen geldig en meermaals te gebruiken.
   - Na 10 foute pogingen per uur wordt het account geblokkeerd voor koppelen.
   - Meldingen en push gaan naar alle accounts. "Gelezen" geldt per melding, dus voor beide ouders samen.
   - Verwijder je het eerste account, dan wordt de andere ouder het eerste account (Django: `on_delete=account_verwijderd`).
   - De staf kan een account ook rechtstreeks koppelen via het e-mailadres.
11. **Naam KHZS Leden** (op vraag van de eigenaar):
   - Plugin: "KHZS Leden", map `wordpress/khzs-leden/` met als hoofdbestand `khzs-leden.php`. Het adres werd `/leden/`; `/club/` en `/inschrijvingen/` verwijzen door (301).
   - Interne namen zijn gebleven: PHP-namespace `KHZS`, tabellen en opties met `khzs_`.
   - Django: de zichtbare naam is "KHZS Leden" (titel, app op de gsm, mails, beheer).
   - De GitHub-repo heet `khzs-leden`. GitHub verwijst het oude adres `khzs-inschrijvingen` automatisch door, dus de server werkt gewoon verder.
12. **Logo KHZS Leden**: de app-iconen tonen het HZS-logo met daaronder "Leden" in dezelfde stijl.
   - "Leden" staat in Rubik Black Italic (SIL OFL), in dezelfde twee blauwtinten `#009FE0` en `#074DA2`, met de golf zoals die in het HZS-logo gemeten is.
   - Opnieuw maken: `python3 tools/logo/maak_iconen.py`. Dat schrijft naar `static/img/` en `wordpress/khzs-leden/assets/img/`.
   - De kleine iconen (192 en 180 pixels) hebben meer letterruimte, zodat de e leesbaar blijft.
   - Het favicon (32 pixels) en het logo bovenaan in de app tonen enkel HZS.
10. **Zelf zwemmer** (bv. masters), in Django én WordPress:
   - Bij registratie vult het vinkje *Ik zwem zelf* het eerste zwemmerblok met de eigen naam. Dat gebeurt enkel met JavaScript; de server verandert niet.
   - Bij *Mijn zwemmers* opent de knop *+ Mezelf* het formulier met de naam al ingevuld. De knop verdwijnt zodra er een zwemmer met de naam van het account bestaat.

## Trainers: eigen schema, aanwezigheid bevestigen, bestuur past aan (branch `wordpress/trainers-aanwezigheid`)

Vraag van de eigenaar: trainers geven zelf hun gewone schema in, krijgen na de training een melding met de vraag of ze aanwezig waren, kunnen achteraf nog het schema of één training aanpassen; bestuurders zien alles, passen desnoods aan, en de trainer krijgt daarvan een melding en ziet het in de app.

Gekozen werking (details in `wordpress/README.md`, *Module Trainers*):
- **Schema**: blijft `tr_vast` (vaste trainer per moment, met periode). De trainer kiest nu zelf een datum *vanaf* of *niet meer vanaf*, ook in het verleden tot het begin van vorige maand, maar niet in een uitbetaalde maand.
- **Aanwezigheid**: nieuwe tabel `tr_bevestigingen` (moment, datum, trainer, `aanwezig` 1/0/NULL, door wie, bron, gevraagd, herinnerd).
  - Geplande taak `khzs_tr_vragen` elk kwartier (eigen cron-interval `khzs_kwartier`): na het einde van de training een webpush *Was je aanwezig?* met de knoppen Ja/Nee; kijkt 2 dagen terug. Eén herinnering na minstens 12 uur, enkel tussen 8 en 21 uur.
  - Antwoorden met één tik: de service worker post naar `/leden/trainers/bevestig/` met een HMAC-token (trainer + training, 60 dagen geldig). Geen nonce nodig, ook geen aanmelding. Tik op de melding zelf → eenvoudig scherm met twee knoppen.
  - **Uren tellen enkel wat als aanwezig bevestigd is.** Niet bevestigd telt niet mee, maar staat apart (overzicht, verloning, Excel-kolom *Niet bevestigd*) tot de trainer of het bestuur bevestigt. Zo betaalt de club niets uit wat niemand bevestigde, en ziet de penningmeester wat er nog openstaat.
  - Bestaande installaties met planning: trainingen vóór de dag van de update tellen mee zonder bevestiging (optie `khzs_tr_bevestigen_vanaf`, eenmalig gezet bij de update).
  - Een trainer wijzigt eigen voorbije trainingen tot de maand voor hem of haar *uitbetaald* is; daarna enkel het bestuur (met de bestaande waarschuwing *gewijzigd na uitbetaling*).
  - Bestuurders die ook trainer (en zwemmer) zijn: voor hun **eigen** trainingen en schema gelden de trainersregels (`tr_mag_bevestigen()`, `v_tr_actie()`, `v_tr_vast()`), zodat niemand zijn eigen uren na uitbetaling wijzigt. Ze krijgen geen melding over hun eigen wijzigingen. Getest in `test_bestuurder_die_ook_trainer_is`.
- **Bestuur**: functie `tr_is_bestuur()` in `modules/trainers/regels.php`: `heeft_rol([BESTUURDER, BEHEERDER])` als die helper en rol bestaan (PR #7, `feature/rollen`), en altijd `is_penningmeester()` (penningmeester-vlag of beheerder). Zonder #7 valt hij dus terug op penningmeester en beheerder; na het samenvoegen van #7 werkt de rol *bestuurder* zonder verdere aanpassing. `tr_mag_beheren()` = bestuur of sportsecretariaat; dat bepaalt wie alles ziet en aanpast.
  - Nieuw scherm `/leden/trainers/overzicht/`: per maand per trainer schema, aanwezig, niet aanwezig, niet bevestigd en uren; per trainer de trainingen met Ja/Nee, *alles bevestigen*, het schema en het logboek. Een bestuurder zonder trainersrol komt via *Trainingen* meteen op dit overzicht.
- **Melding bij aanpassing**: nieuwe tabel `tr_log`. Elke wijziging door iemand anders dan de trainer zelf (schema, afmelden, toevoegen, schrappen, aanwezigheid) komt in het logboek en stuurt de push *Je planning is aangepast*. De trainer ziet *Aangepast door het bestuur* bovenaan *Trainingen*, en alles op `/leden/trainers/wijzigingen/` (wordt dan gelezen).
- **Nieuwe pushvoorkeuren**: `push_aanwezig` en `push_aanpassing` (standaard aan, enkel voor staf).
- **Buiten `modules/trainers/`** (klein, let op bij samenvoegen met andere branches):
  - `includes/app.php`: filter `khzs_zonder_nonce` voor POST-routes met een eigen beveiliging (hier het token).
  - `views/sw.js.php`: meldingen met knoppen (`acties`: actie, titel, post, velden) en `tag`; bij een tik op een knop post de service worker en toont een bevestiging, anders opent het antwoordscherm. Algemeen bruikbaar voor andere modules.
  - De tabellen worden aangemaakt via een eigen versie `TR_DB_VERSIE` in `modules/trainers/db.php`, zodat `DB_VERSIE` in `khzs-leden.php` niet hoeft te veranderen.
- **Tests**: `TrainersTest` gebruikt nu een gedeelde basis `TrainersBasis`; nieuw is `AanwezigheidTest` (4 scenario's: bestuurder die ook trainer is, vraag en antwoord vanuit de melding met herinnering, bestuur past aan met melding en logboek, trainer past eigen schema en voorbije training aan met de grens *uitbetaald*). Getest op een Docker-testsite (`mariadb:11`, `wordpress:php8.2`).
- **Niet getest op een echt toestel**: de knoppen in de melding (Android/Chrome en desktop ondersteunen ze; iPhone toont geen knoppen en opent dan het scherm).
- **Bewust niet gedaan**: een wijziging aan het rooster zelf (uur, afgelasting) geeft geen persoonlijke melding; wettelijke plafonds blijven buiten de plugin.

## Stand van zaken (14/11/2026, eind van de dag)

- **Alles staat op `main`**, samengevoegd op vraag van de eigenaar via vijf pull requests:
  - #1: WordPress-plugin met de modules Inschrijvingen en Trainers
  - #2: overdracht bijgewerkt
  - #3: zwemmer bij meerdere accounts (Django-migratie `0011_zwemmer_accounts`)
  - #4: *Ik zwem zelf* en *+ Mezelf*
  - #5: nieuwe naam KHZS Leden (plugin `wordpress/khzs-leden/`, adres `/leden/`) en nieuw app-icoon
- Elke commit op `main` komt binnen 10 minuten op de productieserver (`deploy/auto_update.sh`, met terugrol als `update.sh` faalt). Vraag daarom akkoord vóór een nieuwe samenvoeging.
- **Werkbranch**: `ccr-5219135a-lksd0b`. Die is na de laatste samenvoeging opnieuw gelijkgezet met `main`. Op een andere pc maak je gerust een eigen branch van `main`.
- **Tests**:
  - Django: 84, alle geslaagd. De vroeger falende datumafhankelijke test staat nu op een vaste datum.
  - WordPress: 76 end-to-end-scenario's in `wordpress/tests/test_e2e.py`, alle geslaagd.
- **Migratie** naar WordPress getest met de Django-demodata: alle gegevens komen over, ook gekoppelde accounts, en oude wachtwoorden werken.
- **Push**: de versleuteling en de VAPID-handtekening zijn gecontroleerd met de Python-bibliotheek `cryptography`. Nog niet getest op een echt toestel.
- **Nog niet geïnstalleerd** op www.khzs.be. Claude had vanuit de cloudomgeving geen toegang tot die site. Maak de zip met `wordpress/bouw.sh` en upload hem via WordPress → Plugins.
- **Niet bijgewerkt**: de PDF-handleidingen, screenshots en filmpjes in `docs/` tonen nog de oude naam en het oude icoon, en vermelden het koppelen en *Ik zwem zelf* niet. Opnieuw maken kan met `docs/build.py` en `docs/video.py`.
- **Open bij de eigenaar**:
  - De GitHub-repo hernoemen van `khzs-inschrijvingen` naar `khzs-leden` (Settings → General). GitHub stuurt het oude adres door.
  - Daarna op de server: `git -C /opt/khzs/app remote set-url origin https://github.com/DinXke/khzs-leden`.
  - Op gsm's die de app al hadden, komen het nieuwe icoon en de nieuwe naam soms pas na opnieuw installeren.

## Verdergaan op een andere pc

```bash
git clone https://github.com/DinXke/khzs-leden && cd khzs-leden   # vóór het hernoemen: khzs-inschrijvingen
git checkout -b mijn-werk            # niet rechtstreeks op main werken
# Django
uv venv --python 3.12 .venv && uv pip install --python .venv -r requirements.txt
.venv/bin/python manage.py migrate && .venv/bin/python manage.py test inschrijvingen
# WordPress (vereist php, composer, mariadb-server)
wordpress/tests/testsite.sh && KHZS_URL=http://localhost:8080 python3 wordpress/tests/test_e2e.py
```

Start Claude Code in de map van de repo. Claude leest dan automatisch `CLAUDE.md`, dat naar dit document verwijst. Vraag bijvoorbeeld: *"Lees wordpress/OVERDRACHT.md en ga verder met …"*.

## Wat er gevraagd werd (chronologisch, sessie 14/11/2026)

1. Kan Hugo meerdere gebruikers, editors, formulieren en een inschrijfsysteem met database aan? Advies: Hugo is statisch; de club heeft al WordPress en een eigen Django-inschrijfapp.
2. "Maak er een WordPress-plugin van die exact hetzelfde werkt, met de WordPress-gebruikers" → plugin (PR #1).
3. Installatiemelding om de app te installeren (PWA) op de website.
4. Eén algemene KHZS-plugin met modules, met een extra module voor trainers: wanneer ze training geven, afmelden als ze niet kunnen, en de penningmeester die bedragen koppelt en per maand uren en verloning ziet.
5. Alles in deze repo zetten, met een overdracht ("geheugen") om elders verder te kunnen.
6. Een zwemmer aan meerdere accounts koppelen (gescheiden ouders) met een code (PR #3).
7. Een vinkje "Ik zwem zelf" bij registratie en in het profiel (PR #4).
8. Alles hernoemen naar KHZS Leden, ook de repo, met het adres `/leden/` (PR #5).
9. Een logo in dezelfde stijl: HZS bovenaan, "Leden" eronder (PR #5).

## Mogelijke volgende stappen

- Waarschuwing als een trainer een jaarbedrag nadert (bv. het plafond van de vrijwilligersvergoeding), instelbaar in wp-admin.
- Groepen (A-groep, B-groep…) als gedeelde gegevens in de kern. Inschrijvingen kan dan een wedstrijd beperken tot een groep, en Trainers kan een groep aan een training koppelen.
- RSVP of aanwezigheden voor zwemmers (afmelden voor een training, activiteiten).
- Testen op echte toestellen: installeren op Android en iPhone, en pushmeldingen.
- De PDF-handleidingen, screenshots en filmpjes bijwerken met de nieuwe naam, het icoon, het koppelen van zwemmers en *Ik zwem zelf*.
- Eventueel "gelezen" per ouder bijhouden in plaats van per melding.
- Installatie op www.khzs.be: zip uploaden, permalinks controleren, zelf registreren eerst uitzetten, en daarna de migratie vanuit Django.

## Demo op /leden-demo/ (branch `wordpress/demo`, PR #8)

Vraag van de eigenaar: *"kan je een /leden-demo maken waarin heel de app nagemaakt is (geen pwa), met demo data, een demo/demo user met alle mogelijke rechten, die bestuurder, zwemmer, trainer, ouder,... is. enkele wedstrijden toegevoegd, de ploegen (je vind die in de uren van de competitie), de uren van de zwemschool voor de trainers, de ploegen van de zwemschool,...."*

Daarna nog drie bijsturingen:
- *"deze moet ook continu up to date worden gehouden. geen koppeling met de productie wordpress"*;
- *"eventueel toch een pwa app ervan maken maar die heel duidelijke KHZS Leden Demo noemen"*;
- adres: *"khzs.be/leden-demo"*.

**Beslissingen**
- **Aparte installatie**: de demo draait op een eigen WordPress met een eigen database (`wordpress/demo/`: docker-compose, `installeer.sh`, `update.sh`). Ze deelt niets met www.khzs.be.
- **Adres khzs.be/leden-demo**: khzs.be loopt via Cloudflare.
  - Een Worker (`wordpress/demo/worker.js`) op de route `khzs.be/leden-demo*` stuurt dat pad naar de tunnel `leden-demo.khzs.be` (nieuwe LXC `leden-demo`, `wordpress/demo/lxc/maak-lxc.sh`).
  - De demo-WordPress staat zelf in de submap `/leden-demo/` (`working_dir` in de compose, eigen `.htaccess`). De app staat op de wortel van die site (`demo_slug()` is leeg met `KHZS_DEMO_ENKEL`).
  - De Worker laat enkel de cookies van de demo door: `khzs_…` en `…_khzsdemo` (vaste `COOKIEHASH`). Aanmeldcookies van de echte site komen dus nooit bij de demoserver.
  - In de kern is `huidige_url()` hersteld: bij WordPress in een submap kwam het pad dubbel.
- **Aan met een constante**: demo-modus enkel met `define('KHZS_DEMO', true)` in wp-config.php. Er is geen optie in wp-admin, zodat ze op productie nooit per ongeluk aangaat.
  - `KHZS_DEMO_ENKEL` laat de rest van de site en `/leden/` doorverwijzen naar `/leden-demo/`.
  - De filter `khzs_demo_aan` dient enkel voor tests (header `X-KHZS-Demo-Uit` in de testhulp).
- **Altijd actueel**: `update.sh` (cron, elke 10 minuten) volgt `main`, net zoals `deploy/auto_update.sh` voor Django. Bij een nieuwe commit:
  - rewrite-regels opnieuw opbouwen;
  - `demo_terugzetten()`;
  - lukt dat niet, terug naar de vorige commit.

  Ook de plugin zelf bouwt de demo opnieuw op als `demo_code_versie()` verandert (VERSIE, DB_VERSIE of `demo-data.php`). Dat werkt dus ook met de automatische updates via releases (`wordpress/auto-update`).
- **App (PWA)**: installeerbaar als *KHZS Leden Demo* (de opties voor de app-naam worden in de demo overschreven).
  - `asset()` kiest in de demo de iconen uit `assets/img/demo/`, met een oranje lint "DEMO" (`tools/logo/maak_demo_iconen.py`).
  - Pushmeldingen blijven uit.
- **Scheiding ook binnen één installatie** (extra veiligheid):
  - `t()` geeft in de demo `wp_khzsdemo_…`;
  - `$wpdb->users` en `$wpdb->usermeta` wijzen naar `wp_khzsdemo_users` en `wp_khzsdemo_usermeta`, met id's vanaf 900000001;
  - de cachegroepen van accounts staan enkel in het geheugen;
  - een eigen cookie `khzs_demo_sessie` met pad `/leden-demo/` (WordPress stuurt in de demo geen aanmeldcookies);
  - eigen opties voor de privémap en de databaseversie.
- **Uit in de demo**: pushmeldingen (`stuur()`), mail (`pre_wp_mail`), zelf registreren en een backup terugzetten.
- **Demodata** (`includes/demo-data.php`):
  - accounts rechtstreeks in de tabellen, zonder `wp_insert_user`, zodat andere plugins er niet op reageren;
  - wedstrijden via de gewone Lenex-import;
  - datums relatief tegenover `vandaag()`;
  - vast pseudo-toeval (`demo_rnd`).
- **Ploegen en uren**:
  - De competitie komt van www.khzs.be/trainingsuren-competitie (14/11/2026), met verzonnen trainers. Ploegen die op hetzelfde uur trainen, vormen één training.
  - De zwemschool:
    - lesmomenten: www.khzs.be/inschrijven-zwemtest;
    - groepen: de pagina's *doelstellingen* en *vakantieperiodes*;
    - welke groep wanneer zwemt: niet op de website, dus verzonnen (`demo_ploegen()`).
- **Terugzetten**: `demo_terugzetten()` maakt alle demotabellen leeg en vult ze opnieuw. `demo_controleer()` weigert als de tabelnamen geen `khzsdemo_` bevatten.
  - De taak `khzs_demo_reset` draait om 03:30.
  - Knoppen in de demo en in wp-admin.

**Uitbreiden voor nieuwe onderdelen** (rollen en ploegen, PR #7; urenregistratie):
- Haak in op de actie `khzs_demo_vullen`. Die krijgt `$c->users` (sleutel → id, bv. `demo`, `lotte`), `$c->zwemmers`, `$c->ploegen` (met `->momenten`, `->trainers`, `->soort`) en `$c->seizoen`.
- `demo_personen()` heeft een kolom *extra* met `bestuurder` (demo, marc) en `penningmeester`.
- `demo_zwemmers()` geeft per zwemmer de ploeg.
- Nieuwe tabellen via `t()` en `khzs_tabellen` komen vanzelf in de demo.

**Kernwijzigingen** (klein gehouden):
- `t()`, `slug()`/`echte_slug()` en `asset()`;
- de demoroute in `registreer_routes()`;
- `app_url()` zonder mooie permalinks;
- `stuur()`;
- `_layout.php`, `aanmelden.php` en `meldingen.php`;
- de haak `khzs_admin_onder` in `admin.php`.

**Stand**:
- `DemoTest` in `tests/test_e2e.py` (6 scenario's), alle geslaagd.
- `wordpress/demo/` is lokaal getest met Docker Desktop: `installeer.sh`, en `update.sh` met een nieuwe commit.
- De Worker is lokaal getest met Node: cookiefilter, doorsturen, aanmelden en manifest.
- **Nog niet uitgerold.**
  - **LXC aangemaakt (14/11/2026)**: CT 139 `leden-demo` op pve01. Debian 13, 2 cores, 2 GB RAM, 16 GB op `rpool`, `vmbr1` met DHCP, IP **10.10.30.220**, SSH als root met de sleutel van Björn.
    - Aangemaakt via de Proxmox-API met het token van Klimaat. Dat token mag geen `keyctl` zetten, maar Docker werkt ook met alleen nesting.
    - Daarna `lxc/firstboot.sh` met `DEMO_TAK=wordpress/demo` (zolang PR #8 niet gemerged is). Zet het na de merge op `main` in `/opt/khzs-demo/wordpress/demo/.env`.
    - De demo draait: met de headers van de Worker getest in de container; cron voor `update.sh` staat in `/etc/cron.d/khzs-demo`.
    - Het wachtwoord van wp-admin van de demo (gebruiker `beheer`) staat in `/var/log/khzs-demo-firstboot.log` op CT 139.
  - **Live sinds 14/11/2026** op https://khzs.be/leden-demo/.
    - Tunnel `leden-demo` → `leden-demo.khzs.be` → `localhost:8094`, met cloudflared op CT 139.
    - Worker `khzs-leden-demo` met `BRON=https://leden-demo.khzs.be` en de route `khzs.be/leden-demo*` (fail open).
    - Van buitenaf getest: aanmelden, alle schermen, manifest, iconen en service worker.
  - **Nog open**:
    - PR #8 mergen en daarna `DEMO_TAK=main` zetten in `.env` op CT 139;
    - het tunneltoken vernieuwen: het stond in de chat.

## Werken aan de plugin

```bash
wordpress/tests/testsite.sh                     # lokale WordPress + MariaDB + plugin op http://localhost:8080
KHZS_URL=http://localhost:8080 python3 wordpress/tests/test_e2e.py
wordpress/bouw.sh                               # khzs-leden.zip voor WordPress
```

- **Nieuwe module**: maak `modules/<id>/` met een `db.php` die `schema_<id>()` bevat en een `module.php`. Haak in via de filters `khzs_routes`, `khzs_menu`, `khzs_tabellen`, `khzs_voorkeuren` en `khzs_taken`, en voeg een regel toe aan `modules()` in `includes/kern.php`.
- **Sjablonen**: `toon('module/naam', …)` laadt `modules/<module>/views/naam.php`. Sjablonen die met `include` herbruikt worden in een lus, gebruiken variabelen met een `_`-prefix om botsingen te vermijden.
- **Datum in tests**: de header `X-Vandaag` simuleert een andere datum (enkel via de testhulp). In de code loopt alles via `vandaag()`.
- **Bewaren**: tijden staan als gehele honderdsten en bedragen in cent.
