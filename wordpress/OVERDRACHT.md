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

## Eigen domein leden.khzs.be (branch `wordpress/subdomein`)

Vraag van de eigenaar: *"kan de plugin in productie ook op leden.khzs.be werken?"* **Ja**, met een Cloudflare Worker voor dezelfde WordPress. Alles staat in `wordpress/leden-domein/`: de Worker, en een README met de stappen in Cloudflare en het terugdraaien.

- **Werking**: de Worker op `leden.khzs.be` stuurt elk verzoek door naar `https://khzs.be/leden/…`.
  - De assets van de plugin gaan naar hetzelfde pad op khzs.be.
  - De Worker zet de header `X-KHZS-Domein` (en `X-Forwarded-Host`).
  - Een eigen header, omdat een proxy van de hosting `X-Forwarded-Host` kan overschrijven.
- **Plugin** (`includes/app.php`): de optie `khzs_app_domein` (wp-admin, *Eigen domein*), met de functies `app_domein()` en `via_app_domein()`.
  - `app_url()` en `asset()` maken dan URL's op het domein.
  - `huidige_url()` geeft het adres op het domein. Die functie is ook hersteld voor WordPress in een submap, net als op de branch `wordpress/demo`.
  - Verzoeken zonder de header gaan met een 302/307 naar het domein. Tijdelijk, zodat browsers niets onthouden.
  - `allowed_redirect_hosts` laat het domein toe voor `?next=` en *wachtwoord vergeten*.
- **Gekozen**:
  - **Assets via het domein**: de service worker zet bij de installatie de assets in de cache (`cache.addAll`), en dat mislukt cross-origin. Met assets op khzs.be zou de app dus niet meer installeren.
  - **Oude installaties**: `khzs.be/leden/sw.js` geeft een service worker die zichzelf uitschrijft, want een service worker doorverwijzen kan niet. Leden installeren de app opnieuw en zetten push opnieuw aan.
  - **Cookies**: standaard alleen op `leden.khzs.be`, dus apart aanmelden voor de app en voor wp-admin. `COOKIE_DOMAIN` `.khzs.be` om één aanmelding te delen staat als optionele stap beschreven, niet als standaard.
  - **Controle bij het bewaren**: de plugin vraagt `<domein>/domein-test/` op en bewaart het domein enkel als de Worker antwoordt met de juiste header. Zo kan een fout ingesteld domein de app niet onbereikbaar maken.
  - **Lusbeveiliging**: verwijst de site naar exact hetzelfde adres terug (de header kwam niet aan), dan stopt de Worker met een 508 en een uitleg.
- **Getest**:
  - lokaal met Docker (`mariadb:11`, `wordpress:php8.2`) en de Worker in Node (`wordpress/tests/worker_sim.mjs`)
  - 7 nieuwe scenario's in `EigenDomeinTest`; alle 84 e2e-tests geslaagd
  - met headless Chromium: de service worker installeert op scope `/` van het domein en cachet de assets; op het oude adres schrijft hij zich uit
  - in wp-admin: de controle bij het bewaren weigert een onbereikbaar domein
- **Nog niet gedaan**: niets uitgerold in Cloudflare of op www.khzs.be. De eigenaar volgt `wordpress/leden-domein/README.md`.
- **Merge met `wordpress/demo` (PR #8)**:
  - Die branch wijzigt ook `slug()`, `app_url()`, `asset()` en `huidige_url()` in `includes/app.php`. Verwacht daar botsingen.
  - Bij het samenvoegen: de demo heeft voorrang (`is_demo()` → geen eigen domein); daarna `app_domein()`; anders het gewone adres.
  - Bij `huidige_url()` is de herstelling voor een submap op beide branches dezelfde.

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

## Trainers: verlof per ploeg en seizoen (branch `wordpress/trainers-verlof-seizoen`)

Vraag van de eigenaar: *"de mogelijkheid toevoegen om verlofperiodes in te stellen per groep enzo zou ook handig zijn, begin en einde seizoen,..."*

- **Branch**: vertrekt van `wordpress/trainers-aanwezigheid` (PR #13) met `origin/feature/ploegen` (PR #9, bovenop #7) erin gemerged. Hangt dus af van #9 en #13. Enige botsing: `tests/test_e2e.py` (beide voegden testklassen toe; allebei behouden).
- **Ploeg per training**: nieuwe tabel `tr_moment_ploegen` (moment, ploeg; meerdere ploegen per training kan). Vinkjes in het formulier van de training (`views/_moment_velden.php`). Zonder ploeg = voor iedereen.
- **Verlof per ploeg**: `tr_afgelast` kreeg de kolommen `ploeg_id`, `soort` (vakantie, verlof, zwembad, ander: `TR_AFGELAST_SOORT`), `door_id` en `aangemaakt` (via `TR_DB_VERSIE` 3, dbDelta). Eén rij geldt voor één training (`moment_id`), een ploeg (`ploeg_id`) of alles (beide 0). In `tr_bezetting()`: een training valt weg door ploegverlof als **al** haar ploegen verlof hebben (een gedeelde training gaat door zolang één ploeg traint). Een training zonder ploeg valt enkel weg door een afgelasting voor alles of voor die training.
- **Seizoen: apart, niet via `tr_afgelast`.** Nieuwe tabel `tr_seizoenen` (naam, van, tot). `tr_in_seizoen()` filtert in `tr_trainingen()` en `tr_geldig()`, dus buiten het seizoen bestaat een training gewoon niet: niet in planning, *Mijn trainingen*, uren, Excel of `tr_vraag_aanwezigheid()`, en de trainingspagina geeft 404. Waarom apart: een afgelasting blijft zichtbaar met een reden, terwijl een zomerstop niet als honderd afgelaste trainingen in de planning hoort te staan.
  - Zonder seizoenen verandert er niets. Dagen **vóór het begin van het eerste seizoen** tellen als *in seizoen*: zo verdwijnen de bestaande (en uitbetaalde) uren niet als het bestuur pas nu een seizoen invoert.
  - Valt vandaag buiten elk seizoen, dan toont de beheerpagina een waarschuwing (*Voeg het volgende seizoen toe…* als het laatste voorbij is).
- **Wie**: de beheerpagina `/leden/trainers/beheer/` en de trainingspagina `/leden/trainers/moment/<id>/` gebruiken nu `tr_vereist_beheer()` (bestuur **en** secretariaat) in plaats van `vereist_rol(SECRETARIAAT)`. De knop *Trainingen beheren* staat er voor wie `tr_mag_beheren()`.
- **Melding**: `tr_wijzig_afgelast()` vergelijkt de afgelaste trainingen vanaf vandaag vóór en na de wijziging en stuurt elke betrokken trainer één push (*Training afgelast* of *Training gaat toch door*). Nieuwe voorkeur `push_afgelast` (standaard aan, enkel staf).
- **Buiten `modules/trainers/`**: `includes/ploegen.php` roept `do_action('khzs_ploeg_verwijderd', $id)` als een ploeg verwijderd wordt; de trainersmodule ruimt dan de koppelingen en het verlof van die ploeg op (de trainingen blijven).
- **Tests**: nieuwe klasse `VerlofSeizoenTest` (2 scenario's: verlof per ploeg met meldingen, gedeelde training, geen vraag, verwijderen, voorkeur uit, ploeg verwijderen, ongeldige invoer; en seizoenen met overlap, planning, 404, vraag, uren, waarschuwing en terug zonder seizoen). Alle e2e-tests groen op een Docker-testsite.
- **Niet gedaan**: seizoenen en verlof zitten niet in de Django-versie (de trainersmodule bestaat enkel in WordPress). Verlof voor een zwemmer (in plaats van een ploeg) en een kalenderweergave van de vakanties zijn mogelijke volgende stappen.

## Trainers: zwemschool en competitie, en een kalender (branch `wordpress/trainers-soorten-kalender`)

Vragen van de eigenaar: *"er is ook een groot verschil tussen een trainer van de zwemschool en een trainer van de competitie (sommigen doen beide). Trainers van de zwemschool zijn niet bezig met wedstrijden enzo."* en *"kan je voor de trainingsregistratie ook een kalenderview maken? dag/week/maand?"*

- **Branch**: vertrekt van `wordpress/trainers-verlof-seizoen` (PR #14, met `main`, rollen #7 en ploegen #9 erin, commit d155246). Hangt dus af van #7, #9, #13 en #14.
- **Twee rollen, geen omzetting.** De bestaande rol `trainer` is nu *Trainer competitie* (enkel de naam veranderde, de sleutel niet). Nieuw: `trainer_zwemschool` (*Trainer zwemschool*), aan te vragen bij het registreren en bij *Mijn rollen*. Wie beide doet, krijgt beide rollen. Zo verliest niemand rechten en is er geen migratie van gegevens nodig. Gekozen boven een rol met een "soort" erbij: de rollen uit #7 tellen al samen, en zo blijven `heeft_rol()` en de bestaande backups ongewijzigd.
  - `STAF` (wedstrijden, nakijken, uitslagen) blijft `[trainer, secretaris, beheerder]`: zonder zwemschool. Nieuw: `TRAINERS` (beide soorten) en `is_trainer()` in `includes/kern.php`, `TR_STAF` en `tr_is_trainer()` in de trainersmodule (vervangt `is_staf()` daar). De pushvoorkeuren van de trainers hebben nu de zichtbaarheid `'trainer'`.
  - `zonder_wedstrijden()`: enkel trainer zwemschool (geen zwemmer, ouder of wedstrijdstaf). Geen menu *Wedstrijden*, `wedstrijden/` stuurt door naar de start, en de startpagina is *Trainingen* (nieuwe filter `khzs_start` in `v_start()`). Wie ook ouder is, ziet de wedstrijden voor de eigen kinderen.
  - **Ploegen** kregen een kolom `soort` (competitie, standaard, of zwemschool; `DB_VERSIE` 6, dbDelta). Een trainer zwemschool ziet de ploegen van de zwemschool en de eigen ploegen, en het menu *Ploegen* (`ziet_ploegenmenu()`). Beide soorten trainers kunnen aan een ploeg gekoppeld worden. Kleine verbetering: een trainer die een ploeg traint maar niet alle ploegen ziet, ziet nu ook de aanvragen voor die ploeg.
  - **Django** volgt voor het inschrijvingsdeel: zelfde rol en namen (`Profiel.TRAINER_ZWEMSCHOOL`, `TRAINERS`), `Ploeg.soort`, `zonder_wedstrijden()` in `rollen.py` (menu, start → ploegen, `wedstrijden/` → ploegen), zichtbaarheid van de ploegen, het stappenplan. Migratie `0014_trainer_zwemschool` (nieuwe kolom en nieuwe keuzes, geen gegevens omgezet). `export_wordpress` neemt de soort mee. Tests: `TrainerZwemschoolTest` in `test_ploegen.py`; 111 tests groen.
- **Kalender**: `/leden/trainers/kalender/` (`v_tr_kalender()`, `views/kalender.php`). Parameters `weergave` (dag, week, maand), `datum`, `wie` (`mij`, `alle` of, voor het bestuur, een user-id) en `ploeg`. Trainer standaard `mij`, bestuur en secretariaat standaard `alle`; een trainer die een user-id meegeeft, krijgt `mij`. Gebruikt `tr_trainingen()`, dus afgelast, verlof per ploeg en het seizoen komen vanzelf mee. De dagweergave gebruikt `_training.php` (met de knoppen); de week en de maand tonen blokjes die naar de trainingspagina linken.
  - Op de gsm: geen aparte dagweergave als standaard, maar een week waarin de dagen onder elkaar staan (lege dagen weg). Dat leest op een klein scherm beter dan één dag per keer en vraagt geen JavaScript. De maand toont dan gekleurde streepjes per training.
  - `_training.php` toont nu ook wie zich afmeldde (doorstreept) en voor wie een vervanger invalt; dat zie je ook in *Planning*.
- **Demo** (`includes/demo-data.php`): de rollen staan nu in `khzs_rollen` (demo heeft alle rollen; ouders krijgen de rol ouder). Nina, Ruben, Elise en Jasper zijn trainer zwemschool, Lotte is beide. De ploegen staan nu echt in de tabel (`demo_vul_ploegen()`, met soort, trainers en zwemmers), en elke training hangt aan haar ploegen (`tr_moment_ploegen`). De bevestigingen van voorbije trainingen blijven zoals voordien.
- **Tests**: `SoortenKalenderTest` (trainer zwemschool: start, menu, geen uitslagen, schema, voorkeuren, ploegen, aanvragen, combinatie met ouder; kalender: week, maand, dag, bevestigen vanuit de dag, vinkjes, afmelden, filters voor trainer en bestuur, ploegfilter, toegang) en `DemoTest.test_soorten_trainers_ploegen_en_kalender`.
- **Fout uit main hersteld**: `views/_layout.php` (PR #16) zette `$p = $pagina` en overschreef zo de ploeg `$p` in `views/ploeg.php`; de pagina van een ploeg toonde geen naam, beschrijving of formulier. Nu `$_pagina`.
- **Niet gedaan**: een vervangingsvraag gaat nog naar alle trainers (ook die van de andere soort). Dat zou kunnen per soort van de ploegen van de training. Kanalen per ploeg bestaan nog niet (communicatiemodule).

## Trainers: aanwezigheid van zwemmers, met statistiek (branch `wordpress/zwemmers-aanwezigheid`)

Vraag van de eigenaar: *"zwemmers moeten hun training niet bevestigen. het kan wel handig zijn voor een trainer dat die kan aangeven wie van zijn/haar zwemmers er die training waren. eender welke competitietrainer moet dat kunnen voor de competitie en zwemschool trainers voor de zwemschool (en bestuur)"*.

- **Branch**: vertrekt van `main` met #7 tot #21 erin. Enkel de WordPress-plugin; de trainersmodule bestaat niet in Django.
- **Geen bevestiging door zwemmers.** Nagekeken: de vraag *Was je aanwezig?* (`tr_vraag_aanwezigheid()`) gaat enkel naar de trainers die op de training staan (`tr_vast` en `tr_wijzigingen`). Er was geen melding of bevestiging voor zwemmers, er moest niets weg. De e2e-test controleert dat een ouder met een toestel na de cron geen melding krijgt.
- **Tabel** `tr_zwemmers` (`TR_DB_VERSIE` 4): moment, datum, zwemmer, ploeg, aanwezig (1/0), door, op. Unieke sleutel per training en zwemmer. Wie bewaart, bewaart de hele lijst (1 of 0 per zwemmer); geen rijen = niet aangeduid. Zo tellen de percentages enkel aangeduide trainingen. `ploeg_id` is de ploeg waaronder de zwemmer op de lijst stond: nodig voor het overzicht per ploeg en voor de rechten.
- **Rechten** (`tr_zw_mag_ploeg()` in `regels.php`), per ploeg en niet per training: `tr_mag_beheren()` (bestuur, beheerder, penningmeester, secretariaat) altijd; rol `trainer` voor ploegen met soort competitie, `trainer_zwemschool` voor de zwemschool. Bij een gemengde training ziet en bewaart elke trainer enkel de eigen soort (`tr_zw_ploegen_van()`); de andere ploegen blijven onaangeroerd, ook bij *wissen*.
- **Snelste invoer op de gsm**: iedereen staat standaard aangevinkt en de trainer vinkt de afwezigen af (meestal zijn de meesten er). Grote aanraakvlakken, knoppen *Iedereen aanwezig* / *Niemand*, een teller op de bewaarknop en een plakkende bewaarknop onderaan (een klein inline script, zonder bibliotheek; zonder JavaScript werkt het formulier ook). `views/_zwemmers.php`, opgenomen in `views/training.php`.
  - Vanaf de dag van de training (`tr_zw_kan()`), niet op een afgelaste training. Zwemmers die intussen uit de ploeg gingen, blijven op de lijst van een al aangeduide training. Een zwemmer in twee ploegen van dezelfde training staat één keer.
  - De telling per training zit in `tr_context()` (`$b->zwemmers`, één groepsquery), dus de lijsten (`_training.php`), de kalender en *Mijn trainingen* (sectie *Zwemmers aanduiden*: eigen trainingen van de voorbije week zonder aanduiding) kosten geen extra query per training.
- **Overzicht** `trainers/zwemmers/` (`v_tr_zwemmers()`, `tr_zw_overzicht()`): periode (standaard het lopende seizoen, anders drie maanden) en ploeg; per ploeg aangeduide trainingen en gemiddelde, per zwemmer aanwezig, afwezig en %. Export `trainers/zwemmers/export/` met `maak_xlsx()`. Wie geen enkele ploeg mag zien, krijgt 403.
- **Zwemmers en ouders** zagen in de eerste versie niets; na de uitbreiding hieronder zien ze hun eigen statistiek. Ook in de JSON van *Mijn gegevens* (`khzs_mijn_zwemmer`), voor het recht op inzage van de AVG. De privacyverklaring vermeldt de aanwezigheden (wie ze ziet en waarom). **Zet in de instellingen een nieuwe versie van de privacyverklaring** als je dit uitrolt, zodat de leden ze opnieuw bevestigen.
- **Opkuis**: een zwemmer verwijderen wist de aanwezigheden (`khzs_zwemmer_verwijderd`), een ploeg verwijderen ook die van de ploeg.
- **Demo**: aanwezigheden voor alle voorbije trainingen behalve de laatste twee dagen (die staan bij *Zwemmers aanduiden*). Elke zwemmer heeft een eigen kans tussen 60 en 97 %. Fout opgelost: `demo_terugzetten()` maakte nieuwe tabellen enkel aan bij een nieuwe `DB_VERSIE` van de kern. Nu ook bij een nieuwe `TR_DB_VERSIE` (optie `khzs_demo_tr_db_versie`), en die versie zit in `demo_code_versie()`.
- **Tests**: `ZwemmersAanwezigTest` (vóór de dag, lijst per soort, alles aangevinkt, *Zwemmers aanduiden* en tellingen in lijst en kalender, bewaren, soort van een andere trainer genegeerd, bestuur ziet alles, ouder 403 en geen push, overzicht met %, Excel, wissen per soort, afgelast, zwemmer verwijderen), `ZwemmersAanwezigTest.test_statussen_en_statistiek` (statussen, ziek wint van het vinkje, statistiek met beide percentages, Excel, ouder ziet het eigen kind, zwemmer zichzelf, ander gezin en trainer van de andere soort 403) en `DemoTest.test_aanwezigheid_zwemmers`.
- **Uitbreiding op vraag van de eigenaar** (zelfde branch): *"kan er voor die aanwezigheden ook een zeer uitgebreide statistiek gemaakt worden, bij de trainer en bestuur voor alle zwemmers, voor de zwemmers enkel voor zichzelf en voor de ouders voor die van de kinderen. Extra opties als te laat, of vroeger door gegaan, of ziek per zwemmer ingave zouden handig zijn"*
  - **Status per zwemmer**: kolom `status` in `tr_zwemmers` (`TR_DB_VERSIE` 5): leeg, `laat`, `vroeger` of `ziek` (`TR_ZW_STATUS`, labels in `TR_ZW_SOORT`). `aanwezig` blijft de bron voor "er of niet": te laat en vroeger weg zetten 1, ziek 0 (server én JavaScript). `tr_zw_soort()` geeft één van de vijf soorten. Bij het aanduiden een keuzelijst naast elke naam; de snelle werkwijze (iedereen aangevinkt) blijft.
  - **Statistiek**: `tr_zw_rijen()` haalt de rijen op (filter op ploegen en/of zwemmer), `tr_zw_stats()` telt alles in één lus (totaal, per ploeg, zwemmer, maand, training, weekdag, verdeling), `tr_zw_reeksen()` geeft de reeksen. Twee percentages: aanwezig (te laat en vroeger weg tellen mee) en zonder ziek (ziek telt niet in de noemer). Gedeelde weergaven: `views/_zw_tabel.php` (balk plus cijfers per soort: de tabel is meteen de toegankelijke weergave) en `views/_zw_legende.php`.
  - **Kleuren**: vijf eigen tokens (`--zw-aanwezig`, `--zw-laat`, `--zw-vroeger`, `--zw-ziek`, `--zw-afwezig`) in `_stijl.php`, apart voor licht en donker, nagekeken met de palettevalidator van de dataviz-richtlijnen. Ziek is bewust neutraal grijs (verwittigd afwezig). Groen en amber zitten voor rood-groenblinden in de grensband, daarom staan er altijd een legende, een tooltip per stuk en de cijfers naast de balk.
  - **Zwemmers en ouders**: `aanwezigheid/<id>/` (`v_tr_zwemmer_stats()`), rechten in `tr_zw_mag_zwemmer()`: gekoppelde accounts (zwemmer zelf, ouders) en `tr_mag_beheren()` alles; een trainer enkel als hij of zij een ploeg van de zwemmer mag zien, en dan enkel de rijen van die ploegen. Kaartje op de zwemmerpagina via de nieuwe actie `khzs_zwemmer_pagina` (één regel in `modules/inschrijvingen/views/zwemmer.php`), zodat de module Inschrijvingen niets van de trainers hoeft te weten. Andere zwemmers zijn er nooit zichtbaar, enkel het ploeggemiddelde.
  - Privacyverklaring aangepast: zwemmers en ouders zien hun eigen aanwezigheden.
- **Niet gedaan**: zwemmers laten afmelden voor een training (RSVP), een vrije opmerking per zwemmer, en een melding aan de trainer als de zwemmers van een training na een dag nog niet aangeduid zijn.

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
- **Documentatie bijgewerkt** (branch `docs/khzs-leden-bijwerken`): de PDF-handleidingen, screenshots en filmpjes in `docs/` tonen de naam KHZS Leden en het nieuwe icoon, en leggen het koppelen van zwemmers (koppelcode) en *Ik zwem zelf* / *+ Mezelf* uit. `docs/build.py` en `docs/video.py` werken nu ook op Windows (`.venv/Scripts/python`). Op de gsm heet het menu *Profiel*, op de pc *Mijn zwemmers*.
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
10. De plugin in productie ook op `leden.khzs.be` laten werken (branch `wordpress/subdomein`).

## Mogelijke volgende stappen

- Waarschuwing als een trainer een jaarbedrag nadert (bv. het plafond van de vrijwilligersvergoeding), instelbaar in wp-admin.
- Groepen (A-groep, B-groep…) als gedeelde gegevens in de kern. Inschrijvingen kan dan een wedstrijd beperken tot een groep, en Trainers kan een groep aan een training koppelen.
- RSVP voor zwemmers (afmelden voor een training, activiteiten). De aanwezigheid aanduiden door de trainer bestaat (branch `wordpress/zwemmers-aanwezigheid`).
- Testen op echte toestellen: installeren op Android en iPhone, en pushmeldingen.
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
- `demo_personen()` geeft per account de huidige app-rol (`khzs_rol`) en de lijst rollen voor meerdere rollen per lid (`khzs_rollen`, zoals in PR #7). Zodra PR #7 gemerged is, hebben de demo-accounts dus meteen hun rollen. De accounts `trainer`, `zwemmer`, `ouder` en `bestuur` hebben elk één rol, met als wachtwoord de gebruikersnaam.
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

## Websitekalender: wedstrijden synchroon met The Events Calendar (branch `wordpress/kalender-sync`)

Vraag van de eigenaar: *"we hebben de kalender/evenementen op de website al, kunnen daar automatisch de wedstrijden van de app in gestoken/synchroon gehouden worden? Alle informatie van de wedstrijd die publiek is ook naar een evenement toe, die ook synchroon houden als er zaken veranderen enzo of weg gaan"*.

**Beslissingen**
- **In de plugin, met de PHP-API** van The Events Calendar (`tribe_events()`, `tribe_create_venue()`, `tribe_create_organizer()`), geen REST-calls naar de eigen site. Alles staat in `modules/inschrijvingen/kalender.php` en de view `kalender.php`.
  - Zonder The Events Calendar (`tec_actief()`) gebeurt er niets; in de demo nooit (`is_demo()`, `demo_enkel()` en een controle op `khzsdemo` in de tabelnaam).
  - Standaard **uit**: na installatie op www.khzs.be zet iemand het bewust aan.
- **Haken** (klein gehouden in de bestaande code):
  - `do_action('khzs_wedstrijd_bewaard', $wid)` in `importeer()` (nieuwe Lenex en nieuwe versie), `v_instellingen()`, `v_nakijken()` (info), bij een bijlage toevoegen of verwijderen;
  - `do_action('khzs_wedstrijd_verwijderd', $wid)` in `verwijder_wedstrijd()`;
  - `do_action('khzs_bijlage_verwijderd', $id)`.
  - Andere modules of latere code kunnen dezelfde haken gebruiken.
- **Koppeling**: post-meta `_khzs_wedstrijd_id` op het evenement. Geen nieuwe kolom, dus geen databaseversie en geen botsing met andere PR's.
  - Na het verwijderen van een wedstrijd: `_khzs_was_wedstrijd_id`.
  - *Nu alles synchroniseren* kijkt ook evenementen na waarvan de wedstrijd niet meer bestaat (bv. na een teruggezette backup).
- **Handmatige wijzigingen**: na elke synchronisatie bewaart de app een vingerafdruk (`_khzs_vingerafdruk`) van wat de app beheert: titel, tekst, begin, einde, hele dag, locatie, website-link en organisator.
  - Verschilt die bij de volgende synchronisatie, dan wordt **niets overschreven**. Het evenement krijgt `_khzs_conflict` en staat in de app bij *Websitekalender*, met *Overschrijven met de app* of *Niet meer bijwerken* (`_khzs_vast`).
  - Statuswijzigingen (annuleren en terug actief) gelden wel, want die raken de inhoud niet. Een evenement met `_khzs_vast` blijft volledig onaangeroerd.
  - Een evenement dat iemand op de website zelf in de prullenbak gooide, haalt de app niet terug.
  - Categorieën: de app voegt haar categorie toe en laat andere staan; die tellen niet mee in de vingerafdruk.
  - Locaties en organisatoren worden op naam gezocht en enkel aangemaakt als ze niet bestaan, nooit gewijzigd.
- **Niet meer publiek of verwijderd** (keuze uitgelegd):
  - Een **gepubliceerd** evenement dat nog moet komen, krijgt de status *Geannuleerd* (`_tribe_events_status = canceled`, een functie van The Events Calendar zelf). Bezoekers, gedeelde links en agenda-abonnementen (iCal) zien dan dat het niet doorgaat, in plaats van een evenement dat zonder uitleg verdwijnt.
  - Een evenement dat **nooit gepubliceerd** was (concept), heeft niemand gezien: dat gaat naar de prullenbak (`_khzs_weg_door_app`), en komt terug als de wedstrijd opnieuw publiek wordt.
  - Een **voorbij** evenement blijft staan: het heeft plaatsgevonden en hoort bij de historiek.
  - Wordt de wedstrijd opnieuw publiek, dan wordt hetzelfde evenement weer actief (`scheduled`).
- **Publieke informatie**:
  - wel: naam, sessies (datum, inzwemmen, start), zwembad als locatie, badlengte, organisator;
  - het adres van het zwembad, de organisator en de einduren komen uit het bewaarde Lenex-bestand (`kalender_lenex()`), want die staan niet in de database;
  - het einde is het einduur van de laatste sessie uit de Lenex, of anders de start van de laatste sessie + 3 uur;
  - nooit inschrijvingen, zwemmers, deadlines of kosten. De laatste inschrijfdatum van de organisator gaat ook niet mee: die is enkel voor het secretariaat.
  - *Info voor zwemmers*: standaard **niet**, want die kan intern zijn (bv. busregeling). Aan te zetten bij *Websitekalender*.
  - Bijlagen: enkel als je ze per bijlage als **publiek** (🌐) aanduidt (optie `khzs_kalender_bijlagen`, een lijst van id's). Zo'n bijlage kan iedereen zonder aanmelden downloaden via `/leden/bijlage/<id>/`, zolang de wedstrijd een publieke status heeft.
- **Instellingen**: opties `khzs_kalender_aan`, `_statussen`, `_categorie`, `_publiceren`, `_info`, `_bijlagen`, `_fout` (laatste fout) en `_laatst`. Ze staan in de app op `/leden/kalender/` (sportsecretariaat en beheerders). In wp-admin staan de stand en de knop.
- **Fouten** breken de app nooit: `kalender_sync()` vangt alles op, schrijft naar het PHP-foutlog en toont de laatste fout bij *Websitekalender*.
- **Django synchroniseert niet** (beslist door de eigenaar, 14/11/2026): enkel de plugin vult de websitekalender. Twee schrijvers op dezelfde kalender zouden botsen zodra de plugin live gaat, en een applicatiewachtwoord is een extra geheim op de server. Gevolg: de kalender op www.khzs.be vult zich pas als de plugin Django vervangt.

**Stand**
- `KalenderTest` in `tests/test_e2e.py` (8 scenario's) met The Events Calendar 6.18.4: aanmaken, wijzigen, nieuwe Lenex, statuswijziging, annuleren, prullenbak, voorbij, handmatige wijziging, de knop, publieke bijlagen en de demo. Van de 94 tests slagen er 93; één test werd al vóór deze wijziging overgeslagen.
  - `testsite.sh` installeert The Events Calendar via wpackagist (`KHZS_TEC=0` slaat dat over). Zonder die plugin worden de tests overgeslagen.
- Nog **niet** op www.khzs.be geprobeerd.

**Open bij de eigenaar**
- Bij de installatie: eerst met *Nieuwe evenementen: als concept* proberen, de categorie nakijken, en daarna *Nu alles synchroniseren*.

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

## Rollen: meerdere per account, met aanvragen (branch `feature/rollen`)

Vraag van de eigenaar: elk lid moet één of meerdere rollen kunnen hebben (bestuurder, trainer, zwemmer, ouder, sportsecretaris…). Leden vragen rollen aan; bestuurders en beheerders keuren goed, keuren af of geven een andere rol. Gebouwd in Django én WordPress, met dezelfde werking.

- **Rollen**: beheerder, sportsecretaris, trainer, bestuurder, ouder, zwemmer (van hoog naar laag). De rechten van alle rollen van een account tellen samen. De hoogste rol is de *hoofdrol*, enkel voor weergave (bv. "Trainer Tom" in een melding).
- **Beslissingen van de eigenaar** (14/11/2026):
  - *Bestuurder* beheert leden en rollen: het scherm *Gebruikers*, aanvragen behandelen, rollen toekennen en afnemen. Geen wedstrijden, inschrijvingen, uitslagen of backup, tenzij de persoon ook een andere rol heeft.
  - *Sportsecretaris* behoudt het scherm *Gebruikers* (zoals vroeger) en kan ook aanvragen behandelen.
  - *Zwemmer* en *ouder* krijg je meteen, zonder goedkeuring. *Ouder* heeft dezelfde rechten als zwemmer. Trainer, sportsecretaris en bestuurder vragen goedkeuring. Beheerder kan je niet aanvragen; enkel een beheerder stelt beheerders aan.
- **Werking**:
  - Aanvragen bij registratie (*Ik ben…*) of later via *Mijn rollen* (`/rollen/`, link bij *Mijn zwemmers* en *Meldingen*). Een open aanvraag kan het lid intrekken.
  - Bovenaan *Gebruikers* staan de open aanvragen: *Goedkeuren*, *Afkeuren* (reden verplicht) of *Deze rol geven* (een andere rol in de plaats). In de tabel staan de rollen als labels: ✕ neemt een rol af, *+ rol* voegt er een toe. Een account houdt minstens één rol.
  - Wie een rol rechtstreeks toekent waarvoor een aanvraag openstaat, keurt die aanvraag meteen goed.
  - Melding voor het lid: een balk bovenaan elke pagina tot het *Mijn rollen* opent, plus een pushmelding. Bestuurders en sportsecretarissen krijgen een push bij een nieuwe aanvraag (voorkeur *(bestuur) Nieuwe rolaanvraag*).
  - Meldingen voor zwemmers (nieuwe wedstrijd, deadline) gaan naar zwemmers én ouders (`GEZIN`).
- **Django**:
  - Modellen `Rol` (account + rol, meerdere per account) en `Rolaanvraag`. `Profiel.rol` blijft bestaan als hoofdrol.
  - Migratie `0012_rollen`: elk account krijgt de rol uit `Profiel.rol`; superusers krijgen ook beheerder; accounts zonder profiel worden zwemmer. Zo verliest niemand rechten. Getest in `MigratieTest`, ook terug naar 0011.
  - Logica in `inschrijvingen/rollen.py`. Rechten controleren met `heeft_rol(user, *rollen)`; `regels.rol()` geeft de hoofdrol.
  - Een backup van vóór de rollen terugzetten werkt: accounts zonder rollen krijgen hun hoofdrol (`vul_ontbrekende()`).
  - `export_wordpress` zet de rollen in `khzs_rollen` en neemt de rolaanvragen mee.
  - Tests: 14 nieuwe in `inschrijvingen/tests/test_rollen.py`; samen 98, alle geslaagd.
- **WordPress**:
  - Rollen in de user meta `khzs_rollen` (komma-gescheiden); `khzs_rol` houdt de hoofdrol bij. Een account met enkel `khzs_rol` houdt die rol, dus er is geen datamigratie nodig. WordPress-beheerders zijn altijd ook beheerder.
  - Tabel `khzs_rolaanvragen` (`DB_VERSIE` 4), logica en pagina's in `includes/rollen.php`. Op het WordPress-profiel zijn de rollen nu vinkjes.
  - Tests: 7 nieuwe in `RollenTest` (`test_e2e.py`); samen 84, alle geslaagd. Gedraaid in een Docker-container (Debian trixie, PHP 8.4, MariaDB), omdat PHP en MariaDB niet op de Windows-pc staan. Op Windows moet `testsite.sh` eerst LF-regeleindes krijgen.
- **Niet gedaan**: de handleidingen en screenshots in `docs/` tonen de rollen nog niet. De penningmeester blijft een aparte vlag, geen rol.
- **Status**: PR #7 naar `main`, nog niet samengevoegd. Samenvoegen zet de migratie automatisch op de productieserver.
- **Later aangepast** (branch `feature/ploegen`): een nieuw account start zonder rol en kiest in het stappenplan; zie hieronder.

## Ploegen en registratie in stappen (branch `feature/ploegen`, bovenop `feature/rollen`)

Vraag van de eigenaar: elke zwemmer kiest in welke ploeg hij of zij zwemt, met meerdere ploegen, als basis voor een latere communicatiemodule waarin mensen enkel de groepen zien waartoe ze behoren. Daarna: rollen en ploeg pas kiezen na de registratie, in een stappenplan.

- **Beslissingen van de eigenaar** (14/11/2026):
  - De staf keurt een gekozen ploeg eerst goed: een trainer van die ploeg, een bestuurder, sportsecretaris of beheerder.
  - Ploegen aanmaken en beheren: bestuurder, sportsecretaris en beheerder. Zij duiden ook de trainers van een ploeg aan.
  - Trainers, bestuurders, sportsecretarissen en beheerders zien alle ploegen; zwemmers en ouders enkel de ploegen van hun zwemmers.
  - Alles gebeurt in de app zelf (ook op de gsm), niet via `/beheer/` of wp-admin.
- **Werking**:
  - *Ploegen* (`/ploegen/`): lijst, nieuwe ploeg, open aanvragen. Per ploeg: zwemmers, aanvragen, zwemmer rechtstreeks toevoegen, uit de ploeg halen, bewerken, archiveren (*Actief* uit) of verwijderen.
  - Bij de zwemmer: blok *Ploegen* met de status per ploeg, *Ploeg kiezen* en ✕ om in te trekken of uit de ploeg te gaan. Een zwemmer kan in meerdere ploegen zitten.
  - Wie de ploeg mag behandelen (staf), zet een zwemmer er meteen in. Afkeuren vraagt een reden; daarna kan je opnieuw aanvragen.
  - Melding voor de ouders: balk bovenaan tot ze de zwemmer openen, en een pushmelding. De behandelaars krijgen een push bij een nieuwe aanvraag.
  - Nieuwe pushvoorkeuren onder *Meldingen*: *Mijn aanvraag voor een rol of ploeg is behandeld* (iedereen) en *(staf) Nieuwe ploegaanvraag* (bestuur, secretariaat, beheer en trainers van een ploeg). De testknop bestond al.
- **Registratie in stappen** (`/welkom/`): het formulier vraagt enkel e-mail, naam en wachtwoord. Daarna één vraag per scherm, elk over te slaan en later opnieuw te doen (link *Stappenplan* bij *Mijn rollen*):
  1. *Zwem je zelf?* → je eigen gegevens en ploeg; je krijgt de rol zwemmer.
  2. *Heb je kinderen die zwemmen?* → kind toevoegen met ploeg, of koppelen met de koppelcode van de andere ouder; je krijgt de rol ouder.
  3. *Help je mee bij de club?* → aanvraag voor trainer, bestuurder of sportsecretaris.
  4. Overzicht.
  - Een nieuw account start **zonder rol** (Django: lege `Profiel.rol`; WordPress: lege `khzs_rollen`). Het allereerste account van een Django-installatie blijft sportsecretaris.
  - Ook later geeft een zwemmer toevoegen of koppelen automatisch de rol zwemmer (jezelf) of ouder.
- **Voor de latere communicatiemodule** bestaat al: `ploegen.zwemmers_van`, `accounts_van` (accounts van de zwemmers, ook gekoppelde ouders, en de trainers van de ploeg), `lezers_van` (dat plus iedereen die alle ploegen ziet), `zichtbare_ploegen(user)`. In WordPress: `ploeg_zwemmers`, `ploeg_accounts`, `ploeg_lezers`, `zichtbare_ploegen`.
- **Django**: modellen `Ploeg` (met `trainers`) en `PloegLid` (zwemmer, ploeg, status, reden, gelezen); migratie `0013_ploegen`. Logica in `inschrijvingen/ploegen.py`. Tests: `test_ploegen.py` (9); de registratietests zijn omgezet naar het stappenplan. Samen 107, alle geslaagd.
- **WordPress**: tabellen `khzs_ploegen`, `khzs_ploeg_trainers`, `khzs_ploegleden` (`DB_VERSIE` 5), `includes/ploegen.php`, `views/ploegen.php` en `views/ploeg.php`, stappenplan in de module Inschrijvingen (`v_welkom`). Menu-item *Ploegen* (sleutel 45; 40 is *Verloning*). Tests: `PloegenTest` (8) en de omgezette registratietests; samen 92, alle geslaagd (Docker, zoals bij de rollen).
- `export_wordpress` neemt de ploegen, trainers en leden mee.

## Communicatiemodule (branch `wordpress/communicatie`)

Wensen van de eigenaar (14/11/2026), verzameld in de loop van de dag:
- chatten zoals WhatsApp per kanaal, met vastgepinde berichten (trainers en bestuur);
- opbouw zoals Discord: *"enkele grote secties (algemeen, competitie, zwemschool) met daaronder kanalen per ploeg bijvoorbeeld, algemeen, wedstrijden en daaronder eventueel nog aparte threads (per wedstrijd, per onderwerp van de competitie)"*;
- een kanaal of thread per wedstrijd met de trainers, het bestuur en de deelnemers met hun ouders, dat de deelname vanzelf volgt;
- aankondigingen bovenaan (*Informatief*, *Dringend*, *Aankondiging*, *Vraag naar hulp*), met bij een hulpvraag ja / nee / misschien en met hoeveel personen, een lijst en een Excel voor het bestuur;
- enkel webpush, met een schakelaar per soort melding en dempen per kanaal;
- alles beheren in de app, goed op de gsm; geen externe bibliotheken, polling volstaat.

**Beslissingen van de eigenaar** (14/11/2026, gevraagd voor het bouwen):
- Wie *Ik doe niet mee* kiest of geweigerd wordt, **blijft in de wedstrijdthread** (tabel `com_wedstrijdzwemmers`: wie er ooit in kwam, blijft).
- Een **trainer van de zwemschool** ziet enkel wat niet over de competitie gaat: geen sectie Competitie, geen wedstrijden, geen competitieploegen (wel als ouder van een zwemmer daar).
- **Leden posten: instelbaar per kanaal** (*zwemmers en ouders mogen posten*). Threads nemen de instelling van hun kanaal over.
- **Foto's en pdf's enkel voor trainers en bestuur** (5 MB, foto's verkleind tot 1600 px, in de privémap).
- **Bewerken en verwijderen**: eigen berichten, plus moderatie (verwijderen) door wie het kanaal als staf ziet.
- **Bewaren 2 jaar**, vastgepinde berichten blijven; wedstrijdthreads een jaar na de wedstrijd naar het archief.
- **Enkel in de plugin**, niet in Django (zoals de trainersmodule en de websitekalender).

**Zelf gekozen** (staat ook in de PR):
- **Eén tabel voor kanalen en threads** (`com_kanalen`, `ouder_id` = kanaal van de thread). `toegang`: `iedereen`, `competitie`, `zwemschool`, `ploeg`, `wedstrijd`, `staf` of `erven` (thread volgt zijn kanaal). `functie`: `ploeg` (automatisch kanaal van een ploeg) of `wedstrijden` (het kanaal waaronder de wedstrijdthreads komen).
- **Standaardopbouw** bij het eerste gebruik (`com_standaard_opbouw()`): Algemeen (*Algemeen*: leden lezen enkel; *Trainers en bestuur*), Competitie (*Competitie algemeen*, *Wedstrijden*), Zwemschool (*Zwemschool algemeen*).
- **Synchroon zonder extra haken in de inschrijvingen** (`com_sync()`, één keer per verzoek, bij Berichten en na `khzs_wedstrijd_bewaard`): ploegen zonder kanaal krijgen er een (naam en archief volgen de ploeg), wedstrijden die niet meer in concept staan krijgen een thread, en de deelnemers (interesse, ik doe mee, of een inschrijving) komen met `INSERT IGNORE` in `com_wedstrijdzwemmers`. Een verwijderde ploeg of wedstrijd zet het kanaal in het archief.
- **Bestuur** in deze module = sportsecretaris, bestuurder en beheerder (zoals `PLOEGBEHEER`): zij beheren secties, kanalen en alle aankondigingen. Een trainer beheert het kanaal van de eigen ploeg (beschrijving, leden posten, archief) en maakt daar aankondigingen.
- **Staf in een kanaal** (lezen, posten, vastpinnen, threads, bijlagen, berichten van anderen verwijderen): wie alle ploegen ziet (`ALLE_PLOEGEN`), de trainer van die ploeg, en de trainer van de zwemschool buiten de competitie. Threads maken enkel de staf.
- **Meldingen voor nieuwe berichten gaan naar wie bij het kanaal hoort** (`com_is_lid()`), niet naar iedereen die het ziet: anders krijgt een bestuurder elk bericht van elke ploeg. Wedstrijd: deelnemers, trainers van de competitie en het secretariaat. Wie alleen meekijkt, kan het kanaal volgen (🔔). Per kanaal en account: `com_status.meldingen` (NULL = standaard, 1 = aan, 0 = gedempt); een thread zonder eigen keuze volgt de keuze voor zijn kanaal.
- **Vermeldingen** (`@Voornaam Naam`) enkel uit `com_vermeldbaar()`: wie er schreef, trainers en bestuur, en de leden van de ploeg of wedstrijd, gefilterd op wie het kanaal ziet. Geen lijst van alle leden voor iedereen (AVG). Een vermelding komt door, ook in een gedempt kanaal of met de voorkeur voor gewone berichten uit.
- **Pushmelding met `tag`** per kanaal: `stuur()` in `includes/push.php` kreeg een optionele parameter `$extra`, die mee in het bericht gaat. De service worker kende `tag` al.
- **Polling elke 8 seconden** (`berichten/kanaal/<id>/nieuw/?na=<id>`) zolang de pagina zichtbaar is; versturen met `fetch` en de header `X-KHZS-Ajax` (antwoord in JSON). Bewerken, verwijderen en vastpinnen herladen de pagina.
- **Ongelezen**: `com_status.gelezen_tot`. Het getal bij *Berichten* telt enkel kanalen met meldingen aan, plus nieuwe aankondigingen (user meta `khzs_com_aank_gezien`). Daarvoor kreeg een menu-item een optioneel zesde element (callable met een aantal) in `views/_layout.php`, met `.tab-badge` in `app.css` (vóór de svg, zodat de test van de onderbalk de labels blijft vinden).
- **Aankondigingen**: voor iedereen (`kanaal_id` 0) of voor wie een kanaal ziet. Op het overzicht hoogstens drie (dringend eerst). Leden zien bij een hulpvraag enkel de totalen, het bestuur en de maker ook de namen en de Excel (`maak_xlsx`).
- **Menu**: *Berichten* staat op plaats 15 (na *Wedstrijden*). Op de gsm schuift daardoor *Meldingen* bij een ouder onder *Meer*; de test van de onderbalk is aangepast.
- **Knop *💬 Wedstrijdchat*** op de pagina van een wedstrijd via een nieuwe actie `khzs_wedstrijd_knoppen` in `modules/inschrijvingen/views/wedstrijd.php`.
- **Bestaande installaties**: de module staat aan als `khzs_modules` nooit bewaard werd, anders zet je ze aan in wp-admin.
- **Tabellen** via een eigen `COM_DB_VERSIE` (zoals `TR_DB_VERSIE`), dus `DB_VERSIE` van de kern bleef 6.

**Getest**: `CommunicatieTest` (8 scenario's: opbouw en wie ziet wat; posten, meldingen, dempen, volgen, vermelden en het getal in het menu; versturen zonder herladen en vernieuwen; bewerken, verwijderen, vastpinnen en bijlagen; threads en beheer; de wedstrijdthread die de deelname volgt, met archief; aankondigingen en hulpvraag met Excel; voorkeuren en AVG), `DemoTest.test_berichten` en de aangepaste `TabbalkTest`, op een Docker-testsite. Met de browser nagekeken op gsm-formaat in de demo: overzicht, chat, @-suggesties, versturen zonder herladen, lijst van een hulpvraag.

**Niet gedaan** (mogelijke volgende stappen):
- reacties (emoji) en antwoorden op een bepaald bericht;
- leesbevestiging per bericht (*gezien door*);
- zoeken in berichten;
- een thread per onderwerp door leden zelf laten starten;
- een kanaal per wedstrijd dat ook de officials of de chauffeurs bevat (nu enkel deelnemers en staf);
- testen op een echt toestel (pushmeldingen met `tag`, iPhone).

## AVG / GDPR (branch `feature/avg`, bovenop `feature/ploegen`)

Vraag van de eigenaar: *"zaken als AVG melding/pagina, GDPR, cookie regeling?"* Gebouwd in Django én WordPress, met dezelfde werking.

**Inventaris van de persoonsgegevens**

| Gegevens | Django | WordPress | Wie ziet het |
|---|---|---|---|
| Account: naam, e-mail, wachtwoordhash, laatste aanmelding | `auth_user` | `wp_users`, user meta `khzs_laatst_aangemeld` | zelf, rolbeheer, beheer |
| Rollen, rolaanvragen (met motivatie en reden), penningmeester | `Rol`, `Rolaanvraag`, `Profiel.rol` | user meta `khzs_rollen`, tabel `khzs_rolaanvragen` | zelf, rolbeheer |
| Akkoord privacyverklaring (datum, versie) | `Profiel.privacy_*` | user meta `khzs_privacy_*` | beheer |
| Zwemmers: naam, geboortedatum (vaak minderjarig), geslacht, licentie, Swimrankings-ID, toestemming ouder | `Zwemmer` | `khzs_zwemmers` | gekoppelde accounts, staf |
| Gekoppelde accounts en koppelcodes | `extra_accounts`, `koppelcode` | `khzs_zwemmer_accounts` | gekoppelde accounts, staf |
| Ploegen, trainers van een ploeg, ploegaanvragen | `Ploeg`, `PloegLid` | `khzs_ploegen`, `_ploeg_trainers`, `_ploegleden` | zie *Ploegen* hierboven |
| Beste tijden, deelname, inschrijvingen, meldingen van wijzigingen | `BesteTijd`, `Deelname`, `Inschrijving`, `Melding` | `khzs_tijden`, `_deelnames`, `_inschrijvingen`, `_meldingen` | gekoppelde accounts, staf; inschrijvingen ook naar organisator en federatie (Lenex) |
| Pushmeldingen: endpoint, sleutels, browser, voorkeuren | `PushAbonnement`, `Profiel.push_*` | `khzs_push`, user meta `khzs_push_*` | beheer; pushdiensten (Google, Mozilla, Apple, Microsoft) krijgen versleutelde berichten |
| Trainers: vaste trainingen, afmeldingen en vervangingen, uren, tarieven, uitbetalingen | (niet in Django) | `khzs_tr_*` | staf; tarieven en verloning enkel penningmeester en beheer |
| Backups | zip + `pg_dump`, 30 dagen | zip in de privémap, 30 dagen | beheer |
| Logboeken (IP-adressen) | nginx/gunicorn, logrotate | hosting van de website | beheer, hosting |

**Cookies en opslag**: allemaal strikt noodzakelijk, dus **geen cookiebanner** nodig voor de app. Een vermelding in de privacyverklaring volstaat.
- Django: `sessionid` (90 dagen), `csrftoken`, `messages`.
- WordPress: `wordpress_logged_in_…`/`wordpress_sec_…` (14 dagen), `wordpress_test_cookie`, `khzs_f` (melding voor wie niet aangemeld is); in de demo enkel `khzs_demo_sessie`.
- Lokale opslag: `thema` (licht/donker), `khzs_pwa_weg` (‘Niet nu’ bij de installatiemelding, ook op de rest van de website).
- Service worker: cache van opmaak, logo en offlinepagina; pushabonnement.
- Geen statistieken, trackers, externe lettertypes of scripts. Cloudflare kan `__cf_bm` zetten (beveiliging, strikt noodzakelijk). **Let op**: de rest van www.khzs.be (thema, andere plugins, ingesloten video's…) valt hierbuiten en kan wel een cookiebanner vragen.

**Beslissingen**
- **Privacyverklaring** in de app (`/privacy/`, in WordPress `/leden/privacy/`), zonder aanmelden. Het is een sjabloon in duidelijk Vlaams; de club laat het juridisch nakijken. De gegevens van de club zijn instelbaar: Django via `PRIVACY_*` in `/etc/khzs.env`, WordPress via *Instellingen → KHZS Leden*.
- **Versie**: wie de huidige versie nog niet aanvaardde (ook alle bestaande accounts), ziet een melding met een link; op de privacypagina bevestigen ze met één knop. Een nieuwe versie instellen vraagt iedereen opnieuw te lezen.
- **Registratie**: verplicht vinkje voor de privacyverklaring. **Toestemming van een ouder of voogd** bij elke nieuwe zwemmer jonger dan 18 jaar (ook in het stappenplan); voor bestaande minderjarige zwemmers vraagt de zwemmerpagina het achteraf. Rechtsgrond in de tekst: overeenkomst en gerechtvaardigd belang; toestemming voor pushmeldingen.
- **Rechten in de app** (*Mijn gegevens*, link bij *Meldingen* en *Uitleg*): download als JSON, en account verwijderen met wachtwoord en het woord VERWIJDER. Gedeelde zwemmers blijven bij het andere account; de rest verdwijnt mee.
- **Bewaarplicht boekhouding** (enkel WordPress, module Trainers): wie trainingen gaf of vergoed werd, wordt *afgesloten* in plaats van verwijderd. Geen aanmelden meer, e-mail en andere gegevens weg, enkel de naam bij uren en uitbetalingen. 10 jaar na het boekjaar van de laatste prestatie (`BOEKHOUDING_JAREN`, filter `khzs_bewaarplicht`) wist de opkuis het account en de trainersgegevens.
- **Niet zelf te verwijderen**: beheerders van de website (WordPress), accounts met `/beheer/` (Django), auteurs van berichten op de website, en de demo. De demo toont alle AVG-functies maar verwijdert niets en verstuurt niets.
- **Opkuis**: Django `manage.py opkuis`, dagelijks mee met de herinneringen (`update.sh` installeert geen nieuwe timers); WordPress WP-Cron `khzs_opkuis` om 03:00. Termijnen: meldingen, behandelde aanvragen en het logboek van herinneringen 365 dagen; pushabonnementen na 365 dagen zonder aanmelden; verlopen koppelcodes; verlopen sessies (Django).
- WordPress: de gegevens van de app zitten ook in *Extra → Persoonsgegevens exporteren*. Modules haken in met de filters `khzs_mijn_zwemmer`, `khzs_mijn_gegevens`, `khzs_bewaarplicht`, `khzs_opkuis_aantallen` en de actie `khzs_bewaartermijn_voorbij` (zie `includes/avg.php`).

**Technisch**
- Django: `inschrijvingen/avg.py`, migratie `0014_avg`, sjablonen `privacy.html` en `mijn_gegevens.html`, commando `opkuis`. Tests: `test_avg.py` (12). In `herinneringen` staat het aanmaken van een herinnering nu in een savepoint, anders breekt een dubbele herinnering de opkuis erna.
- WordPress: `includes/avg.php`, `views/privacy.php` en `views/mijn_gegevens.php`, kolommen `toestemming_op`/`toestemming_door` in `khzs_zwemmers` (`DB_VERSIE` 6). Tests: `AvgTest` in `test_e2e.py`.
- `export_wordpress` neemt het akkoord en de toestemming mee.
- **Bij het samenvoegen**: andere branches kunnen ook `DB_VERSIE` verhogen; neem dan de hoogste en controleer dat `installeer_db()` alle kolommen heeft. De Django-migratie `0014_avg` hangt af van `0013_ploegen`.

**Nog te doen door de club** (vragen aan de eigenaar)
- De gegevens van de club invullen: juridische naam (vzw), adres, ondernemingsnummer, contactadres voor privacyvragen.
- Staat er al een privacyverklaring of cookieverklaring op www.khzs.be? Dan best op elkaar afstemmen en naar elkaar linken.
- De tekst laten nakijken, en de bewaartermijn voor de boekhouding (nu 10 jaar) laten bevestigen door de penningmeester of boekhouder.
- Verwerkersovereenkomsten nakijken met de hosting en Cloudflare, en het register van verwerkingsactiviteiten van de club aanvullen met deze app.
