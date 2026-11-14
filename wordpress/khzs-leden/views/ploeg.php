<?php namespace KHZS; defined('ABSPATH') || exit; $_hier = app_url("ploegen/{$p->id}/"); ?>
<div class="rij tussen"><h1><?= e($p->naam) ?><?php if (!(int) $p->actief): ?> <span class="badge doorgestuurd">gearchiveerd</span><?php endif; ?></h1><a class="knop secundair" href="<?= esc_url(app_url('ploegen/')) ?>">Alle ploegen</a></div>
<?php if ($p->beschrijving !== ''): ?><p><?= e($p->beschrijving) ?></p><?php endif; ?>
<p class="klein">Trainers: <?= e(implode(', ', array_map(fn($u) => volle_naam($u) ?: $u->user_login, $trainers)) ?: 'nog niemand') ?></p>
<?php if ($aanvragen): ?>
<div class="kaart melding-lijst"><h2 style="margin-top:0">Aanvragen (<?= count($aanvragen) ?>)</h2>
  <?php $_terug = $_hier; foreach ($aanvragen as $_l) { include __DIR__ . '/_ploegaanvraag.php'; } ?></div>
<?php endif; ?>
<h2>Zwemmers (<?= count($leden) ?>)</h2>
<div class="kaart">
  <?php foreach ($leden as $l): ?><div class="rij tussen" style="padding:.35rem 0;border-bottom:1px solid var(--lijn)">
    <span><?php if ($is_staf): ?><a href="<?= esc_url(app_url("zwemmers/{$l->zwemmer_id}/")) ?>"><?= e("{$l->voornaam} {$l->naam}") ?></a><?php else: ?><?= e("{$l->voornaam} {$l->naam}") ?><?php endif; ?></span>
    <?php if ($behandelen): ?><form method="post" action="<?= esc_url(app_url("ploegen/lid/{$l->id}/")) ?>" onsubmit="return confirm(<?= esc_attr(wp_json_encode("{$l->voornaam} uit {$p->naam} halen?")) ?>)"><?= nonce_veld() ?>
      <input type="hidden" name="terug" value="<?= esc_attr($_hier) ?>"><button name="actie" value="verwijderen" class="mini secundair">Uit ploeg</button></form><?php endif; ?>
  </div><?php endforeach; ?>
  <?php if (!$leden): ?><p class="klein">Nog geen zwemmers in deze ploeg.</p><?php endif; ?>
  <?php if ($behandelen && $toe_te_voegen): ?><form method="post" action="<?= esc_url($_hier) ?>" class="rij" style="margin-top:.6rem;align-items:end"><?= nonce_veld() ?><input type="hidden" name="actie" value="toevoegen">
    <div style="flex:1;min-width:200px"><label for="zw">Zwemmer toevoegen</label>
      <select id="zw" name="zwemmer" required><option value="">Kies…</option><?php foreach ($toe_te_voegen as $z): ?><option value="<?= (int) $z->id ?>"><?= e("{$z->naam} {$z->voornaam}") ?></option><?php endforeach; ?></select></div>
    <button class="mini">Toevoegen</button></form><?php endif; ?>
</div>
<?php if ($beheer): ?>
<details class="kaart" <?= $f ? 'open' : '' ?>><summary><strong>Ploeg bewerken</strong></summary>
  <form method="post" action="<?= esc_url($_hier) ?>"><?= nonce_veld() ?><input type="hidden" name="actie" value="bewaar">
    <?php include __DIR__ . '/_ploeg_velden.php'; ?><p><button class="vol">Bewaren</button></p></form>
  <form method="post" action="<?= esc_url($_hier) ?>" onsubmit="return confirm(<?= esc_attr(wp_json_encode("Ploeg {$p->naam} verwijderen? De indeling van de zwemmers gaat verloren. Archiveren (vinkje Actief uit) bewaart ze.")) ?>)"><?= nonce_veld() ?>
    <button name="actie" value="verwijder_ploeg" class="mini secundair">Ploeg verwijderen</button></form>
</details>
<?php endif; ?>
