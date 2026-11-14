# Overdracht: WordPress-plugin KHZS

Dit document vat samen wat er in de sessie met Claude Code (14/11/2026) beslist en gebouwd is, zodat iemand anders of een volgende sessie meteen verder kan. Lees daarna `wordpress/README.md` voor installatie en gebruik.

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
   - Op de website en in de app verschijnt een melding "Installeer de HZS-app".
   - Browsers tonen hun installatievenster alleen binnen de scope van de app. Daarom gaat de knop op de rest van de website naar `/club/installeer/`.
7. **Modules**: de plugin heette eerst `khzs-inschrijvingen` en is daarna omgebouwd tot één plugin **KHZS** met een kern en modules. Het adres werd `/club/`; het oude `/inschrijvingen/` verwijst door (301).
8. **Module Trainers**, op vraag van de club: trainers duiden aan welke trainingen ze normaal geven en melden zich af als ze niet kunnen. De penningmeester koppelt bedragen aan trainers en ziet per maand de uren en de verloning. Gekozen werking:
   - vaste trainers per trainingsmoment (`tr_vast`, met periode) plus wijzigingen per datum (`tr_wijzigingen`: afwezig, vervanger, extra)
   - pushmelding naar collega's bij een afmelding, en naar de afgemelde trainer als iemand overneemt
   - uren = trainingen tot en met vandaag waarop iemand aanwezig was, behalve afgelaste
   - tarief per trainer, per uur of per training, met *geldig vanaf*, zodat oude maanden niet veranderen
   - *uitbetaald* bewaart een momentopname; bij een latere correctie toont het overzicht "gewijzigd na uitbetaling"
   - de penningmeester is een vlag (`khzs_penningmeester`) los van de rol en kan ook een ouder zijn; enkel een beheerder kan die vlag zetten
   - **geen loonberekening**: wettelijke plafonds (vrijwilligersvergoeding, verenigingswerk) zitten niet in de plugin; de Excel-export is voor de boekhouding

## Stand van zaken

- Alles staat op **`main`** (PR #1). De map `wordpress/` en `export_wordpress` raken de draaiende Django-app niet. Werk verder op een branch: elke commit op `main` komt binnen 10 minuten op de server.
- **Tests**: `wordpress/tests/test_e2e.py`, 63 scenario's: alle Django-scenario's plus de trainersmodule. Ze slaagden allemaal.
- **Migratie** getest met de Django-demodata: alle gegevens komen over en oude wachtwoorden werken.
- **Push**: de versleuteling en de VAPID-handtekening zijn gecontroleerd met de Python-bibliotheek `cryptography`. Nog niet getest op een echt toestel.
- **Nog niet geïnstalleerd** op www.khzs.be. De plugin-zip maak je met `wordpress/bouw.sh`.
- **Bekend, los van de plugin**: de Django-test `test_trainer_wijzigt_en_zwemmer_ziet_melding` faalt sinds de testwedstrijd (oktober 2026) voorbij is. De test is datumafhankelijk.

## Mogelijke volgende stappen

- Waarschuwing als een trainer een jaarbedrag nadert (bv. het plafond van de vrijwilligersvergoeding), instelbaar in wp-admin.
- Groepen (A-groep, B-groep…) als gedeelde gegevens in de kern. Inschrijvingen kan dan een wedstrijd beperken tot een groep, en Trainers kan een groep aan een training koppelen.
- RSVP of aanwezigheden voor zwemmers (afmelden voor een training, activiteiten).
- Testen op echte toestellen: installeren op Android en iPhone, en pushmeldingen.
- Installatie op www.khzs.be: zip uploaden, permalinks controleren, zelf registreren eerst uitzetten, en daarna de migratie vanuit Django.

## Werken aan de plugin

```bash
wordpress/tests/testsite.sh                     # lokale WordPress + MariaDB + plugin op http://localhost:8080
KHZS_URL=http://localhost:8080 python3 wordpress/tests/test_e2e.py
wordpress/bouw.sh                               # khzs.zip voor WordPress
```

- **Nieuwe module**: maak `modules/<id>/` met een `db.php` die `schema_<id>()` bevat en een `module.php`. Haak in via de filters `khzs_routes`, `khzs_menu`, `khzs_tabellen`, `khzs_voorkeuren` en `khzs_taken`, en voeg een regel toe aan `modules()` in `includes/kern.php`.
- **Sjablonen**: `toon('module/naam', …)` laadt `modules/<module>/views/naam.php`. Sjablonen die met `include` herbruikt worden in een lus, gebruiken variabelen met een `_`-prefix om botsingen te vermijden.
- **Datum in tests**: de header `X-Vandaag` simuleert een andere datum (enkel via de testhulp). In de code loopt alles via `vandaag()`.
- **Bewaren**: tijden staan als gehele honderdsten en bedragen in cent.
