<?php namespace KHZS; defined('ABSPATH') || exit;
include __DIR__ . '/_stijl.php';
$_toegang = array_diff_key(COM_TOEGANG, array_flip(['wedstrijd', 'erven']));
$_ploegen = array_column(array_map(fn($p) => (array) $p, $ploegen), 'naam', 'id');
?>
<div class="com-pad klein"><a href="<?= esc_url(app_url('berichten/')) ?>">← Berichten</a></div>
<h1>Kanalen beheren</h1>
<p class="klein">Elke ploeg krijgt vanzelf een kanaal in de sectie van haar soort, en elke wedstrijd (zodra ze niet meer in concept staat) een thread onder <em>Wedstrijden</em>. Hier maak je secties en extra kanalen.</p>
<?php foreach ($secties as $s): ?>
<div class="kaart">
  <details><summary><strong><?= e($s->naam) ?></strong> <span class="badge"><?= e(COM_SECTIE_SOORT[$s->soort] ?? $s->soort) ?></span></summary>
    <form method="post" action="<?= esc_url(app_url('berichten/beheer/')) ?>"><?= nonce_veld() ?><input type="hidden" name="id" value="<?= (int) $s->id ?>">
      <?= veld_tekst("naam", 'Naam', $s->naam, null, ['maxlength' => 60, 'id' => "s{$s->id}_naam"]) ?>
      <?= veld_select('soort', 'Soort', COM_SECTIE_SOORT, $s->soort, null, 'Trainers van de zwemschool zien geen secties van de soort Competitie.') ?>
      <?= veld_tekst('volgorde', 'Volgorde', (int) $s->volgorde, null, ['type' => 'number']) ?>
      <p class="rij"><button class="mini" name="actie" value="sectie_bewaar">Bewaren</button><button class="mini gevaar" name="actie" value="sectie_verwijder" onclick="return confirm('Sectie verwijderen?')">Verwijderen</button></p></form>
  </details>
  <div class="com-lijst">
    <?php foreach ($per_sectie[(int) $s->id] ?? [] as $k): ?><a class="com-rij" href="<?= esc_url(app_url("berichten/kanaal/{$k->id}/instellingen/")) ?>">
      <span class="com-icoon"><?= com_icoon($k) ?></span><span class="com-rij-info"><b><?= e($k->naam) ?><?php if ((int) $k->gearchiveerd): ?> <span class="badge doorgestuurd">archief</span><?php endif; ?></b>
      <span class="klein"><?= e(COM_TOEGANG[$k->toegang] ?? $k->toegang) ?><?= $k->toegang === 'ploeg' ? ': ' . e(ploeg((int) $k->ploeg_id)->naam ?? 'verwijderde ploeg') : '' ?> · <?= (int) $k->leden_posten ? 'leden posten' : 'leden lezen' ?></span></span><span aria-hidden="true">⚙</span></a>
    <?php endforeach; ?>
    <?php if (empty($per_sectie[(int) $s->id])): ?><p class="klein">Nog geen kanalen.</p><?php endif; ?>
  </div>
</div>
<?php endforeach; ?>

<details class="kaart" <?= $f ? 'open' : '' ?>><summary><strong>+ Nieuw kanaal</strong></summary>
  <form method="post" action="<?= esc_url(app_url('berichten/beheer/')) ?>"><?= nonce_veld() ?><input type="hidden" name="actie" value="kanaal">
    <?= veld_select('sectie_id', 'Sectie', array_map(fn($s) => $s->naam, $secties), $v['sectie_id'], $f['sectie_id'] ?? null) ?>
    <?= veld_tekst('naam', 'Naam', $v['naam'], $f['naam'] ?? null, ['maxlength' => 80, 'required' => true, 'placeholder' => 'bv. Stages']) ?>
    <?= veld_tekst('beschrijving', 'Beschrijving', $v['beschrijving'], null, ['maxlength' => 200]) ?>
    <?= veld_select('toegang', 'Wie ziet dit kanaal?', $_toegang, $v['toegang'], $f['toegang'] ?? null) ?>
    <?= veld_select('ploeg_id', 'Ploeg (bij "Eén ploeg")', [0 => '—'] + $_ploegen, $v['ploeg_id']) ?>
    <?= veld_vinkje('leden_posten', 'Zwemmers en ouders mogen posten', (bool) (int) $v['leden_posten']) ?>
    <p><button class="vol">Kanaal aanmaken</button></p></form>
</details>
<details class="kaart"><summary><strong>+ Nieuwe sectie</strong></summary>
  <form method="post" action="<?= esc_url(app_url('berichten/beheer/')) ?>"><?= nonce_veld() ?><input type="hidden" name="actie" value="sectie">
    <?= veld_tekst('naam', 'Naam', '', null, ['maxlength' => 60, 'required' => true, 'id' => 'nieuwe_sectie']) ?>
    <?= veld_select('soort', 'Soort', COM_SECTIE_SOORT, 'algemeen') ?>
    <p><button class="vol">Sectie aanmaken</button></p></form>
</details>
