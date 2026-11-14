<?php namespace KHZS; defined('ABSPATH') || exit;
$p = $pagina;
$actief = fn(array $namen) => in_array($p, $namen, true) ? 'actief' : '';
$items = is_user_logged_in() ? menu() : [];
$ingelogd = is_user_logged_in();
?><!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
  <meta name="theme-color" content="#111b1e" media="(prefers-color-scheme: dark)">
  <meta name="robots" content="noindex">
  <script>try{var t=localStorage.getItem("thema");if(t)document.documentElement.dataset.theme=t}catch(e){}</script>
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="<?= esc_attr(app_kort()) ?>">
  <title><?= e($titel) ?> · <?= e(app_kort()) ?></title>
  <link rel="manifest" href="<?= esc_url(app_url('manifest.webmanifest')) ?>">
  <link rel="icon" href="<?= esc_url(asset('img/favicon-32.png')) ?>">
  <link rel="apple-touch-icon" href="<?= esc_url(asset('img/apple-touch-icon.png')) ?>">
  <link rel="stylesheet" href="<?= esc_url(asset('app.css')) ?>">
</head>
<body>
<header class="kop">
  <a class="logo" href="<?= esc_url(app_url('')) ?>"><span class="logo-badge"><img src="<?= esc_url(asset('img/logo.png')) ?>" alt="<?= esc_attr(club()['naam']) ?>"></span><span class="logo-tekst"><?= e(app_titel()) ?></span></a>
  <button type="button" class="thema-knop" id="thema" title="Licht / donker / automatisch" aria-label="Thema wisselen"></button>
  <?php if ($ingelogd): ?>
  <nav>
    <?php foreach ($items as [$label, $pad, $namen, $icoon]): ?><a href="<?= esc_url(app_url($pad)) ?>" class="<?= $actief($namen) ?>"<?= $label === 'Meldingen' ? ' title="Meldingen"' : '' ?>><?= $label === 'Meldingen' ? '🔔' : e($label) ?></a>
    <?php endforeach; ?>
    <?php if (club()['webshop']): ?><a href="<?= esc_url(club()['webshop']) ?>" target="_blank" rel="noopener">Webshop ↗</a><?php endif; ?>
    <?php if ($is_beheerder): ?><a href="<?= esc_url(app_url('backup/')) ?>" class="<?= $actief(['backups']) ?>">Backup</a><?php if (current_user_can('manage_options')): ?><a href="<?= esc_url(admin_url('options-general.php?page=khzs')) ?>">Beheer</a><?php endif; endif; ?>
    <form method="post" action="<?= esc_url(app_url('afmelden/')) ?>" style="display:inline"><?= nonce_veld() ?><button class="mini secundair">Afmelden</button></form>
  </nav>
  <?php endif; ?>
</header>
<?php if (is_demo()) { include KHZS_PAD . 'views/demo-balk.php'; } ?>
<main class="<?= esc_attr($mainklasse) ?>">
  <?php foreach ($meldingen_flash as [$soort, $tekst]): ?><div class="melding <?= esc_attr($soort) ?>"><?= e($tekst) ?></div><?php endforeach; ?>
  <?php include $inhoud_sjabloon; ?>
</main>
<?php if ($ingelogd):
  // Onderbalk (gsm): hoogstens 5 knoppen. Zijn er meer (veel rollen), dan komen de rest en Afmelden onder 'Meer'.
  $gsm = $items;
  if ($is_beheerder) {
      $gsm[] = ['Backup', 'backup/', ['backups'], '<rect x="3" y="4" width="18" height="5" rx="1"/><path d="M5 9v10a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V9M10 13h4"/>'];
  }
  $afmelden_icoon = '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>';
  $meer = count($gsm) + 1 > 5 ? array_slice($gsm, 4) : [];
  $balk = $meer ? array_slice($gsm, 0, 4) : $gsm;
  $meer_actief = $actief(array_merge([], ...array_map(fn($i) => $i[2], $meer))); ?>
<nav class="tabbalk">
  <?php foreach ($balk as [$label, $pad, $namen, $icoon]): ?><a href="<?= esc_url(app_url($pad)) ?>" class="<?= $actief($namen) ?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><?= $icoon /* vaste svg uit de code */ ?></svg><?= e($label) ?></a>
  <?php endforeach; ?>
  <?php if ($meer): ?>
  <button type="button" class="<?= $meer_actief ?>" id="meer-knop" aria-expanded="false" aria-controls="meer-blad">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="5" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/></svg>Meer</button>
  <?php else: ?>
  <a href="#" onclick="document.getElementById('afmelden').submit();return false">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><?= $afmelden_icoon ?></svg>Afmelden</a>
  <?php endif; ?>
</nav>
<?php if ($meer): ?>
<div class="meer-achtergrond" id="meer-achtergrond" hidden></div>
<div class="meer-blad" id="meer-blad" role="dialog" aria-label="Meer" hidden>
  <?php foreach ($meer as [$label, $pad, $namen, $icoon]): ?><a href="<?= esc_url(app_url($pad)) ?>" class="<?= $actief($namen) ?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><?= $icoon /* vaste svg uit de code */ ?></svg><?= e($label) ?></a>
  <?php endforeach; ?>
  <a href="#" onclick="document.getElementById('afmelden').submit();return false">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><?= $afmelden_icoon ?></svg>Afmelden</a>
</div>
<script>
(function () {
  const knop = document.getElementById("meer-knop"), blad = document.getElementById("meer-blad"), achter = document.getElementById("meer-achtergrond");
  const zet = open => { blad.hidden = achter.hidden = !open; knop.setAttribute("aria-expanded", open); if (open) blad.querySelector("a").focus(); };
  knop.addEventListener("click", () => zet(blad.hidden));
  achter.addEventListener("click", () => zet(false));
  document.addEventListener("keydown", e => { if (e.key === "Escape" && !blad.hidden) { zet(false); knop.focus(); } });
})();
</script>
<?php endif; ?>
<form id="afmelden" method="post" action="<?= esc_url(app_url('afmelden/')) ?>" hidden><?= nonce_veld() ?></form>
<?php endif; ?>
<script>
(function () {
  const iconen = {
    auto: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 3a9 9 0 0 1 0 18z" fill="currentColor"/></svg>',
    light: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>',
    dark: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>'
  };
  const knop = document.getElementById("thema");
  const volgende = {auto: "light", light: "dark", dark: "auto"};
  const huidig = () => document.documentElement.dataset.theme || "auto";
  const toon = () => { knop.innerHTML = iconen[huidig()]; knop.title = {auto: "Thema: automatisch", light: "Thema: licht", dark: "Thema: donker"}[huidig()]; };
  knop.addEventListener("click", () => {
    const t = volgende[huidig()];
    if (t === "auto") delete document.documentElement.dataset.theme; else document.documentElement.dataset.theme = t;
    try { t === "auto" ? localStorage.removeItem("thema") : localStorage.setItem("thema", t); } catch (e) {}
    toon();
  });
  toon();
})();
if ("serviceWorker" in navigator) navigator.serviceWorker.register(<?= wp_json_encode(app_url('sw.js')) ?>, {scope: <?= wp_json_encode(wp_parse_url(app_url(''), PHP_URL_PATH)) ?>});
</script>
<?php if (pwa_melding_aan() && $pagina !== 'installeer') { echo pwa_melding_html(true); } ?>
</body>
</html>
