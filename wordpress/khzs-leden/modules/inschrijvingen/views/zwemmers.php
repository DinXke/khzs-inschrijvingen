<?php namespace KHZS; defined('ABSPATH') || exit; ?>
<div class="rij tussen"><h1><?= $alle ? 'Zwemmers' : 'Mijn zwemmers' ?></h1>
  <div class="rij"><?php if ($is_staf): ?><a class="knop" href="<?= esc_url(app_url('zwemmers/uitslagen/')) ?>">Uitslagen importeren</a><?php endif; ?><?php if ($zelf_ontbreekt): ?><a class="knop secundair" href="<?= esc_url(app_url('zwemmers/nieuw/', ['zelf' => 1])) ?>">+ Mezelf</a><?php endif; ?><a class="knop secundair" href="<?= esc_url(app_url('zwemmers/nieuw/')) ?>">+ Zwemmer</a><a class="knop secundair" href="<?= esc_url(app_url('ploegen/')) ?>">Ploegen</a><a class="knop secundair" href="<?= esc_url(app_url('rollen/')) ?>">Mijn rollen</a></div></div>
<?php if ($alle): ?><form method="get" class="kaart" action="<?= esc_url(app_url('zwemmers/')) ?>"><?= pad_veld('zwemmers/') ?><input type="search" name="q" value="<?= esc_attr($q) ?>" placeholder="Zoek op naam…"></form><?php endif; ?>
<?php foreach ($zwemmers as $z): ?>
<a class="kaart" href="<?= esc_url(app_url("zwemmers/{$z->id}/")) ?>"><div class="rij tussen"><strong><?= e("{$z->voornaam} {$z->naam}") ?></strong><span class="klein"><?= e(datum($z->geboortedatum, 'j/m/Y')) ?> · <?= e(GESLACHT[$z->geslacht] ?? '') ?></span></div>
  <?php if ($alle): ?><div class="klein"><?= e($z->email ?? '') ?><?= $z->licentie ? ' · licentie ' . e($z->licentie) : '' ?></div><?php endif; ?></a>
<?php endforeach; ?>
<?php if (!$zwemmers): ?><div class="leeg">Nog geen zwemmers.</div><?php endif; ?>
<form method="post" action="<?= esc_url(app_url('zwemmers/koppelen/')) ?>" class="kaart rij" style="align-items:end"><?= nonce_veld() ?>
  <div style="flex:1;min-width:200px"><label for="koppelcode">Zwemmer koppelen met een code</label>
    <input id="koppelcode" name="code" placeholder="bv. K7QM-2PXA" autocomplete="off" autocapitalize="characters" required></div>
  <button class="secundair">Koppelen</button>
  <p class="help" style="flex-basis:100%;margin:0">Heeft de andere ouder je kind al toegevoegd? Vraag een koppelcode (bij de zwemmer → ‘Koppelcode maken’) en vul ze hier in.</p>
</form>
<?php if (!$alle && $zelf_ontbreekt): ?><p class="klein">Zwem je zelf (bv. masters)? Voeg jezelf toe met <a href="<?= esc_url(app_url('zwemmers/nieuw/', ['zelf' => 1])) ?>"><b>+ Mezelf</b></a>.</p><?php endif; ?>
<?php if (!$alle): ?><p class="klein">Eén account kan meerdere zwemmers hebben, bv. al je kinderen. Voeg ze toe met <b>+ Zwemmer</b>; bij elke wedstrijd kies je dan voor wie je inschrijft.</p><?php endif; ?>
