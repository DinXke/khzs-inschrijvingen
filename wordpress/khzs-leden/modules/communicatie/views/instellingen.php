<?php namespace KHZS; defined('ABSPATH') || exit;
$_toegang = array_diff_key(COM_TOEGANG, array_flip(['wedstrijd', 'erven']));
$_ploegen = array_column(array_map(fn($p) => (array) $p, $ploegen), 'naam', 'id');
?>
<div class="com-pad klein"><a href="<?= esc_url(app_url("berichten/kanaal/{$k->id}/")) ?>">← <?= e(com_volle_naam($k)) ?></a></div>
<h1>Instellingen</h1>
<form method="post" class="kaart" action="<?= esc_url(app_url("berichten/kanaal/{$k->id}/instellingen/")) ?>"><?= nonce_veld() ?>
  <?php if ($auto): ?>
    <p><strong><?= e($k->naam) ?></strong><br><span class="klein"><?= $k->toegang === 'wedstrijd' ? 'De naam en de deelnemers volgen de wedstrijd.' : 'De naam en de leden volgen de ploeg.' ?></span></p>
  <?php else: ?>
    <?= veld_tekst('naam', 'Naam', $v['naam'], $f['naam'] ?? null, ['maxlength' => 80, 'required' => true]) ?>
  <?php endif; ?>
  <?= veld_tekst('beschrijving', 'Beschrijving', $v['beschrijving'], null, ['maxlength' => 200]) ?>
  <?php if ($bestuur && !(int) $k->ouder_id): ?>
    <?= veld_select('sectie_id', 'Sectie', array_map(fn($s) => $s->naam, com_secties()), $v['sectie_id']) ?>
    <?php if (!$auto && $k->functie !== 'wedstrijden'): ?>
      <?= veld_select('toegang', 'Wie ziet dit kanaal?', $_toegang, $v['toegang'], $f['toegang'] ?? null, 'Trainers, bestuur en beheer zien elk kanaal (trainers van de zwemschool alles buiten de competitie).') ?>
      <?= veld_select('ploeg_id', 'Ploeg (bij "Eén ploeg")', [0 => '—'] + $_ploegen, $v['ploeg_id']) ?>
    <?php endif; ?>
    <?= veld_tekst('volgorde', 'Volgorde in de sectie', (int) $v['volgorde'], null, ['type' => 'number']) ?>
  <?php elseif ($bestuur && (int) $k->ouder_id && !$auto): ?>
    <?= veld_select('toegang', 'Wie ziet deze thread?', ['erven' => 'Zoals ' . (com_kanaal((int) $k->ouder_id)->naam ?? 'het kanaal'), 'staf' => 'Enkel trainers en bestuur'], $v['toegang']) ?>
  <?php endif; ?>
  <?= veld_vinkje('leden_posten', 'Zwemmers en ouders mogen posten', (bool) (int) $v['leden_posten'], 'Uit: enkel trainers en bestuur schrijven, de leden lezen.') ?>
  <?= veld_vinkje('gearchiveerd', 'Gearchiveerd (enkel nog lezen)', (bool) (int) $v['gearchiveerd'], $k->functie === 'ploeg' ? 'Een gearchiveerde ploeg zet haar kanaal vanzelf in het archief.' : '') ?>
  <p><button class="vol">Bewaren</button></p>
</form>
<?php if ($bestuur && !$auto && $k->functie !== 'wedstrijden'): ?>
<form method="post" class="kaart" action="<?= esc_url(app_url("berichten/kanaal/{$k->id}/instellingen/")) ?>" onsubmit="return confirm('<?= esc_js(com_volle_naam($k)) ?> en alle berichten<?= (int) $k->ouder_id ? '' : ' en threads' ?> definitief verwijderen?')"><?= nonce_veld() ?>
  <p class="klein">Verwijderen wist alle berichten<?= (int) $k->ouder_id ? '' : ' en threads' ?> van dit kanaal. Archiveren bewaart ze.</p>
  <button class="gevaar" name="actie" value="verwijderen">Kanaal verwijderen</button></form>
<?php endif; ?>
