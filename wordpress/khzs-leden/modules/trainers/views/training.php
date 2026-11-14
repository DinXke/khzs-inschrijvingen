<?php namespace KHZS; defined('ABSPATH') || exit; include __DIR__ . '/_stijl.php';
$actie_form = function (string $actie, string $label, string $klasse, array $velden = []) use ($m, $datum) {
    $h = '<form method="post" action="' . esc_url(app_url('trainers/actie/')) . '" style="display:inline">' . nonce_veld()
        . '<input type="hidden" name="moment" value="' . (int) $m->id . '"><input type="hidden" name="datum" value="' . esc_attr($datum) . '">'
        . '<input type="hidden" name="actie" value="' . esc_attr($actie) . '">';
    foreach ($velden as $k => $v) {
        $h .= '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr($v) . '">';
    }
    return $h . '<button class="' . esc_attr($klasse) . '">' . e($label) . '</button></form>';
};
$af = tr_afgelopen($b);
$badge = ['ja' => ['goedgekeurd', '✓ aanwezig'], 'nee' => ['afgekeurd', '✗ niet aanwezig'], 'open' => ['wacht', '? niet bevestigd']]; ?>
<p><a href="<?= esc_url(app_url('trainers/planning/', ['week' => $datum])) ?>">← Planning</a></p>
<h1><?= e($m->naam) ?></h1>
<div class="stappen">
  <div class="stap">Datum<b><?= e(datum($datum, 'D j M Y')) ?></b></div>
  <div class="stap">Uur<b><?= e(tr_tijd($m)) ?></b></div>
  <?php if ($m->locatie): ?><div class="stap">Waar<b><?= e($m->locatie) ?></b></div><?php endif; ?>
</div>
<?php if ($b->afgelast): ?><div class="melding info">Deze training is afgelast: <?= e($b->afgelast) ?></div><?php endif; ?>

<div class="kaart">
  <h2 style="margin-top:0"><?= $voorbij ? 'Wie gaf training?' : 'Wie geeft training?' ?></h2>
  <?php foreach ($b->aanwezig as $id => [$soort, $vervangt]): $st = $af && !$b->afgelast ? tr_status($b, $id) : ''; $door = $st ? tr_bevestigd_door($b, $id) : ''; ?>
  <div class="tr-rij"><div class="tr-info"><strong><?= e(tr_naam($id)) ?><?= $id === $uid ? ' (jij)' : '' ?></strong>
    <span class="klein"><?= $soort === 'vervanger' ? 'vervangt ' . e(tr_voornaam($vervangt)) : e(TR_SOORT[$soort]) ?><?= $door ? ' · bevestigd ' . e($door) : '' ?></span></div>
    <div class="tr-status">
    <?php if ($st): ?><span class="badge <?= $badge[$st][0] ?>"><?= e($badge[$st][1]) ?></span>
      <?php if (tr_mag_bevestigen($b, $id)): ?>
        <?php if ($st !== 'ja'): ?><?= $actie_form('ja', $id === $uid ? '✓ Ik was er' : '✓ Aanwezig', 'mini ok', ['user' => $id]) ?><?php endif; ?>
        <?php if ($st !== 'nee'): ?><?= $actie_form('nee', $id === $uid ? '✗ Ik was er niet' : '✗ Niet aanwezig', 'mini secundair', ['user' => $id]) ?><?php endif; ?>
        <?php if ($beheer && $st !== 'open' && $b->datum >= (string) get_option('khzs_tr_bevestigen_vanaf', '')): ?><?= $actie_form('wis', 'Wissen', 'mini secundair', ['user' => $id]) ?><?php endif; ?>
      <?php endif; ?>
    <?php endif; ?>
    <?php if ($mag && $soort !== 'vast' && ($id === $uid || $beheer)): ?><?= $actie_form('schrappen', $id === $uid ? 'Toch niet' : 'Schrappen', 'mini secundair', ['user' => $id]) ?><?php endif; ?>
    <?php if ($beheer && $soort === 'vast' && $id !== $uid): ?><?= $actie_form('afwezig', $voorbij ? 'Was afgemeld' : 'Afwezig zetten', 'mini secundair', ['user' => $id]) ?><?php endif; ?></div></div>
  <?php endforeach; ?>
  <?php if (!$b->aanwezig): ?><p class="klein">Nog niemand.</p><?php endif; ?>
  <?php foreach ($b->afwezig as $id => $reden): ?>
  <div class="tr-rij"><div class="tr-info"><s><?= e(tr_naam($id)) ?></s>
    <span class="klein">kan niet<?= $reden ? ': ' . e($reden) : '' ?><?= isset($b->vervangen[$id]) ? ' · ' . e(tr_voornaam($b->vervangen[$id])) . ' neemt over' : '' ?></span></div>
    <div class="tr-status">
      <?php if (!isset($b->vervangen[$id])): ?><?php if (!$voorbij): ?><span class="badge afgekeurd">vervanger gezocht</span><?php endif; ?>
        <?php if ($mag && !isset($b->aanwezig[$uid]) && $id !== $uid && !$b->afgelast && tr_is_trainer()): ?><?= $actie_form('overnemen', $voorbij ? 'Ik heb overgenomen' : 'Ik neem over', 'mini ok', ['vervangt' => $id]) ?><?php endif; ?><?php endif; ?>
      <?php if ($mag && ($id === $uid || $beheer)): ?><?= $actie_form('aanwezig', $voorbij ? ($id === $uid ? 'Ik was er toch' : 'Was er toch') : ($id === $uid ? 'Ik kan toch' : 'Toch aanwezig'), 'mini secundair', ['user' => $id]) ?><?php endif; ?>
    </div></div>
  <?php endforeach; ?>
</div>

<?php include __DIR__ . '/_zwemmers.php'; ?>

<?php if ($mag && !$b->afgelast): ?>
<div class="kaart">
  <?php if (!$voorbij && ($b->aanwezig[$uid][0] ?? '') === 'vast'): ?>
    <form method="post" action="<?= esc_url(app_url('trainers/actie/')) ?>" class="rij" style="align-items:end"><?= nonce_veld() ?>
      <input type="hidden" name="moment" value="<?= (int) $m->id ?>"><input type="hidden" name="datum" value="<?= esc_attr($datum) ?>"><input type="hidden" name="actie" value="afwezig">
      <div style="flex:1;min-width:200px"><label for="reden">Ik kan niet</label><input id="reden" type="text" name="reden" maxlength="200" placeholder="Reden (optioneel, zichtbaar voor collega's)"></div>
      <button class="gevaar">Afmelden</button></form>
  <?php elseif (tr_is_trainer() && !isset($b->aanwezig[$uid]) && !isset($b->afwezig[$uid]) && !$b->open): ?>
    <?= $actie_form('helpen', $voorbij ? '🙋 Ik was er ook (extra trainer)' : '🙋 Ik help mee op deze training', 'secundair') ?>
  <?php endif; ?>
  <?php if ($beheer): ?>
    <form method="post" action="<?= esc_url(app_url('trainers/actie/')) ?>" class="rij" style="align-items:end;margin-top:.8rem"><?= nonce_veld() ?>
      <input type="hidden" name="moment" value="<?= (int) $m->id ?>"><input type="hidden" name="datum" value="<?= esc_attr($datum) ?>"><input type="hidden" name="actie" value="toevoegen">
      <div style="flex:1;min-width:160px"><label for="tr-user">Trainer toevoegen (bestuur)</label>
        <select id="tr-user" name="user"><?php foreach ($trainers as $id => $u): if (isset($b->aanwezig[$id])) { continue; } ?><option value="<?= (int) $id ?>"><?= e(tr_naam($u)) ?></option><?php endforeach; ?></select></div>
      <?php if ($b->open): ?><div style="min-width:140px"><label for="tr-vervangt">Als vervanger van</label>
        <select id="tr-vervangt" name="vervangt"><option value="">— extra —</option><?php foreach ($b->open as $id): ?><option value="<?= (int) $id ?>"><?= e(tr_naam($id)) ?></option><?php endforeach; ?></select></div><?php endif; ?>
      <button class="secundair">Toevoegen</button></form>
    <?php if ($voorbij): ?><p class="help">Wie je achteraf toevoegt, staat meteen als aanwezig. De trainer krijgt van elke wijziging een melding.</p><?php endif; ?>
  <?php endif; ?>
</div>
<?php elseif (!$mag): ?><p class="klein">Deze maand is al uitbetaald. Correcties gebeuren door het bestuur.</p><?php endif; ?>

<?php if ($wijz): ?>
<details class="kaart"><summary class="klein">Geschiedenis</summary><ul class="klein">
  <?php foreach ($wijz as $w): ?><li><?= e(datum($w->aangemaakt, 'j M H:i')) ?> · <?= e(tr_naam((int) $w->user_id)) ?>: <?= e(['afwezig' => 'kan niet', 'vervanger' => 'neemt over', 'extra' => 'helpt mee'][$w->soort] ?? $w->soort) ?><?= $w->door_id && (int) $w->door_id !== (int) $w->user_id ? ' (door ' . e(tr_voornaam((int) $w->door_id)) . ')' : '' ?></li><?php endforeach; ?>
</ul></details>
<?php endif; ?>
