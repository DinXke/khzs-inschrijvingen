<?php namespace KHZS; defined('ABSPATH') || exit;
if ($w->info || $bijlagen || $beheer_bijlagen): ?>
<div class="kaart info-wedstrijd">
  <?php if ($w->info): ?><div>ℹ️ <?= nl2br(e($w->info)) ?></div><?php endif; ?>
  <?php if ($bijlagen): ?><div class="bijlagen"><?php foreach ($bijlagen as $b): ?>
    <span class="bijlage"><a href="<?= esc_url(app_url("bijlage/{$b->id}/")) ?>" target="_blank"><?= icoon($b) ?> <?= e($b->naam) ?></a>
      <?php if ($beheer_bijlagen && tec_actief() && !is_demo()): $_publiek = in_array((int) $b->id, publieke_bijlagen(), true); ?><form method="post" action="<?= esc_url(app_url("bijlage/{$b->id}/publiek/")) ?>"><?= nonce_veld() ?>
        <input type="hidden" name="terug" value="<?= esc_attr(huidige_url()) ?>"><input type="hidden" name="publiek" value="<?= $_publiek ? '' : '1' ?>">
        <button class="mini <?= $_publiek ? '' : 'secundair' ?>" title="<?= $_publiek ? 'Publiek: staat op de websitekalender. Klik om privé te maken.' : 'Privé. Klik om publiek te maken (websitekalender, downloaden zonder aanmelden).' ?>">🌐<?= $_publiek ? ' publiek' : '' ?></button></form><?php endif; ?>
      <?php if ($beheer_bijlagen): ?><form method="post" action="<?= esc_url(app_url("bijlage/{$b->id}/verwijderen/")) ?>" onsubmit="return confirm('Bijlage verwijderen?')"><?= nonce_veld() ?>
        <input type="hidden" name="terug" value="<?= esc_attr(huidige_url()) ?>"><button class="mini secundair" title="Verwijderen">✕</button></form><?php endif; ?></span>
  <?php endforeach; ?></div><?php endif; ?>
  <?php if ($beheer_bijlagen): ?>
  <details class="bijlage-nieuw"><summary class="klein">+ Bijlage toevoegen</summary>
    <form method="post" action="<?= esc_url(app_url("wedstrijd/{$w->id}/bijlage/")) ?>" enctype="multipart/form-data" class="rij" style="align-items:end"><?= nonce_veld() ?>
      <input type="hidden" name="terug" value="<?= esc_attr(huidige_url()) ?>">
      <div style="flex:2;min-width:200px"><label for="bijlage-bestand">Bestand</label><input id="bijlage-bestand" type="file" name="bestand" required accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx"></div>
      <div style="flex:1;min-width:160px"><label for="bijlage-naam">Naam (optioneel)</label><input id="bijlage-naam" type="text" name="naam" placeholder="bv. Busregeling"></div>
      <button>Toevoegen</button>
    </form>
    <p class="help">PDF, afbeelding, Word of Excel, max. 10 MB. Zichtbaar voor alle aangemelde zwemmers, ouders en staf.</p>
  </details><?php endif; ?>
</div>
<?php endif;
