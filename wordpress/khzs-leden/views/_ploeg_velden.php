<?php namespace KHZS; defined('ABSPATH') || exit; ?>
<?= veld_tekst('naam', 'Naam', $v['naam'] ?? '', $f['naam'] ?? null, ['maxlength' => 60, 'required' => true]) ?>
<?= veld_tekst('beschrijving', 'Beschrijving', $v['beschrijving'] ?? '', null, ['maxlength' => 200]) ?>
<div class="veld"><label for="soort">Soort</label><select id="soort" name="soort">
  <?php foreach (PL_SOORT as $_k => $_n): ?><option value="<?= esc_attr($_k) ?>" <?= ($v['soort'] ?? 'competitie') === $_k ? 'selected' : '' ?>><?= e($_n) ?></option><?php endforeach; ?></select>
  <div class="help">De trainers van de zwemschool zien alle ploegen van de zwemschool.</div></div>
<div class="veld"><label>Trainers</label><div>
  <?php foreach ($alle_trainers as $_t): ?><div><label><input type="checkbox" name="trainers[]" value="<?= (int) $_t->ID ?>" <?= in_array((int) $_t->ID, array_map('intval', $gekozen), true) ? 'checked' : '' ?>> <?= e(volle_naam($_t) ?: $_t->user_login) ?></label></div><?php endforeach; ?>
  <?php if (!$alle_trainers): ?><span class="klein">Nog geen accounts met een rol trainer.</span><?php endif; ?></div>
  <div class="help">Accounts met de rol trainer competitie of trainer zwemschool.</div></div>
<?= veld_vinkje('actief', 'Actief', (bool) ($v['actief'] ?? 1), 'Een gearchiveerde ploeg kan je niet meer kiezen; de leden blijven bewaard.') ?>
