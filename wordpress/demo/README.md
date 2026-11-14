# Demo van KHZS Leden op khzs.be/leden-demo

De demo is een eigen WordPress-installatie met de plugin KHZS Leden in demo-modus. Ze is **volledig los van de WordPress van www.khzs.be**: eigen containers, eigen database, niets gedeeld.

Bezoekers zien ze toch op **https://khzs.be/leden-demo/**. Dat werkt zo:

```
bezoeker → https://khzs.be/leden-demo/…
             │  Cloudflare Worker op de route khzs.be/leden-demo*  (worker.js)
             ▼
           https://leden-demo.khzs.be/leden-demo/…   (Cloudflare-tunnel naar de LXC leden-demo)
             │  cloudflared
             ▼
           demo-WordPress in het homelab (docker compose, WordPress in de submap /leden-demo/)
```

- **De rest van khzs.be** gaat gewoon naar de hosting van de club. De productie-WordPress merkt niets van de demo.
- **Cookies**: de Worker laat enkel de cookies van de demo door. Aanmeldcookies van de echte site komen dus nooit bij de demoserver.
- **Aanmelden**: met `demo` / `demo` (alle rollen). wp-admin van de demo: `https://khzs.be/leden-demo/wp-admin/` (gebruiker `beheer`).
- **Installeerbaar** als app *KHZS Leden Demo*, met iconen waarop "DEMO" staat. Pushmeldingen en mails zijn uitgeschakeld.
- **Altijd de nieuwste versie**: `update.sh` volgt de tak `main`. Bij een nieuwe commit:
  - werkt het de plugin en de database bij;
  - zet het de demo terug naar de begintoestand, zodat de demodata bij de nieuwe code passen;
  - gaat het terug naar de vorige versie als dat mislukt.

## 1. Demo-WordPress installeren

**Nieuwe LXC op Proxmox** (aanbevolen): maak eerst de tunnel aan (stap 2) en kopieer het token. Dan, op de Proxmox-host:

```bash
git clone https://github.com/DinXke/khzs-inschrijvingen /root/khzs-leden && cd /root/khzs-leden/wordpress/demo/lxc
CF_TUNNEL_TOKEN=<token> ./maak-lxc.sh <vmid> [opslag] [bridge] [vlan]
```

`maak-lxc.sh` doet het volgende:
- een Debian 12-LXC `leden-demo` aanmaken (unprivileged, met nesting voor Docker);
- daarin `firstboot.sh` uitvoeren: Docker en cloudflared installeren, de repo clonen in `/opt/khzs-demo`, `.env` met een willekeurig wachtwoord, `installeer.sh`, en een cron-regel voor `update.sh`.

**Of met de hand** op een bestaande host met Docker:

Gebruik een **eigen clone** voor de demo: `update.sh` zet die clone telkens gelijk met `main`.

```bash
git clone https://github.com/DinXke/khzs-inschrijvingen /opt/khzs-demo
cd /opt/khzs-demo/wordpress/demo
cp .env.voorbeeld .env          # DB_WACHTWOORD invullen; DEMO_URL=https://khzs.be/leden-demo
./installeer.sh                 # toont eenmalig het wachtwoord van wp-admin (gebruiker beheer)
```

Houd de demo bij met cron, elke 10 minuten. Dezelfde taak draait ook de geplande taken, zoals het terugzetten om 03:30:

```bash
( crontab -l 2>/dev/null; echo '*/10 * * * * /opt/khzs-demo/wordpress/demo/update.sh >> /var/log/khzs-demo.log 2>&1' ) | crontab -
```

## 2. Cloudflare-tunnel

Maak in Cloudflare Zero Trust (Networks → Tunnels) een tunnel `leden-demo` aan, met de public hostname **`leden-demo.khzs.be`** → `http://localhost:8094`. cloudflared draait in de LXC zelf, met het token van die tunnel.

Wie `leden-demo.khzs.be` rechtstreeks opent, wordt doorgestuurd naar `khzs.be/leden-demo/`.

## 3. Cloudflare Worker

In het Cloudflare-dashboard van khzs.be:

1. **Workers & Pages → Create → Worker**, bv. met de naam `khzs-leden-demo`. Plak de inhoud van `worker.js` en klik op **Deploy**.
2. **Settings → Variables**: voeg de variabele `BRON` toe, met als waarde `https://leden-demo.khzs.be`.
3. **Settings → Domains & Routes → Add route**:
   - `khzs.be/leden-demo*`
   - `www.khzs.be/leden-demo*`, als de site ook op www draait.

De demo uitzetten kan door de route te verwijderen. De rest van de site verandert daar niet door.

## Beheer

```bash
docker compose run --rm wpcli wp eval 'KHZS\demo_terugzetten();'   # demo nu terugzetten
docker compose logs -f wordpress                                    # logs
docker compose down                                                 # stoppen (gegevens blijven in de volumes)
```

In wp-admin van de demo staat onder *Instellingen → KHZS Leden* ook een knop *Demo nu terugzetten*.

**Lokaal testen**: zet `DEMO_URL=http://localhost:8094/leden-demo` in `.env` en open http://localhost:8094/leden-demo/.
