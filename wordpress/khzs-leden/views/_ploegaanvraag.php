<?php namespace KHZS; defined('ABSPATH') || exit;
/** Eén open ploegaanvraag ($_l) met knoppen goedkeuren/afkeuren; $_terug = pad om naar terug te keren. */
$_door = $_l->aangevraagd_door ? get_userdata((int) $_l->aangevraagd_door) : null; ?>
<div class="melding-item">
  <div class="rij tussen"><span><strong><?= e("{$_l->voornaam} {$_l->naam}") ?></strong> vraagt <strong><?= e($_l->ploegnaam) ?></strong></span>
    <span class="klein"><?= e(datum($_l->aangemaakt, 'j/m/Y')) ?><?= $_door ? ' · ' . e(volle_naam($_door) ?: $_door->user_login) : '' ?></span></div>
  <form method="post" action="<?= esc_url(app_url("ploegen/lid/{$_l->id}/")) ?>" class="rij" style="margin-top:.4rem;align-items:start"><?= nonce_veld() ?><input type="hidden" name="terug" value="<?= esc_attr($_terug) ?>">
    <button name="actie" value="goedkeuren" class="mini">Goedkeuren</button>
    <details style="flex:1;min-width:220px"><summary class="klein">Afkeuren</summary>
      <div class="rij" style="margin-top:.4rem;align-items:end">
        <div style="flex:1;min-width:160px"><label for="pr-<?= (int) $_l->id ?>">Reden</label><input type="text" id="pr-<?= (int) $_l->id ?>" name="reden" maxlength="300"></div>
        <button name="actie" value="afkeuren" class="mini secundair">Afkeuren</button></div></details>
  </form>
</div>
