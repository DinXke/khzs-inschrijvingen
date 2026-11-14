# Demo van KHZS Leden (aparte WordPress)

Een eigen WordPress-installatie met de plugin KHZS Leden in demo-modus, **volledig los van www.khzs.be**: eigen containers, eigen database, niets gedeeld.

- **Adres**: de demo staat op `<DEMO_URL>/leden-demo/`. De rest van de site en `/leden/` verwijzen ernaar door.
- **Aanmelden**: met `demo` / `demo` (alle rollen). Uitleg over de demo zelf: `../README.md`, kop *Demo*.
- **Installeerbaar** als app *KHZS Leden Demo*, met iconen waarop "DEMO" staat. Pushmeldingen en mails zijn uitgeschakeld.
- **Altijd de nieuwste versie**: `update.sh` volgt de tak `main`. Bij een nieuwe commit:
  - werkt het de plugin en de database bij;
  - zet het de demo terug naar de begintoestand, zodat de demodata bij de nieuwe code passen;
  - gaat het terug naar de vorige versie als dat mislukt.

## Installeren (bv. in een LXC met Docker)

Gebruik een **eigen clone** voor de demo: `update.sh` zet die clone telkens gelijk met `main`.

```bash
git clone https://github.com/DinXke/khzs-leden /opt/khzs-demo
cd /opt/khzs-demo/wordpress/demo
cp .env.voorbeeld .env          # DEMO_URL, DB_WACHTWOORD … aanvullen
./installeer.sh                 # toont eenmalig het wachtwoord van wp-admin (gebruiker beheer)
```

Houd de demo bij met cron, elke 10 minuten. Dezelfde taak draait ook de geplande taken, zoals het terugzetten om 03:30:

```bash
( crontab -l 2>/dev/null; echo '*/10 * * * * /opt/khzs-demo/wordpress/demo/update.sh >> /var/log/khzs-demo.log 2>&1' ) | crontab -
```

## Cloudflare-tunnel

De container luistert op `DEMO_BIND:DEMO_POORT` (standaard `127.0.0.1:8094`). Een ingress-regel in de configuratie van cloudflared:

```yaml
- hostname: demo.khzs.be
  service: http://localhost:8094      # of http://<ip-van-de-lxc>:8094 met DEMO_BIND=0.0.0.0
```

Zet `DEMO_URL=https://demo.khzs.be` in `.env`. HTTPS via de tunnel werkt vanzelf: de WordPress-image herkent `X-Forwarded-Proto`.

## Beheer

```bash
docker compose run --rm wpcli wp eval 'KHZS\demo_terugzetten();'   # demo nu terugzetten
docker compose logs -f wordpress                                    # logs
docker compose down                                                 # stoppen (gegevens blijven in de volumes)
```

In wp-admin (`<DEMO_URL>/wp-admin/`, gebruiker `beheer`) staat onder *Instellingen → KHZS Leden* ook een knop *Demo nu terugzetten*.
