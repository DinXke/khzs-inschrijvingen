# KHZS inschrijvingen: WordPress-plugin

De inschrijvingsapp als WordPress-plugin. Ze werkt hetzelfde als de Django-versie: zelfde schermen, rollen, Lenex-import en -export, Excel, finaliseren, pushmeldingen, PWA en backup. Accounts zijn de **gewone WordPress-gebruikers**.

## Installeren

1. `./bouw.sh` maakt `khzs-inschrijvingen.zip`. Met `--met-uitleg` komen de filmpjes en PDF's uit `../docs` mee, maar dan is het bestand ± 40 MB.
2. Upload de zip in WordPress via **Plugins → Nieuwe plugin → Plugin uploaden** en activeer de plugin.
3. Zet de **permalinks** op iets anders dan "Eenvoudig". De app staat dan op `https://jouwsite.be/inschrijvingen/`.
4. Controleer de instellingen bij **Instellingen → KHZS inschrijvingen**: clubnaam, clubcode, adres van de app en of zelf registreren mag.

Vereisten: WordPress 6.2+, PHP 8.0+ met de extensies `zip`, `simplexml`, `dom` en `openssl`. Er zijn geen andere plugins of Composer-pakketten nodig.

## Rollen

| App-rol | Wie | Instellen |
|---|---|---|
| Zwemmer | iedereen met een account (ouder met kinderen) | standaard |
| Trainer | nakijken, goed-/afkeuren, finaliseren, tijden | in de app via *Gebruikers*, of op het WordPress-profiel |
| Sportsecretaris | Lenex uploaden, instellingen, export, rollen | idem |
| Beheerder | alles, plus backup | WordPress-beheerders zijn dit altijd |

De app-rol staat los van de WordPress-rol. Een redacteur van de website kan dus tegelijk ouder (zwemmer) in de app zijn.

## Overstappen vanuit de Django-versie

```bash
python manage.py export_wordpress --uit hzs-backup-export.zip
```

Daarna in de app (als WordPress-beheerder): **Backup → Backup terugzetten**, kies de zip en typ `HERSTEL`.

- Alle zwemmers, tijden, wedstrijden, inschrijvingen, meldingen, deelnames, bijlagen, Lenex-bestanden, rollen en voorkeuren komen mee.
- Ontbrekende accounts worden aangemaakt (gebruikersnaam = e-mail). Een account dat al bestaat met dezelfde gebruikersnaam of hetzelfde e-mailadres wordt gekoppeld.
- **Bestaande wachtwoorden blijven werken.** Bij de eerste keer aanmelden wordt het Django-wachtwoord omgezet naar een WordPress-wachtwoord.
- Pushabonnementen komen mee, maar toestellen moeten meldingen opnieuw aanzetten. De nieuwe site heeft een andere VAPID-sleutel en een andere service worker.

## Verschillen met de Django-versie

- **Accounts en wachtwoord vergeten** lopen via WordPress (`wp-login.php?action=lostpassword`).
- **Geplande taken** (herinneringen om 08:00, backup om 02:30) gebruiken WP-Cron. Op een rustige site stel je best een echte cron in die `wp-cron.php` oproept.
- **Backup** bevat de gegevens van de app, niet de WordPress-accounts zelf. Die zitten in de backup van je site.
- **"Beheer"** (het Django-admin) is vervangen door de WordPress-beheeromgeving.
- Automatische updates via git (`deploy/`) zijn niet van toepassing: je werkt de plugin bij door een nieuwe zip te uploaden.

## Opbouw

```
khzs-inschrijvingen/
  khzs-inschrijvingen.php   opstart, activatie, cron
  includes/db.php           eigen tabellen (wp_khzs_*), privémap voor bestanden
  includes/regels.php       leeftijd, limieten, deadlines, tijden (= regels.py)
  includes/data.php         opvragen van gegevens
  includes/lenex.php        Lenex lezen/importeren/exporteren (= lenex.py)
  includes/xlsx.php         Excel-export zonder bibliotheek
  includes/push.php         Web Push (VAPID + aes128gcm) met OpenSSL
  includes/backup.php       backup/terugzetten, import uit Django, oude wachtwoorden
  includes/app.php          routes, weergave, meldingen, toegang
  includes/acties.php       alle pagina's (= views.py)
  includes/admin.php        wp-admin: instellingen, rol op het profiel
  views/                    sjablonen (= templates/)
  assets/                   app.css en iconen (ongewijzigd uit static/)
```

## Testen

`tests/test_e2e.py` bevat dezelfde scenario's als `inschrijvingen/tests/test_alles.py` en doet echte HTTP-verzoeken naar een testsite. Op die testsite moet `tests/khzs-test-helper.php` als mu-plugin staan. **Zet die helper nooit op een echte site.**

```bash
KHZS_URL=http://localhost:8080 python3 tests/test_e2e.py
```
