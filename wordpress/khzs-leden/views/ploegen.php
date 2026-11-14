<?php namespace KHZS; defined('ABSPATH') || exit; ?>
<h1>Ploegen</h1>
<?php if ($aanvragen): ?>
<div class="kaart melding-lijst">
  <h2 style="margin-top:0">Aanvragen (<?= count($aanvragen) ?>)</h2>
  <?php $_terug = app_url('ploegen/'); foreach ($aanvragen as $_l) { include __DIR__ . '/_ploegaanvraag.php'; } ?>
</div>
<?php endif; ?>
<?php foreach ($ploegen as $p): ?>
<a class="kaart" href="<?= esc_url(app_url("ploegen/{$p->id}/")) ?>"><div class="rij tussen"><strong><?= e($p->naam) ?><?php if (($p->soort ?? '') === 'zwemschool'): ?> <span class="badge">zwemschool</span><?php endif; ?><?php if (!(int) $p->actief): ?> <span class="badge doorgestuurd">gearchiveerd</span><?php endif; ?></strong>
  <span class="klein"><?= (int) $p->leden ?> zwemmer<?= (int) $p->leden !== 1 ? 's' : '' ?><?php if ($p->open): ?> · <span class="badge wacht"><?= (int) $p->open ?> wacht</span><?php endif; ?></span></div>
  <?php $_t = implode(', ', array_map(fn($u) => volle_naam($u) ?: $u->user_login, $p->trainers)); if ($p->beschrijving !== '' || $_t !== ''): ?>
  <div class="klein"><?= e(implode(' · ', array_filter([$p->beschrijving, $_t]))) ?></div><?php endif; ?></a>
<?php endforeach; ?>
<?php if (!$ploegen): ?><div class="leeg"><?= $beheer ? 'Nog geen ploegen. Maak hieronder de eerste aan.' : 'Je zwemmers zitten nog in geen enkele ploeg. Kies een ploeg bij de zwemmer.' ?></div><?php endif; ?>
<?php if ($beheer): ?>
<details class="kaart" <?= $f ? 'open' : '' ?>><summary><strong>+ Nieuwe ploeg</strong></summary>
  <form method="post" action="<?= esc_url(app_url('ploegen/')) ?>"><?= nonce_veld() ?>
    <?php include __DIR__ . '/_ploeg_velden.php'; ?>
    <p><button class="vol">Aanmaken</button></p></form>
</details>
<?php endif; ?>
