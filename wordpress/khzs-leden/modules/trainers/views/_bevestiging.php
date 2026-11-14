<?php namespace KHZS; defined('ABSPATH') || exit;
/**
 * Eén voorbije of geplande training met de aanwezigheid van één trainer en knoppen Ja/Nee.
 * Verwacht $b (bezetting) en $_wie (uid van de trainer). Toont de naam van de trainer i.p.v. de training als $_naam waar is.
 */
$_m = $b->moment;
$_naam = $_naam ?? false;
$_afgemeld = isset($b->afwezig[$_wie]);
$_status = $_afgemeld ? null : tr_status($b, $_wie);
$_kan = !$_afgemeld && tr_mag_bevestigen($b, $_wie);
$_badge = ['ja' => ['goedgekeurd', '✓ aanwezig'], 'nee' => ['afgekeurd', '✗ niet aanwezig'], 'open' => ['wacht', '? niet bevestigd'], 'gepland' => ['', 'gepland']];
$_knop = fn(string $actie, string $label, string $klasse) => '<form method="post" action="' . esc_url(app_url('trainers/actie/')) . '" style="display:inline">' . nonce_veld()
    . '<input type="hidden" name="moment" value="' . (int) $_m->id . '"><input type="hidden" name="datum" value="' . esc_attr($b->datum) . '">'
    . '<input type="hidden" name="user" value="' . (int) $_wie . '"><input type="hidden" name="actie" value="' . esc_attr($actie) . '">'
    . '<input type="hidden" name="terug" value="' . esc_attr(huidige_url()) . '"><button class="' . esc_attr($klasse) . '">' . e($label) . '</button></form>';
$_soort = $b->aanwezig[$_wie][0] ?? null;
?>
<div class="tr-rij tr-bev-<?= esc_attr($_status ?? 'afgemeld') ?><?= $b->afgelast ? ' tr-afgelast' : '' ?>">
  <a class="tr-datum" href="<?= esc_url(tr_training_url($_m, $b->datum)) ?>"><b><?= e(datum($b->datum, 'D')) ?></b><span><?= e(datum($b->datum, 'j M')) ?></span></a>
  <div class="tr-info">
    <a href="<?= esc_url(tr_training_url($_m, $b->datum)) ?>"><strong><?= e($_naam ? tr_naam($_wie) : $_m->naam) ?></strong></a>
    <span class="klein"><?= $_naam ? e($_m->naam) . ' · ' : '' ?><?= e(tr_tijd($_m)) ?><?php
      if ($_soort === 'vervanger') { echo ' · vervangt ' . e(tr_voornaam($b->aanwezig[$_wie][1])); } elseif ($_soort === 'extra') { echo ' · extra'; } ?></span>
    <?php if ($_status && ($_door = tr_bevestigd_door($b, $_wie))): ?><span class="klein">bevestigd <?= e($_door) ?></span><?php endif; ?>
  </div>
  <div class="tr-status">
    <?php if ($b->afgelast): ?><span class="badge concept">Afgelast</span>
    <?php elseif ($_afgemeld): ?><span class="badge doorgestuurd">afgemeld<?= isset($b->vervangen[$_wie]) ? ' · ' . e(tr_voornaam($b->vervangen[$_wie])) . ' nam over' : '' ?></span>
    <?php else: [$_k, $_l] = $_badge[$_status]; ?><span class="badge <?= esc_attr($_k) ?>"><?= e($_l) ?></span>
      <?php if ($_kan): ?>
        <?php if ($_status !== 'ja'): ?><?= $_knop('ja', $_status === 'open' ? '✓ Ja, aanwezig' : '✓ Toch aanwezig', 'mini ok') ?><?php endif; ?>
        <?php if ($_status !== 'nee'): ?><?= $_knop('nee', $_status === 'open' ? '✗ Nee' : '✗ Niet aanwezig', 'mini secundair') ?><?php endif; ?>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
