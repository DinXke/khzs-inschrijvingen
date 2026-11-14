<?php namespace KHZS; defined('ABSPATH') || exit; ?>
<div class="rij tussen"><h1>Meldingen</h1><div class="rij"><a class="knop secundair" href="<?= esc_url(app_url('rollen/')) ?>">Mijn rollen</a><a class="knop secundair" href="<?= esc_url(app_url('privacy/mijn-gegevens/')) ?>">Mijn gegevens</a></div></div>
<?php if (is_demo()): ?>
<div class="kaart"><h2 style="margin-top:0">Op dit toestel</h2>
  <p class="klein">In de demo staan pushmeldingen uit. In de echte app zet je ze hier aan voor je gsm of computer.</p></div>
<?php else: ?>
<div class="kaart">
  <h2 style="margin-top:0">Op dit toestel</h2>
  <p class="klein" id="push-status">Even kijken…</p>
  <p class="rij">
    <button type="button" id="push-aan" hidden>🔔 Meldingen aanzetten</button>
    <button type="button" id="push-uit" class="secundair" hidden>Uitzetten op dit toestel</button>
    <button type="button" id="push-test" class="secundair mini" hidden>Stuur testmelding</button>
  </p>
  <p class="help" id="ios-tip" hidden>Op iPhone/iPad werken meldingen enkel als je de app eerst op je beginscherm zet: tik op Delen ⬆ → ‘Zet op beginscherm’, en open de app van daaruit.</p>
  <p class="help">Je ontvangt meldingen op <?= (int) $toestellen ?> toestel(len).</p>
</div>
<?php endif; ?>
<form method="post" class="kaart" action="<?= esc_url(app_url('meldingen/')) ?>"><?= nonce_veld() ?>
  <h2 style="margin-top:0">Waarvoor?</h2>
  <?php foreach ($velden as $k):
    $label = voorkeuren()[$k][0];
    if ($k === 'push_deadline' && $staf) { $label = 'Herinnering: 1 dag voor de deadline (zwemmers én trainers)'; } ?>
    <?= veld_vinkje($k, $label, voorkeur(get_current_user_id(), $k)) ?>
  <?php endforeach; ?>
  <p><button>Bewaren</button></p>
</form>
<?php if (!is_demo()): ?>
<script>
(function () {
  const VAPID = <?= wp_json_encode($vapid) ?>;
  const nonce = <?= wp_json_encode(wp_create_nonce('khzs')) ?>;
  const URLS = <?= wp_json_encode(['abonneer' => app_url('meldingen/abonneer/'), 'afmelden' => app_url('meldingen/afmelden/'), 'test' => app_url('meldingen/test/')], JSON_UNESCAPED_SLASHES) ?>;
  const $ = id => document.getElementById(id);
  const status = $("push-status");
  const post = (url, body) => fetch(url, {method: "POST", credentials: "same-origin", headers: {"Content-Type": "application/json", "X-KHZS-Nonce": nonce}, body: JSON.stringify(body || {})}).then(r => r.json());
  const sleutel = b64 => { const p = "=".repeat((4 - b64.length % 4) % 4); const r = atob((b64 + p).replace(/-/g, "+").replace(/_/g, "/")); return Uint8Array.from(r, c => c.charCodeAt(0)); };

  if (/iphone|ipad/i.test(navigator.userAgent) && !window.matchMedia("(display-mode: standalone)").matches) $("ios-tip").hidden = false;
  if (!("serviceWorker" in navigator) || !("PushManager" in window)) { status.textContent = "Deze browser ondersteunt geen pushmeldingen."; return; }

  async function toon() {
    const reg = await navigator.serviceWorker.ready;
    const ab = await reg.pushManager.getSubscription();
    if (Notification.permission === "denied") { status.textContent = "Meldingen zijn geblokkeerd in de instellingen van je browser."; return; }
    status.textContent = ab ? "✅ Meldingen staan aan op dit toestel." : "Meldingen staan uit op dit toestel.";
    $("push-aan").hidden = !!ab; $("push-uit").hidden = !ab; $("push-test").hidden = !ab;
  }
  $("push-aan").onclick = async () => {
    const reg = await navigator.serviceWorker.ready;
    if (await Notification.requestPermission() !== "granted") return toon();
    const ab = await reg.pushManager.subscribe({userVisibleOnly: true, applicationServerKey: sleutel(VAPID)});
    await post(URLS.abonneer, ab.toJSON());
    toon();
  };
  $("push-uit").onclick = async () => {
    const reg = await navigator.serviceWorker.ready;
    const ab = await reg.pushManager.getSubscription();
    if (ab) { await post(URLS.afmelden, {endpoint: ab.endpoint}); await ab.unsubscribe(); }
    toon();
  };
  $("push-test").onclick = async () => { const r = await post(URLS.test); status.textContent = r.ok ? "Testmelding verstuurd." : "Versturen lukte niet."; };
  toon();
})();
</script>
<?php endif; ?>
