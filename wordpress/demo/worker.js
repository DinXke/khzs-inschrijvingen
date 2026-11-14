// Cloudflare Worker voor de route khzs.be/leden-demo* (en www.khzs.be/leden-demo*).
// Stuurt de demo door naar de aparte demo-WordPress in het homelab (via een Cloudflare-tunnel). De site van de club zelf
// merkt er niets van. Variabele BRON (Settings → Variables): adres van de tunnel, https://leden-demo.khzs.be
//
// Enkel cookies van de demo gaan mee (khzs_…, en de WordPress-cookies van de demo met COOKIEHASH 'khzsdemo'), zodat
// aanmeldcookies van de echte site nooit bij de demoserver terechtkomen.

const PAD = "/leden-demo";

function demoCookies(kop) {
  if (!kop) return "";
  return kop
    .split(";")
    .map((c) => c.trim())
    .filter((c) => {
      const naam = c.split("=")[0];
      return naam.startsWith("khzs_") || naam.endsWith("_khzsdemo") || naam === "wordpress_test_cookie" || naam.startsWith("wp-settings-");
    })
    .join("; ");
}

export default {
  async fetch(request, env) {
    const url = new URL(request.url);
    if (url.pathname === PAD) {
      return Response.redirect(`${url.origin}${PAD}/${url.search}`, 301);
    }
    if (!url.pathname.startsWith(PAD + "/")) {
      return fetch(request); // valt buiten de route: gewoon naar de site van de club
    }
    const bron = new URL(env.BRON);
    const doel = new URL(url.pathname + url.search, bron);

    const koppen = new Headers(request.headers);
    const cookies = demoCookies(koppen.get("Cookie"));
    if (cookies) koppen.set("Cookie", cookies);
    else koppen.delete("Cookie");
    koppen.set("X-Forwarded-Host", url.host);
    koppen.set("X-Forwarded-Proto", "https");

    return fetch(doel, {
      method: request.method,
      headers: koppen,
      body: ["GET", "HEAD"].includes(request.method) ? undefined : await request.arrayBuffer(),
      redirect: "manual",
    });
  },
};
