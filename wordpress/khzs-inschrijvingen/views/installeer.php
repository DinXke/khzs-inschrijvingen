<?php namespace KHZS; defined('ABSPATH') || exit;
$naam = get_option('khzs_app_naam', 'HZS inschrijvingen');
$deel = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-4px"><path d="M12 3v12M7 8l5-5 5 5M5 12v7a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-7"/></svg>';
$plus = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-4px"><rect x="3" y="3" width="18" height="18" rx="4"/><path d="M12 8v8M8 12h8"/></svg>';
?>
<div style="text-align:center">
  <img src="<?= esc_url(asset('img/icon-192.png')) ?>" alt="" width="96" height="96" style="border-radius:22px;box-shadow:0 6px 20px rgba(14,107,115,.25)">
  <h1 style="margin-bottom:.2rem">Installeer de app</h1>
  <p class="klein"><?= e($naam) ?>: inschrijven voor wedstrijden en meldingen op je gsm, met één tik vanaf je beginscherm.</p>
</div>

<div class="kaart" id="stap-klaar" hidden>
  <p><strong>✅ De app is geïnstalleerd.</strong> Je vindt ze op je beginscherm.</p>
  <p><a class="knop vol" href="<?= esc_url(app_url('')) ?>">Open de app</a></p>
</div>

<div class="kaart" id="stap-knop" hidden>
  <p><button type="button" class="vol" id="installeer-knop">📲 Installeren</button></p>
  <p class="help">Je browser vraagt nog om te bevestigen. Daarna staat de app op je beginscherm.</p>
</div>

<div class="kaart" id="stap-ios" hidden>
  <h2 style="margin-top:0">Op iPhone of iPad</h2>
  <ol>
    <li>Tik onderaan (of bovenaan op iPad) op <b>Delen</b> <?= $deel ?></li>
    <li>Scrol naar beneden en tik op <b>Zet op beginscherm</b> <?= $plus ?></li>
    <li>Tik rechtsboven op <b>Voeg toe</b>.</li>
  </ol>
  <p class="help" id="ios-ander" hidden>Werkt het niet? Open deze pagina dan in <b>Safari</b> en probeer opnieuw.</p>
  <p class="help">Wil je pushmeldingen? Die werken op iPhone enkel als je de app opent vanaf je beginscherm.</p>
</div>

<div class="kaart" id="stap-android" hidden>
  <h2 style="margin-top:0">Op Android</h2>
  <ol>
    <li>Tik rechtsboven op het menu <b>⋮</b></li>
    <li>Kies <b>App installeren</b> of <b>Toevoegen aan startscherm</b>.</li>
    <li>Bevestig met <b>Installeren</b>.</li>
  </ol>
</div>

<div class="kaart" id="stap-computer" hidden>
  <h2 style="margin-top:0">Op de computer</h2>
  <p>In Chrome of Edge: klik in de adresbalk op het installatie-icoon <?= $plus ?> of kies in het menu <b>App installeren</b>.</p>
  <p class="help">De app is vooral handig op je gsm. Open deze pagina daar via <b><?= e(app_url('installeer/')) ?></b>.</p>
</div>

<p class="leeg"><a href="<?= esc_url(app_url('')) ?>">Verder in de browser →</a></p>

<script>
(function () {
  var $ = function (id) { return document.getElementById(id); };
  var ua = navigator.userAgent;
  var ios = /iphone|ipad|ipod/i.test(ua) || (navigator.maxTouchPoints > 1 && /macintosh/i.test(ua));
  var android = /android/i.test(ua);
  var standalone = window.matchMedia("(display-mode: standalone)").matches || navigator.standalone === true;
  var uitgesteld = null;
  function toon(id) { ["stap-klaar", "stap-knop", "stap-ios", "stap-android", "stap-computer"].forEach(function (s) { $(s).hidden = s !== id; }); }

  if (standalone) { toon("stap-klaar"); return; }
  if (ios) { toon("stap-ios"); $("ios-ander").hidden = !/crios|fxios|edgios/i.test(ua); }
  else { toon(android ? "stap-android" : "stap-computer"); }

  window.addEventListener("beforeinstallprompt", function (e) { e.preventDefault(); uitgesteld = e; toon("stap-knop"); });
  window.addEventListener("appinstalled", function () { toon("stap-klaar"); });
  $("installeer-knop").addEventListener("click", function () {
    if (!uitgesteld) return;
    uitgesteld.prompt();
    uitgesteld.userChoice.then(function (k) { if (k.outcome === "accepted") toon("stap-klaar"); uitgesteld = null; });
  });
})();
</script>
