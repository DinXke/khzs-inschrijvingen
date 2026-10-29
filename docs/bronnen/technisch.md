# Technische documentatie

## Architectuur

| Onderdeel | Keuze |
|---|---|
| App | Python 3.11+ / Django 5.1, server-side templates, PWA (manifest + service worker) |
| Database | PostgreSQL (productie), SQLite (lokaal) |
| Webserver | gunicorn achter nginx (poort 80 in de container) |
| Publiek | Cloudflare Tunnel (`cloudflared`) → `https://inschrijvingen.khzs.be` |
| Hosting | Proxmox LXC (Debian 12, VLAN 10.10.30.0/24) |
| Push | Web Push met VAPID (`pywebpush`), sleutel in `/opt/khzs/data/vapid.pem` |

## Lenex

- Import: `.lxf` (zip) en `.lef` (XML), Lenex 2.x en 3.0. Veilig ingelezen met `defusedxml`.
- Limietlijsten worden gelezen onder `MEET` en onder `LENEX` (SPLASH).
- Niet inschrijfbaar: finales, halve finales en swim-offs (`preveventid` of `round`), aflossingen en onbekende slagen.
- Export: Lenex 3.0 met `CLUB → ATHLETES → ENTRIES` (`eventid`, `entrytime`, `entrycourse`).

## Uitrol en updates

- De container installeert zichzelf bij de eerste start (`deploy/firstboot.sh`): code van GitHub, Postgres, nginx, cloudflared.
- `khzs-update.timer` haalt elke 10 minuten nieuwe commits op `main` op en installeert ze (`deploy/auto_update.sh`). Mislukt de update, dan gaat de vorige versie terug online.
- `khzs-herinneringen.timer`: elke dag om 18:00 de deadline-herinneringen.
- Backup: `khzs-backup.timer` maakt elke nacht om 02:30 een volledige app-backup (zip: `dumpdata` + media) in `/opt/khzs/backups/app` (30 dagen). Daarnaast om 03:15 een `pg_dump` in `/opt/khzs/backups/pg`. Beheer via `/backup/` (enkel superuser) of `manage.py backup`.
- Instellingen en geheimen: `/etc/khzs.env` (niet in git). SMTP invullen voor de mails bij een vergeten wachtwoord.

## Documentatie bijwerken

```
.venv/bin/python docs/build.py
```

Het script start de app met demodata, maakt alle screenshots opnieuw (gsm + pc, licht + donker) en bouwt de PDF's in `docs/pdf/`.

## Tests

```
.venv/bin/python manage.py test inschrijvingen
```

## Demo-accounts (testfase)

| Gebruiker | Rol | Wachtwoord |
|---|---|---|
| `beheerder` | Sportsecretaris + beheer (`/beheer/`) | `demo` |
| `sportsec1`, `sportsec2` | Sportsecretaris | `demo` |
| `trainer1`, `trainer2` | Trainer | `demo` |
| `zwemmer1` (Lien, 2013), `zwemmer2` (Bram, 2011) | Zwemmer | `demo` |

> **Verwijder of wijzig deze accounts voor er echte zwemmersgegevens in komen**:
> `manage.py demo_accounts --verwijder`. Het beheer-paneel staat op `/beheer/`.
