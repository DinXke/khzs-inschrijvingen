# KHZS Leden: instructies voor Claude

Deze repo (`DinXke/khzs-leden`, vroeger `khzs-inschrijvingen`) bevat twee versies van de ledenapp van de Hasseltse Zwemvereniging Spartacus:

1. **Django-app** (`inschrijvingen/`, `config/`, `templates/`, `static/`, `deploy/`): de huidige productieversie. Ze draait in een Debian-LXC met Postgres, gunicorn, nginx en cloudflared. Inschrijvingen, rollen, ploegen, stappenplan na registratie, AVG.
   - ⚠️ De container installeert **elke commit op `main` automatisch** (`deploy/auto_update.sh`, elke 10 minuten). Werk altijd op een branch; merge naar `main` alleen na akkoord.
   - Tests: `.venv/bin/python manage.py test inschrijvingen` (125, alle geslaagd). Op Windows: `.venv\Scripts\python` met `PYTHONUTF8=1`.
2. **WordPress-plugin KHZS Leden** (`wordpress/khzs-leden/`): een port van de app naar WordPress, met de modules *Inschrijvingen*, *Trainers* (planning, aanwezigheid, uren, verloning, aanwezigheid van zwemmers) en *Communicatie* (kanalen, threads, aankondigingen), plus de websitekalender (The Events Calendar).
   - **Lees eerst `wordpress/OVERDRACHT.md`**: daar staan de beslissingen, de stand van zaken en de volgende stappen. Lees daarna `wordpress/README.md`.
   - Testsite: `wordpress/tests/testsite.sh`. Tests: `KHZS_URL=http://localhost:8080 python3 wordpress/tests/test_e2e.py` (152). Zip: `wordpress/bouw.sh`.
   - Release: versie verhogen in `khzs-leden.php` (`Version:` én `VERSIE`), mergen, tag `plugin-vX.Y.Z` pushen; `.github/workflows/plugin-release.yml` maakt de release en de sites updaten zichzelf.
   - Demo: https://khzs.be/leden-demo/ (`wordpress/demo/`), volgt `main` automatisch. Accounts `demo`, `ouder`, `zwemmer`, `trainer`, `bestuur` (wachtwoord = gebruikersnaam).
   - Gegevens van Django naar WordPress overzetten: `python manage.py export_wordpress` (`inschrijvingen/management/commands/export_wordpress.py`).
3. **Documentatie** (`docs/`): bronteksten in `docs/bronnen/*.md`, PDF's via `docs/build.py`, schermen van de plugin via `docs/demo_screenshots.py`. Werk ze bij als een functie verandert.

Conventies:
- Alles in het Nederlands (Vlaams): code, namen, commentaar en teksten in de app.
- Houd beide versies functioneel gelijk voor de inschrijvingen, rollen en ploegen zolang Django in productie is. Een wijziging aan de inschrijvingsregels hoort in `inschrijvingen/regels.py` én in `wordpress/khzs-leden/modules/inschrijvingen/regels.php`. Trainers, communicatie en de websitekalender bestaan enkel in de plugin.
- De plugin gebruikt geen externe PHP-bibliotheken. Houd dat zo: veel WordPress-hosting heeft geen Composer.
