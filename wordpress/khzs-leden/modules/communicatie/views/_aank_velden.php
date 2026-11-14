<?php namespace KHZS; defined('ABSPATH') || exit;
/** Velden van een aankondiging (nieuw en bewerken). Krijgt $v, $f en $kanalen. */ ?>
<?= veld_select('soort', 'Soort', COM_AANK_SOORT, $v['soort'], $f['soort'] ?? null, 'Bij een vraag naar hulp antwoorden leden met ja, nee of misschien, en met hoeveel personen ze komen.') ?>
<?= veld_tekst('titel', 'Titel', $v['titel'], $f['titel'] ?? null, ['maxlength' => 120, 'required' => true]) ?>
<?= veld_textarea('tekst', 'Tekst', $v['tekst'], $f['tekst'] ?? null, 4) ?>
<?= veld_select('kanaal_id', 'Voor wie?', $kanalen, $v['kanaal_id'], $f['kanaal_id'] ?? null, 'Iedereen, of wie het gekozen kanaal ziet.') ?>
<?= veld_tekst('wanneer', 'Wanneer (optioneel)', $v['wanneer'], $f['wanneer'] ?? null, ['maxlength' => 80, 'placeholder' => 'bv. zaterdag 6 december, 8u tot 17u']) ?>
<?= veld_tekst('nodig', 'Hoeveel personen nodig? (enkel bij een vraag naar hulp)', (int) $v['nodig'] ?: '', $f['nodig'] ?? null, ['type' => 'number', 'min' => 0, 'max' => 9999]) ?>
<?= veld_tekst('tot', 'Bovenaan tot en met (optioneel)', $v['tot'] ?? '', $f['tot'] ?? null, ['type' => 'date'], 'Leeg: tot iemand de aankondiging beëindigt.') ?>
