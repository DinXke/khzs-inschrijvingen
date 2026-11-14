<?php namespace KHZS; defined('ABSPATH') || exit; ?>
<div class="rij tussen"><h1><?= $alle ? 'Zwemmers' : 'Mijn zwemmers' ?></h1>
  <div class="rij"><?php if ($is_staf): ?><a class="knop" href="<?= esc_url(app_url('zwemmers/uitslagen/')) ?>">Uitslagen importeren</a><?php endif; ?><a class="knop secundair" href="<?= esc_url(app_url('zwemmers/nieuw/')) ?>">+ Zwemmer</a></div></div>
<?php if ($alle): ?><form method="get" class="kaart" action="<?= esc_url(app_url('zwemmers/')) ?>"><?= pad_veld('zwemmers/') ?><input type="search" name="q" value="<?= esc_attr($q) ?>" placeholder="Zoek op naam…"></form><?php endif; ?>
<?php foreach ($zwemmers as $z): ?>
<a class="kaart" href="<?= esc_url(app_url("zwemmers/{$z->id}/")) ?>"><div class="rij tussen"><strong><?= e("{$z->voornaam} {$z->naam}") ?></strong><span class="klein"><?= e(datum($z->geboortedatum, 'j/m/Y')) ?> · <?= e(GESLACHT[$z->geslacht] ?? '') ?></span></div>
  <?php if ($alle): ?><div class="klein"><?= e($z->email ?? '') ?><?= $z->licentie ? ' · licentie ' . e($z->licentie) : '' ?></div><?php endif; ?></a>
<?php endforeach; ?>
<?php if (!$zwemmers): ?><div class="leeg">Nog geen zwemmers.</div><?php endif; ?>
<?php if (!$alle): ?><p class="klein">Eén account kan meerdere zwemmers hebben, bv. al je kinderen. Voeg ze toe met <b>+ Zwemmer</b>; bij elke wedstrijd kies je dan voor wie je inschrijft.</p><?php endif; ?>
