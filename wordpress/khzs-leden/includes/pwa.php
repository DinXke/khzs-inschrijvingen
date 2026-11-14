<?php
/**
 * Installatiemelding voor de app (PWA) op de gewone pagina's van de website en in de app zelf.
 *
 * Browsers tonen hun eigen installatievenster enkel op pagina's binnen de app (/inschrijvingen/). Op de rest van de
 * website toont de melding daarom een knop naar /inschrijvingen/installeer/, waar het installeren met één tik gebeurt
 * (Android/Chrome/Edge) of stap voor stap uitgelegd wordt (iPhone/iPad, Firefox).
 */

namespace KHZS;

defined('ABSPATH') || exit;

function pwa_melding_aan(): bool
{
    return (bool) get_option('khzs_pwa_melding', 1);
}

/** Melding op de website (thema-pagina's), niet in wp-admin. */
add_action('wp_footer', function () {
    if (!pwa_melding_aan() || is_admin() || wp_doing_ajax()) {
        return;
    }
    if (get_option('khzs_pwa_enkel_home', 0) && !(is_front_page() || is_home())) {
        return;
    }
    echo pwa_melding_html(false); // phpcs:ignore WordPress.Security.EscapeOutput -- volledig opgebouwd met esc_*.
}, 50);

/** Icoon van de app ook op de website (voor 'Zet op beginscherm' op iPhone). */
add_action('wp_head', function () {
    if (!pwa_melding_aan()) {
        return;
    }
    echo '<link rel="apple-touch-icon" href="' . esc_url(asset('img/apple-touch-icon.png')) . '">' . "\n";
}, 5);

/**
 * HTML + CSS + JS van de melding, los van het thema (eigen klassen khzs-pwa-*).
 * $in_app: true binnen de app; dan wordt het installatievenster van de browser rechtstreeks geopend.
 */
function pwa_melding_html(bool $in_app): string
{
    $tekst = get_option('khzs_pwa_tekst', '') ?: 'Schrijf je kinderen in voor wedstrijden en krijg meldingen, rechtstreeks vanaf je beginscherm.';
    $titel = 'Installeer de app ' . app_naam();
    $cfg = [
        'installeer' => app_url('installeer/'),
        'inApp' => $in_app,
        'enkelGsm' => (bool) get_option('khzs_pwa_enkel_gsm', 1),
        'dagen' => 30,
    ];
    ob_start(); ?>
<div id="khzs-pwa" class="khzs-pwa" role="dialog" aria-label="<?= esc_attr($titel) ?>" hidden>
  <img class="khzs-pwa-icoon" src="<?= esc_url(asset('img/icon-192.png')) ?>" alt="" width="48" height="48">
  <div class="khzs-pwa-tekst"><strong><?= esc_html($titel) ?></strong><span><?= esc_html($tekst) ?></span></div>
  <div class="khzs-pwa-knoppen">
    <button type="button" class="khzs-pwa-ja" data-khzs-pwa="ja">Installeren</button>
    <button type="button" class="khzs-pwa-nee" data-khzs-pwa="nee" aria-label="Niet nu">Niet nu</button>
  </div>
</div>
<style>
.khzs-pwa{position:fixed;left:12px;right:12px;bottom:calc(12px + env(safe-area-inset-bottom));z-index:99999;display:flex;gap:12px;align-items:center;
  max-width:560px;margin:0 auto;padding:12px 14px;border-radius:16px;background:#fff;color:#14232c;border:1px solid #e0e7ea;
  box-shadow:0 10px 30px rgba(10,40,50,.22);font:15px/1.35 -apple-system,"Segoe UI",Roboto,Arial,sans-serif;animation:khzs-pwa-in .35s ease-out}
.khzs-pwa[hidden]{display:none}
.khzs-pwa-icoon{width:48px;height:48px;border-radius:12px;flex:none}
.khzs-pwa-tekst{flex:1;min-width:0;display:flex;flex-direction:column;gap:2px}
.khzs-pwa-tekst strong{font-size:15px;color:#0e6b73}
.khzs-pwa-tekst span{font-size:13px;color:#5d6d77}
.khzs-pwa-knoppen{display:flex;flex-direction:column;gap:6px;flex:none}
.khzs-pwa button{font:inherit;font-weight:700;font-size:14px;border-radius:999px;padding:8px 14px;cursor:pointer;border:1px solid transparent;line-height:1.1;margin:0}
.khzs-pwa-ja{background:#0e6b73;color:#fff}
.khzs-pwa-nee{background:transparent;color:#5d6d77;border-color:#e0e7ea!important;font-weight:600!important;font-size:13px!important}
.khzs-pwa.in-app{bottom:calc(76px + env(safe-area-inset-bottom))}
@media (min-width:720px){.khzs-pwa.in-app{bottom:20px}}
@media (prefers-color-scheme:dark){.khzs-pwa{background:#17262b;color:#e6eef0;border-color:#2a3b41}.khzs-pwa-tekst strong{color:#5cc4cc}.khzs-pwa-tekst span{color:#9fb2b9}.khzs-pwa-nee{border-color:#2a3b41!important;color:#9fb2b9}}
@keyframes khzs-pwa-in{from{transform:translateY(20px);opacity:0}to{transform:none;opacity:1}}
</style>
<script>
(function () {
  var cfg = <?= wp_json_encode($cfg, JSON_UNESCAPED_SLASHES) ?>;
  var el = document.getElementById("khzs-pwa");
  if (!el) return;
  var standalone = window.matchMedia("(display-mode: standalone)").matches || window.navigator.standalone === true;
  var gsm = /android|iphone|ipad|ipod|mobile/i.test(navigator.userAgent) || (navigator.maxTouchPoints > 1 && /macintosh/i.test(navigator.userAgent));
  var sleutel = "khzs_pwa_weg";
  function weggeklikt() { try { var t = +localStorage.getItem(sleutel); return t && Date.now() - t < cfg.dagen * 864e5; } catch (e) { return false; } }
  if (standalone || weggeklikt() || (cfg.enkelGsm && !gsm)) return;
  if (cfg.inApp) el.classList.add("in-app");
  var uitgesteld = null;
  window.addEventListener("beforeinstallprompt", function (e) { e.preventDefault(); uitgesteld = e; toon(); });
  window.addEventListener("appinstalled", function () { el.hidden = true; try { localStorage.setItem(sleutel, Date.now()); } catch (e) {} });
  function toon() { el.hidden = false; }
  // Op de website (buiten de app) komt beforeinstallprompt niet: toon na een korte pauze.
  setTimeout(toon, cfg.inApp ? 2500 : 1500);
  el.addEventListener("click", function (e) {
    var k = e.target.closest("[data-khzs-pwa]");
    if (!k) return;
    if (k.dataset.khzsPwa === "nee") {
      el.hidden = true;
      try { localStorage.setItem(sleutel, Date.now()); } catch (x) {}
      return;
    }
    if (uitgesteld) {
      uitgesteld.prompt();
      uitgesteld.userChoice.then(function () { el.hidden = true; uitgesteld = null; });
    } else {
      location.href = cfg.installeer;
    }
  });
})();
</script>
<?php
    return ob_get_clean();
}
