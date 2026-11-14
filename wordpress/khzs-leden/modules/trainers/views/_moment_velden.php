<?php namespace KHZS; defined('ABSPATH') || exit; ?>
<?= veld_tekst('naam', 'Naam', $v['naam'], $f['naam'] ?? null, ['maxlength' => 100, 'required' => true, 'placeholder' => 'bv. A-groep, Masters, Zwemschool niveau 2']) ?>
<div class="grid twee">
  <?= veld_select('weekdag', 'Dag', array_map('ucfirst', TR_DAGEN), $v['weekdag'], $f['weekdag'] ?? null) ?>
  <?= veld_tekst('locatie', 'Waar (optioneel)', $v['locatie'], null, ['maxlength' => 100, 'placeholder' => 'bv. Kapermolen groot bad']) ?>
  <?= veld_tekst('start', 'Van', $v['start'], $f['start'] ?? null, ['type' => 'time', 'required' => true]) ?>
  <?= veld_tekst('einde', 'Tot', $v['einde'], $f['einde'] ?? null, ['type' => 'time', 'required' => true]) ?>
  <?= veld_tekst('van', 'Eerste training', $v['van'], $f['van'] ?? null, ['type' => 'date', 'required' => true]) ?>
  <?= veld_tekst('tot', 'Laatste training (optioneel)', $v['tot'] ?? '', $f['tot'] ?? null, ['type' => 'date'], 'Leeg = loopt door.') ?>
</div>
