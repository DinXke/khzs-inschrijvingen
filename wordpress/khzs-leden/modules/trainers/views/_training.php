<?php namespace KHZS; defined('ABSPATH') || exit;
/** Eén training als rij met status en knoppen voor de aangemelde trainer. Verwacht $b (bezetting) en $uid. */
$_m = $b->moment;
$_mijn = $b->aanwezig[$uid] ?? null;
$_afwezig = isset($b->afwezig[$uid]);
$_mag = $b->datum >= vandaag()->format('Y-m-d'); // voorbije trainingen: enkel de aanwezigheid; correcties op de training zelf
$_af = tr_afgelopen($b);
$_knop = function (string $actie, string $label, string $klasse = 'mini secundair', array $extra = []) use ($_m, $b) {
    $h = '<form method="post" action="' . esc_url(app_url('trainers/actie/')) . '" style="display:inline">' . nonce_veld()
        . '<input type="hidden" name="moment" value="' . (int) $_m->id . '"><input type="hidden" name="datum" value="' . esc_attr($b->datum) . '">'
        . '<input type="hidden" name="actie" value="' . esc_attr($actie) . '"><input type="hidden" name="terug" value="' . esc_attr(huidige_url()) . '">';
    foreach ($extra as $k => $v) {
        $h .= '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr($v) . '">';
    }
    return $h . '<button class="' . esc_attr($klasse) . '">' . e($label) . '</button></form>';
};
?>
<div class="tr-rij<?= $b->afgelast ? ' tr-afgelast' : '' ?>">
  <a class="tr-datum" href="<?= esc_url(tr_training_url($_m, $b->datum)) ?>"><b><?= e(datum($b->datum, 'D')) ?></b><span><?= e(datum($b->datum, 'j M')) ?></span></a>
  <div class="tr-info">
    <a href="<?= esc_url(tr_training_url($_m, $b->datum)) ?>"><strong><?= e($_m->naam) ?></strong></a>
    <span class="klein"><?= e(tr_tijd($_m)) ?><?= $_m->locatie ? ' · ' . e($_m->locatie) : '' ?></span>
    <span class="klein tr-wie"><?php
      $namen = [];
      foreach ($b->aanwezig as $id => [$soort, $vervangt]) {
          $_st = $_af && !$b->afgelast ? tr_status($b, $id) : '';
          $namen[] = e(tr_voornaam($id)) . ($soort === 'extra' ? ' <i>(extra)</i>' : '')
              . ($soort === 'vervanger' && $vervangt ? ' <i>(voor ' . e(tr_voornaam($vervangt)) . ')</i>' : '')
              . ($_st ? '<span class="tr-merk ' . $_st . '" title="' . esc_attr(TR_STATUS[$_st]) . '">' . ['ja' => '✓', 'nee' => '✗', 'open' => '?'][$_st] . '</span>' : '');
      }
      foreach ($b->afwezig as $id => $_r) {
          $namen[] = '<s title="afgemeld">' . e(tr_voornaam($id)) . '</s>';
      }
      echo $namen ? '👥 ' . implode(', ', $namen) : '';
      foreach ($b->open as $id) {
          echo ' <span class="badge afgekeurd">vervanger gezocht voor ' . e(tr_voornaam($id)) . '</span>';
      }
    ?></span>
  </div>
  <div class="tr-status">
    <?php if ($b->afgelast): ?><span class="badge concept">Afgelast: <?= e($b->afgelast) ?></span>
    <?php elseif (!$_mag): $_st = $_mijn && $_af ? tr_status($b, $uid) : ''; ?>
      <?php if ($_st): ?><span class="badge <?= ['ja' => 'goedgekeurd', 'nee' => 'afgekeurd', 'open' => 'wacht'][$_st] ?? '' ?>"><?= e(TR_STATUS[$_st]) ?></span>
        <?php if ($_st === 'open' && tr_mag_bevestigen($b, $uid)): ?><?= $_knop('ja', '✓ Ja', 'mini ok') ?><?= $_knop('nee', '✗ Nee') ?><?php endif; ?>
      <?php elseif ($_afwezig): ?><span class="badge doorgestuurd">Afgemeld</span><?php endif; ?>
      <a class="knop mini secundair" href="<?= esc_url(tr_training_url($_m, $b->datum)) ?>">›</a>
    <?php elseif ($_afwezig): ?>
      <span class="badge afgekeurd">Afgemeld</span>
      <?php if (isset($b->vervangen[$uid])): ?><span class="klein"><?= e(tr_voornaam($b->vervangen[$uid])) ?> neemt over</span><?php endif; ?>
      <?= $_knop('aanwezig', 'Ik kan toch') ?>
    <?php elseif ($_mijn && $_mijn[0] === 'vast'): ?>
      <span class="badge goedgekeurd">Ik geef training</span>
      <details class="tr-kannie"><summary class="knop mini secundair">Kan niet</summary>
        <form method="post" action="<?= esc_url(app_url('trainers/actie/')) ?>" class="rij"><?= nonce_veld() ?>
          <input type="hidden" name="moment" value="<?= (int) $_m->id ?>"><input type="hidden" name="datum" value="<?= esc_attr($b->datum) ?>">
          <input type="hidden" name="actie" value="afwezig"><input type="hidden" name="terug" value="<?= esc_attr(huidige_url()) ?>">
          <input type="text" name="reden" placeholder="Reden (optioneel)" maxlength="200" style="flex:1;min-width:140px">
          <button class="mini gevaar">Afmelden</button></form></details>
    <?php elseif ($_mijn): ?>
      <span class="badge doorgestuurd"><?= $_mijn[0] === 'vervanger' ? 'Ik vervang ' . e(tr_voornaam($_mijn[1])) : 'Ik help mee' ?></span>
      <?= $_knop('schrappen', 'Toch niet') ?>
    <?php else: ?>
      <?php foreach ($b->open as $id): ?><?= $_knop('overnemen', 'Ik neem over van ' . tr_voornaam($id), 'mini ok', ['vervangt' => $id]) ?><?php endforeach; ?>
      <?php if (!$b->open): ?><?= $_knop('helpen', 'Ik help mee') ?><?php endif; ?>
    <?php endif; ?>
  </div>
</div>
