<?php namespace KHZS; defined('ABSPATH') || exit; include __DIR__ . '/_stijl.php';
/** Kaartje op de pagina van een zwemmer (actie khzs_zwemmer_pagina). Verwacht $z, $van, $tot en $rijen (tr_zw_rijen). */
$_t = tr_zw_stats($rijen)['totaal']; ?>
<h2>Trainingen</h2>
<a class="kaart" href="<?= esc_url(app_url("aanwezigheid/{$z->id}/")) ?>" style="display:block;text-decoration:none;color:inherit">
  <div class="rij tussen"><strong><?= e(tr_zw_procent($_t)) ?> aanwezig</strong><span class="klein">Statistiek →</span></div>
  <?= tr_zw_balk($_t) ?>
  <span class="klein"><?= tr_zw_er($_t) ?> van de <?= (int) $_t['totaal'] ?> trainingen sinds <?= e(datum($van, 'j F Y')) ?><?= $_t['laat'] ? ' · ' . (int) $_t['laat'] . '× te laat' : '' ?><?= $_t['ziek'] ? ' · ' . (int) $_t['ziek'] . '× ziek' : '' ?></span>
</a>
