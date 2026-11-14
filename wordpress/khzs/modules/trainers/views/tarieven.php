<?php namespace KHZS; defined('ABSPATH') || exit; include __DIR__ . '/_stijl.php'; $url = app_url('trainers/tarieven/'); ?>
<div class="rij tussen"><h1>Tarieven trainers</h1><a class="knop secundair" href="<?= esc_url(app_url('trainers/uren/')) ?>">Verloning per maand</a></div>
<form method="post" class="kaart" action="<?= esc_url($url) ?>"><?= nonce_veld() ?>
  <h2 style="margin-top:0">Tarief instellen</h2>
  <div class="grid twee">
    <?= veld_select('user', 'Trainer', ['' => '— kies —'] + array_map(__NAMESPACE__ . '\\tr_naam', $trainers), $v['user'], $fout['user'] ?? null) ?>
    <?= veld_select('soort', 'Soort', TR_TARIEF, $v['soort'], $fout['soort'] ?? null) ?>
    <?= veld_tekst('bedrag', 'Bedrag (€)', $v['bedrag'], $fout['bedrag'] ?? null, ['inputmode' => 'decimal', 'placeholder' => 'bv. 15,00', 'required' => true]) ?>
    <?= veld_tekst('geldig_vanaf', 'Geldig vanaf', $v['geldig_vanaf'], $fout['geldig_vanaf'] ?? null, ['type' => 'date', 'required' => true]) ?>
  </div>
  <?= veld_tekst('opmerking', 'Opmerking (optioneel)', $v['opmerking'], null, ['maxlength' => 200, 'placeholder' => 'bv. vrijwilligersvergoeding, initiator, diploma trainer B']) ?>
  <p class="help">Een nieuw tarief geldt vanaf de gekozen datum; vroegere trainingen houden hun oude tarief.</p>
  <p><button>Bewaren</button></p>
</form>
<div class="kaart tabelwrap"><table>
  <tr><th>Trainer</th><th>Huidig tarief</th><th>Geschiedenis</th></tr>
  <?php foreach ($trainers as $uid => $u): $l = $tarieven[$uid]; $nu = tr_tarief_op($l, $vandaag); ?>
  <tr><td><?= e(tr_naam($u)) ?></td>
    <td><?= $nu ? '<b>' . e(euro($nu->bedrag_cent)) . '</b> ' . e(TR_TARIEF[$nu->soort]) . ($nu->opmerking ? '<div class="klein">' . e($nu->opmerking) . '</div>' : '') : '<span class="badge afgekeurd">geen tarief</span>' ?></td>
    <td class="klein"><?php foreach ($l as $t): ?><div class="rij" style="gap:.4rem"><?= e(euro($t->bedrag_cent) . ' ' . TR_TARIEF[$t->soort]) ?> vanaf <?= e(datum($t->geldig_vanaf, 'j/m/Y')) ?>
      <form method="post" action="<?= esc_url($url) ?>" onsubmit="return confirm('Tarief verwijderen? Dit verandert de bedragen van de trainingen waarvoor het gold.')"><?= nonce_veld() ?><input type="hidden" name="actie" value="verwijder"><input type="hidden" name="id" value="<?= (int) $t->id ?>"><button class="mini secundair" title="Verwijderen">✕</button></form></div><?php endforeach; ?></td></tr>
  <?php endforeach; ?>
</table></div>
