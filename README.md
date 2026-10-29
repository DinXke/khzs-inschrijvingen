# HZS inschrijvingen

Inschrijfsysteem (PWA) voor zwemwedstrijden van HZS Spartacus.

- **Sportsecretaris**: Lenex-uitnodiging (.lxf/.lef) uploaden → programma, deadline en limieten worden ingelezen; deadline secretariaat instellen; inschrijvingen exporteren als Lenex + Excel.
- **Trainers**: extra dagen instellen, inschrijvingen nakijken, goed-/afkeuren en aanpassen.
- **Zwemmers**: profiel + beste tijden, interesse/ik doe mee/ik doe niet mee, inschrijven op nummers waarvoor ze in aanmerking komen.
- Pushmeldingen (instelbaar): nieuwe wedstrijd, inschrijvingen open, 1 dag voor de deadline, wijziging door de club.

Deadline zwemmers = deadline organisator − dagen secretariaat − dagen trainer.

## Lokaal draaien

```bash
uv venv --python 3.12 .venv && uv pip install --python .venv -r requirements.txt
.venv/bin/python manage.py migrate
.venv/bin/python manage.py demodata      # demo-accounts, zie inschrijvingen/management/commands/demodata.py
.venv/bin/python manage.py runserver
.venv/bin/python manage.py test inschrijvingen
```

## Productie

Debian 12 LXC met Postgres, gunicorn, nginx en cloudflared (`deploy/`).
De container haalt elke 10 minuten nieuwe commits op `main` op en installeert ze (`deploy/auto_update.sh`),
met terugrol bij een mislukte update. Het eerste account dat zich registreert wordt sportsecretaris.
