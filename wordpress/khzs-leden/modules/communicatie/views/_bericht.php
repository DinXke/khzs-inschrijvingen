<?php namespace KHZS; defined('ABSPATH') || exit;
/** Eén bericht in de chat. Krijgt $_b, $_vorige, $_vermeld, $_k, $_uid en $_mod (zie com_berichten_html()). */
$_eigen = (int) $_b->user_id === $_uid;
$_nieuwe_dag = !$_vorige || substr($_vorige->aangemaakt, 0, 10) !== substr($_b->aangemaakt, 0, 10);
$_vervolg = !$_nieuwe_dag && (int) $_vorige->user_id === (int) $_b->user_id && !$_vorige->verwijderd
    && strtotime($_b->aangemaakt) - strtotime($_vorige->aangemaakt) < 300;
$_naam = com_naam((int) $_b->user_id);
$_mij = !$_eigen && in_array(com_naam($_uid), $_vermeld, true);
?>
<?php if ($_nieuwe_dag): ?><div class="com-dag"><?= e(com_dag_label($_b->aangemaakt)) ?></div><?php endif; ?>
<div class="com-b<?= $_eigen ? ' com-eigen' : '' ?><?= $_vervolg ? ' com-vervolg' : '' ?><?= $_mij ? ' com-mij' : '' ?>" id="b<?= (int) $_b->id ?>">
  <span class="com-av" aria-hidden="true"><?= e(com_initialen($_naam)) ?></span>
  <div class="com-ballon">
    <?php if (!$_vervolg && !$_eigen): ?><div class="com-naam"><?= e($_naam) ?><?php if ($_r = com_rol_label((int) $_b->user_id)): ?><span class="com-rol"><?= e($_r) ?></span><?php endif; ?></div><?php endif; ?>
    <?php if ($_b->verwijderd): ?><span class="com-weg">Bericht verwijderd</span>
    <?php else: ?>
      <?php if ($_b->bijlage && $_b->bijlage_type === 'pdf'): ?><a class="com-pdf" href="<?= esc_url(app_url("berichten/bijlage/{$_b->id}/")) ?>" target="_blank">📄 <?= e($_b->bijlage_naam) ?></a>
      <?php elseif ($_b->bijlage): ?><a href="<?= esc_url(app_url("berichten/bijlage/{$_b->id}/")) ?>" target="_blank"><img class="com-foto" loading="lazy" src="<?= esc_url(app_url("berichten/bijlage/{$_b->id}/")) ?>" alt="<?= esc_attr($_b->bijlage_naam) ?>"></a><?php endif; ?>
      <?php if ($_b->tekst !== ''): ?><div><?= com_opmaak($_b->tekst, $_vermeld) ?></div><?php endif; ?>
    <?php endif; ?>
    <span class="com-tijd"><?= $_b->vastgepind ? '📌 ' : '' ?><?= e(substr($_b->aangemaakt, 11, 5)) ?><?= $_b->bewerkt && !$_b->verwijderd ? ' · bewerkt' : '' ?></span>
  </div>
  <?php if (!$_b->verwijderd && ($_eigen || $_mod)): ?>
  <details class="com-menu"><summary aria-label="Acties voor dit bericht" title="Acties">⋯</summary>
    <div class="com-menu-blad">
      <?php if ($_eigen && !(int) $_k->gearchiveerd): ?>
      <form method="post" action="<?= esc_url(app_url("berichten/bericht/{$_b->id}/")) ?>"><?= nonce_veld() ?><input type="hidden" name="actie" value="bewerken">
        <textarea name="tekst" aria-label="Bericht bewerken"><?= esc_textarea($_b->tekst) ?></textarea>
        <button class="mini vol">Bewaren</button></form>
      <?php endif; ?>
      <?php if ($_mod): ?>
      <form method="post" action="<?= esc_url(app_url("berichten/bericht/{$_b->id}/")) ?>"><?= nonce_veld() ?>
        <button class="mini secundair vol" name="actie" value="<?= $_b->vastgepind ? 'losmaken' : 'vastpinnen' ?>"><?= $_b->vastgepind ? 'Losmaken' : '📌 Vastpinnen' ?></button></form>
      <?php endif; ?>
      <form method="post" action="<?= esc_url(app_url("berichten/bericht/{$_b->id}/")) ?>" onsubmit="return confirm('Dit bericht verwijderen?')"><?= nonce_veld() ?>
        <button class="mini gevaar vol" name="actie" value="verwijderen">Verwijderen</button></form>
    </div>
  </details>
  <?php endif; ?>
</div>
