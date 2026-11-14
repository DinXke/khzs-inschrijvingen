<?php namespace KHZS; defined('ABSPATH') || exit;
$p = $pagina;
$actief = fn(array $namen) => in_array($p, $namen, true) ? 'actief' : '';
$w_pag = ['wedstrijden', 'wedstrijd', 'nakijken', 'instellingen', 'upload'];
$z_pag = ['zwemmers', 'zwemmer', 'zwemmer_nieuw', 'uitslagen'];
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
  <meta name="apple-mobile-web-app-title" content="<?= esc_attr(get_option('khzs_app_kort', 'HZS')) ?>">
  <title><?= e($titel) ?> · <?= e(get_option('khzs_app_kort', 'HZS')) ?></title>
  <link rel="manifest" href="<?= esc_url(app_url('manifest.webmanifest')) ?>">
  <link rel="icon" href="<?= esc_url(asset('img/favicon-32.png')) ?>">
  <link rel="apple-touch-icon" href="<?= esc_url(asset('img/apple-touch-icon.png')) ?>">
  <link rel="stylesheet" href="<?= esc_url(asset('app.css')) ?>">
</head>
<body>
<header class="kop">
  <a class="logo" href="<?= esc_url(app_url('')) ?>"><span class="logo-badge"><img src="<?= esc_url(asset('img/logo.png')) ?>" alt="<?= esc_attr(club()['naam']) ?>"></span><span class="logo-tekst">Inschrijvingen</span></a>
  <button type="button" class="thema-knop" id="thema" title="Licht / donker / automatisch" aria-label="Thema wisselen"></button>
  <?php if ($ingelogd): ?>
  <nav>
    <a href="<?= esc_url(app_url('wedstrijden/')) ?>" class="<?= $actief($w_pag) ?>">Wedstrijden</a>
    <a href="<?= esc_url(app_url('zwemmers/')) ?>" class="<?= $actief($z_pag) ?>"><?= $is_staf ? 'Zwemmers' : 'Mijn zwemmers' ?></a>
    <?php if ($is_secretaris): ?><a href="<?= esc_url(app_url('gebruikers/')) ?>" class="<?= $actief(['gebruikers']) ?>">Gebruikers</a><?php endif; ?>
    <?php if ($is_beheerder): ?><a href="<?= esc_url(app_url('backup/')) ?>" class="<?= $actief(['backups']) ?>">Backup</a><?php if (current_user_can('manage_options')): ?><a href="<?= esc_url(admin_url('options-general.php?page=khzs-inschrijvingen')) ?>">Beheer</a><?php endif; endif; ?>
    <a href="<?= esc_url(app_url('uitleg/')) ?>" class="<?= $actief(['uitleg']) ?>">Uitleg</a>
    <a href="<?= esc_url(app_url('meldingen/')) ?>" class="<?= $actief(['meldingen']) ?>" title="Meldingen">🔔</a>
    <form method="post" action="<?= esc_url(app_url('afmelden/')) ?>" style="display:inline"><?= nonce_veld() ?><button class="mini secundair">Afmelden</button></form>
  </nav>
  <?php endif; ?>
</header>
<main class="<?= esc_attr($mainklasse) ?>">
  <?php foreach ($meldingen_flash as [$soort, $tekst]): ?><div class="melding <?= esc_attr($soort) ?>"><?= e($tekst) ?></div><?php endforeach; ?>
  <?php include $inhoud_sjabloon; ?>
</main>
<?php if ($ingelogd): ?>
<nav class="tabbalk">
  <a href="<?= esc_url(app_url('wedstrijden/')) ?>" class="<?= $actief($w_pag) ?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>Wedstrijden</a>
  <a href="<?= esc_url(app_url('zwemmers/')) ?>" class="<?= $actief($z_pag) ?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg><?= $is_staf ? 'Zwemmers' : 'Profiel' ?></a>
  <?php if ($is_secretaris): ?><a href="<?= esc_url(app_url('gebruikers/')) ?>" class="<?= $actief(['gebruikers']) ?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="8" r="3"/><circle cx="17" cy="9" r="2.5"/><path d="M2 20c0-3 3-5 7-5s7 2 7 5M16 14c3 0 6 1.5 6 4.5"/></svg>Gebruikers</a><?php endif; ?>
  <a href="<?= esc_url(app_url('uitleg/')) ?>" class="<?= $actief(['uitleg']) ?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M10 8l6 4-6 4z" fill="currentColor"/></svg>Uitleg</a>
  <a href="<?= esc_url(app_url('meldingen/')) ?>" class="<?= $actief(['meldingen']) ?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0"/></svg>Meldingen</a>
  <a href="#" onclick="document.getElementById('afmelden').submit();return false">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>Afmelden</a>
</nav>
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
<?php if (!empty($scripts)) { include KHZS_PAD . "views/$scripts.php"; } ?>
</body>
</html>
