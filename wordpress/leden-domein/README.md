# KHZS Leden op leden.khzs.be

De app draait standaard op **https://khzs.be/leden/**. Met een Cloudflare Worker kan ze ook op een eigen domein draaien, bijvoorbeeld **https://leden.khzs.be/**:

- Dezelfde WordPress, dus dezelfde accounts en gegevens. Er komt geen tweede installatie bij.
- `khzs.be/leden/…` verwijst daarna automatisch door naar `leden.khzs.be/…`.

```
bezoeker → https://leden.khzs.be/wedstrijden/
             │  Cloudflare Worker op leden.khzs.be  (worker.js)
             │  + header X-KHZS-Domein: leden.khzs.be
             ▼
           https://khzs.be/leden/wedstrijden/   (WordPress van de club, bij de hosting)
```

## Wat de plugin doet als het eigen domein ingesteld is

- **Links en doorverwijzingen**: alle links van de app wijzen naar `https://leden.khzs.be/…`. Dat geldt ook voor de app op het beginscherm, de pushmeldingen, de installatiemelding op de rest van de site en `/club/` en `/inschrijvingen/`.
- **Oud adres**: wie `khzs.be/leden/…` opent, wordt doorgestuurd naar het domein.
  - Het is een tijdelijke doorverwijzing (302, of 307 bij een formulier). Browsers onthouden die dus niet als je het domein later weer uitzet.
  - Herkenning: verzoeken via de Worker dragen de header `X-KHZS-Domein`. Verzoeken zonder die header worden doorgestuurd.
- **Assets** (`app.css`, iconen) lopen ook via het domein. De service worker kan enkel bestanden van zijn eigen domein in de cache zetten.
- **App op de gsm**: het manifest en de service worker gelden voor `https://leden.khzs.be/`.
  - Een app die nog op `khzs.be/leden/` geïnstalleerd was, krijgt een service worker die zichzelf opruimt.
  - Leden installeren de app dan opnieuw en zetten de pushmeldingen opnieuw aan.
- **Aanmelden**: zonder `COOKIE_DOMAIN` gelden de aanmeldcookies enkel op `leden.khzs.be`.
  - Wie in de app aangemeld is, is dat dus niet automatisch op `khzs.be/wp-admin`, en omgekeerd. De accounts en wachtwoorden zijn wel dezelfde.
  - Zie stap 5 als je één aanmelding wilt voor beide.
- **Wachtwoord vergeten** loopt via `khzs.be/wp-login.php`. Daarna kom je terug op `leden.khzs.be/aanmelden/`, want de plugin voegt het domein toe aan de toegelaten adressen voor doorverwijzingen.

## Stappen in Cloudflare (voor de eigenaar)

Voer deze stappen uit in deze volgorde. Zolang stap 4 niet gedaan is, verandert er niets voor de leden.

**1. Worker aanmaken**: *Workers & Pages → Create → Worker*, bijvoorbeeld met de naam `khzs-leden`.
- Plak de inhoud van `worker.js` en klik op **Deploy**.

**2. Variabele instellen**: in de Worker, *Settings → Variables and Secrets*:
- `BRON` = het adres van de app op de site, **exact** zoals in WordPress.
  - Kijk in *Instellingen → Algemeen → Site-adres*. Staat daar `https://www.khzs.be`, gebruik dan `https://www.khzs.be/leden`. Anders `https://khzs.be/leden`.
  - Een verschil tussen www en zonder www geeft een extra doorverwijzing die de Worker niet omzet.
- `ASSETS` is enkel nodig als de plugin niet in `/wp-content/plugins/khzs-leden/` staat.

**3. Domein koppelen**: in de Worker, *Settings → Domains & Routes → Add → Custom domain* `leden.khzs.be`.
- Cloudflare maakt dan zelf het DNS-record en het certificaat aan.
- Er mag nog geen ander DNS-record `leden` bestaan.

Alternatief, met een route:
- Maak een DNS-record `AAAA leden 100::` aan, **Proxied** (oranje wolk).
- Voeg in de Worker de route `leden.khzs.be/*` toe.

**Controle**: open https://leden.khzs.be/domein-test/. Je moet dit zien:

```json
{"app":"khzs-leden","domein":"leden.khzs.be"}
```

- Staat `"domein":""`, dan haalt iets tussen Cloudflare en WordPress de header `X-KHZS-Domein` weg (bv. een proxy van de hosting).
- De app werkt nu al op https://leden.khzs.be/, maar de links wijzen nog naar `khzs.be/leden/`.

**4. Domein instellen in WordPress**: *Instellingen → KHZS Leden → Eigen domein* = `https://leden.khzs.be`. Klik op **Wijzigingen opslaan**.
- De plugin controleert eerst of de Worker werkt, met dezelfde test als hierboven. Lukt dat niet, dan wordt er niets bewaard en zie je waarom.
- Vanaf nu verwijst `khzs.be/leden/` door naar het domein.
- Kan de server van de hosting zichzelf niet bereiken via Cloudflare? Dan mislukt de controle. Stel het domein in dat geval in met wp-cli, nadat je stap 3 gecontroleerd hebt: `wp option update khzs_app_domein https://leden.khzs.be`.

**5. (Optioneel) Eén aanmelding voor de site en de app**: zet in `wp-config.php`, boven de regel `/* That's all, stop editing! */`:

```php
define('COOKIE_DOMAIN', '.khzs.be');
```

- Iedereen wordt daardoor één keer afgemeld, ook op de site.
- Doe dit enkel als je de aanmelding echt wilt delen. Zonder deze regel werkt alles ook.

## Terugdraaien

1. Maak in WordPress *Eigen domein* leeg en bewaar. De app staat dan meteen weer op `khzs.be/leden/`.
2. Verwijder daarna eventueel de Worker of het domein in Cloudflare.

Doe het in deze volgorde. Verwijder je eerst de Worker, dan verwijst `khzs.be/leden/` naar een domein dat niet meer werkt.

## Aandachtspunten

- **Cache**: de app stuurt `no-cache` mee. Staat er in Cloudflare een regel *Cache Everything* voor khzs.be, sluit dan `/leden/*` uit.
- **Aantal verzoeken**: het gratis plan van Workers laat 100.000 verzoeken per dag toe. De app op de gsm haalt de assets uit haar cache, dus voor een club is dat ruim voldoende.
- **De Django-versie** (`inschrijvingen.khzs.be`) staat hier los van.

## Lokaal testen

`wordpress/tests/worker_sim.mjs` draait de Worker met Node. De end-to-end-tests in `EigenDomeinTest` (`wordpress/tests/test_e2e.py`) starten hem zelf, op poort 8098 (`KHZS_WORKER_POORT`):

```bash
KHZS_URL=http://localhost:8080 python3 wordpress/tests/test_e2e.py EigenDomeinTest
```

Draait de testsite in Docker? Geef dan ook het adres mee waarop de site de nagebootste Worker bereikt: `KHZS_DOMEIN_VANUIT_SITE=http://host.docker.internal:8098`.
