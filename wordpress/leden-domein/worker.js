// Cloudflare Worker voor de route leden.khzs.be/* : de app KHZS Leden op een eigen domein.
//
// Stuurt elk verzoek door naar dezelfde WordPress van de club (https://khzs.be/leden/…). Er is dus geen tweede
// installatie: dezelfde accounts, dezelfde gegevens. De plugin herkent de verzoeken aan de header X-KHZS-Domein en maakt
// dan alle links op https://leden.khzs.be/… (optie "Eigen domein" in Instellingen → KHZS Leden).
//
//   https://leden.khzs.be/wedstrijden/?x=1                    → https://khzs.be/leden/wedstrijden/?x=1
//   https://leden.khzs.be/wp-content/plugins/khzs-leden/assets/app.css → https://khzs.be/wp-content/plugins/khzs-leden/assets/app.css
//
// Variabelen (Settings → Variables and Secrets):
//   BRON    verplicht: adres van de app op de site van de club, bv. https://khzs.be/leden (zonder / op het einde)
//   ASSETS  optioneel: pad naar de assets van de plugin, standaard /wp-content/plugins/khzs-leden/assets/
//
// Cookies gaan ongewijzigd heen en terug. Zonder COOKIE_DOMAIN in wp-config.php zijn de aanmeldcookies van de app enkel
// geldig op leden.khzs.be (los van khzs.be); met define('COOKIE_DOMAIN', '.khzs.be') delen ze één aanmelding.

const ASSETS = "/wp-content/plugins/khzs-leden/assets/";

export default {
  async fetch(request, env) {
    const url = new URL(request.url);
    const bron = new URL(env.BRON.replace(/\/+$/, ""));
    const assets = env.ASSETS || ASSETS;

    // Assets rechtstreeks van de site (zelfde pad); al de rest onder het adres van de app.
    const doel = url.pathname.startsWith(assets)
      ? new URL(url.pathname + url.search, bron.origin)
      : new URL(bron.pathname + url.pathname + url.search, bron.origin);

    const koppen = new Headers(request.headers);
    koppen.set("X-KHZS-Domein", url.host);
    koppen.set("X-Forwarded-Host", url.host);
    koppen.set("X-Forwarded-Proto", url.protocol.replace(":", ""));

    const antwoord = await fetch(doel, {
      method: request.method,
      headers: koppen,
      body: ["GET", "HEAD"].includes(request.method) ? undefined : await request.arrayBuffer(),
      redirect: "manual",
    });

    const waar = antwoord.headers.get("Location");
    if (!waar) return antwoord;

    // Doorverwijzingen naar de app op de site van de club (bv. van WordPress zelf) terug naar het eigen domein.
    let nieuw = new URL(waar, doel);
    if (nieuw.origin === bron.origin && (nieuw.pathname === bron.pathname || nieuw.pathname.startsWith(bron.pathname + "/"))) {
      nieuw = new URL(nieuw.pathname.slice(bron.pathname.length) + nieuw.search, url.origin);
    }
    if (nieuw.href === url.href) {
      // Verwijst de site naar exact hetzelfde adres terug, dan kwam de header X-KHZS-Domein niet aan (bv. weggefilterd
      // door een proxy van de hosting). Stop de lus en zeg wat er mis is.
      return new Response(
        "KHZS Leden: de site stuurt dit domein naar zichzelf terug. Komt de header X-KHZS-Domein aan bij WordPress?",
        { status: 508, headers: { "Content-Type": "text/plain; charset=utf-8" } },
      );
    }
    const koppenTerug = new Headers(antwoord.headers);
    koppenTerug.set("Location", nieuw.href);
    return new Response(antwoord.body, { status: antwoord.status, statusText: antwoord.statusText, headers: koppenTerug });
  },
};
