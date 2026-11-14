<?php namespace KHZS; defined('ABSPATH') || exit; ?><!doctype html>
<html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Offline · <?= e(get_option('khzs_app_kort', 'HZS')) ?></title><link rel="stylesheet" href="<?= esc_url(asset('app.css')) ?>"></head>
<body><main class="smal">
<div class="leeg"><h1>Geen verbinding</h1><p>Je bent offline. Inschrijven kan enkel met internet.</p>
<p><button onclick="location.reload()">Opnieuw proberen</button></p></div>
</main></body></html>
