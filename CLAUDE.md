# KHZS Leden: instructies voor Claude

Deze repo (`DinXke/khzs-leden`, vroeger `khzs-inschrijvingen`) bevat twee versies van de ledenapp van de Hasseltse Zwemvereniging Spartacus:

1. **Django-app** (`inschrijvingen/`, `config/`, `templates/`, `static/`, `deploy/`): de huidige productieversie. Ze draait in een Debian-LXC met Postgres, gunicorn, nginx en cloudflared.
   - ⚠️ De container installeert **elke commit op `main` automatisch** (`deploy/auto_update.sh`, elke 10 minuten). Werk altijd op een branch; merge naar `main` alleen na akkoord.
   - Tests: `.venv/bin/python manage.py test inschrijvingen` (84, alle geslaagd).
2. **WordPress-plugin KHZS Leden** (`wordpress/khzs-leden/`): een port van de app naar WordPress, met de modules *inschrijvingen* en *trainers* (planning, uren, verloning).
   - **Lees eerst `wordpress/OVERDRACHT.md`**: daar staan de beslissingen, de stand van zaken en de volgende stappen. Lees daarna `wordpress/README.md`.
   - Testsite: `wordpress/tests/testsite.sh`. Tests: `KHZS_URL=http://localhost:8080 python3 wordpress/tests/test_e2e.py`. Zip: `wordpress/bouw.sh`.
   - Gegevens van Django naar WordPress overzetten: `python manage.py export_wordpress` (`inschrijvingen/management/commands/export_wordpress.py`).

Conventies:
- Alles in het Nederlands (Vlaams): code, namen, commentaar en teksten in de app.
- Houd beide versies functioneel gelijk voor de inschrijvingen zolang Django in productie is. Een wijziging aan de inschrijvingsregels hoort in `inschrijvingen/regels.py` én in `wordpress/khzs-leden/modules/inschrijvingen/regels.php`.
- De plugin gebruikt geen externe PHP-bibliotheken. Houd dat zo: veel WordPress-hosting heeft geen Composer.
