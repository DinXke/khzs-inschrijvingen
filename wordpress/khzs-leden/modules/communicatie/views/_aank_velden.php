<?php namespace KHZS; defined('ABSPATH') || exit;
/** Velden van een aankondiging (nieuw en bewerken). Krijgt $v, $f en $kanalen (id => naam; 0 = Iedereen). */ ?>
<?= veld_select('soort', 'Soort', COM_AANK_SOORT, $v['soort'], $f['soort'] ?? null, 'Bij een vraag naar hulp antwoorden leden met ja, nee of misschien, en met hoeveel personen ze komen.') ?>
<?= veld_tekst('titel', 'Titel', $v['titel'], $f['titel'] ?? null, ['maxlength' => 120, 'required' => true]) ?>
<?= veld_textarea('tekst', 'Tekst', $v['tekst'], $f['tekst'] ?? null, 4) ?>
<?php $_gekozen = array_map('intval', explode(',', (string) $v['kanalen'] ?: '0')); ?>
<fieldset class="veld com-voor-wie"><legend>Voor wie?</legend>
  <div class="com-vinkjes">
    <?php foreach ($kanalen as $_id => $_naam): ?><label><input type="checkbox" name="kanalen[]" value="<?= (int) $_id ?>"<?= in_array((int) $_id, $_gekozen, true) ? ' checked' : '' ?>> <?= e($_naam) ?></label>
    <?php endforeach; ?>
  </div>
  <div class="help">Iedereen, of wie minstens één van de gekozen kanalen ziet. Je mag meerdere kanalen kiezen.</div><?= fouten_html($f['kanalen'] ?? null) ?>
</fieldset>
<script>
// 'Iedereen' en losse kanalen sluiten elkaar uit.
document.querySelectorAll('.com-voor-wie').forEach(function (v) {
  v.addEventListener('change', function (e) {
    if (!e.target.checked) return;
    v.querySelectorAll('input[name="kanalen[]"]').forEach(function (i) {
      if (i !== e.target && (e.target.value === '0' || i.value === '0')) i.checked = false;
    });
  });
});
</script>
<?= veld_tekst('wanneer', 'Wanneer (optioneel)', $v['wanneer'], $f['wanneer'] ?? null, ['maxlength' => 80, 'placeholder' => 'bv. zaterdag 6 december, 8u tot 17u']) ?>
<?= veld_tekst('nodig', 'Hoeveel personen nodig? (enkel bij een vraag naar hulp)', (int) $v['nodig'] ?: '', $f['nodig'] ?? null, ['type' => 'number', 'min' => 0, 'max' => 9999]) ?>
<?= veld_tekst('tot', 'Bovenaan tot en met (optioneel)', $v['tot'] ?? '', $f['tot'] ?? null, ['type' => 'date'], 'Leeg: tot iemand de aankondiging beëindigt.') ?>
