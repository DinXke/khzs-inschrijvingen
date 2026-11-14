<?php namespace KHZS; defined('ABSPATH') || exit; ?>
<div class="rij tussen"><h1>Wedstrijden</h1><?php if ($is_secretaris): ?><a class="knop" href="<?= esc_url(app_url('wedstrijd/nieuw/')) ?>">+ Lenex uploaden</a><?php endif; ?></div>
<?php foreach ($lijst as $w):
  $k = $keuzes[$w->id] ?? [];
  $doel = $is_staf ? ($is_secretaris ? "wedstrijd/{$w->id}/instellingen/" : "wedstrijd/{$w->id}/nakijken/") : "wedstrijd/{$w->id}/"; ?>
<a class="kaart" href="<?= esc_url(app_url($doel)) ?>">
  <div class="rij tussen"><strong><?= e($w->naam) ?></strong><?php if ($is_staf): ?><span class="badge <?= esc_attr($w->status) ?>"><?= e(W_STATUS[$w->status]) ?></span><?php endif; ?></div>
  <div class="klein"><?= e($w->stad) ?> · <?= e(datum($w->_start, 'j M Y')) ?> · <?= e(BAAN[$w->baan]) ?></div>
  <?php if ($w->info): ?><div class="klein info-kort">ℹ️ <?= e(mb_strlen($w->info) > 110 ? mb_substr($w->info, 0, 109) . '…' : $w->info) ?></div><?php endif; ?>
  <?php if ($w->bijlagen_aantal): ?><div class="klein info-kort">📎 <?= (int) $w->bijlagen_aantal ?> bijlage<?= $w->bijlagen_aantal != 1 ? 'n' : '' ?></div><?php endif; ?>
  <div class="rij klein" style="margin-top:.4rem">
    <?php if ($is_staf): ?>
      <span>Zwemmers tot <b><?= e(datum(deadline_zwemmer($w), 'j/m')) ?></b></span>
      <span>Trainers tot <b><?= e(datum(deadline_trainer($w), 'j/m')) ?></b></span>
      <span>Organisator <b><?= e(datum($w->deadline, 'j/m')) ?></b></span>
      <span><?= (int) $w->aantal ?> inschrijvingen<?php if ($w->te_keuren): ?> · <span class="badge wacht"><?= (int) $w->te_keuren ?> na te kijken</span><?php endif; ?></span>
    <?php else: ?>
      <?php if ($w->status === W_CONCEPT): ?><span class="badge concept">Nog niet open</span><?php elseif ($w->status === W_OPEN): ?><span>Inschrijven tot <b><?= e(datum(deadline_zwemmer($w), 'l j F')) ?></b></span><?php else: ?><span class="badge gesloten">Inschrijvingen gesloten</span><?php endif; ?>
      <?php foreach ($k as $d): ?><span class="badge k-<?= esc_attr($d->geweigerd ? 'geweigerd' : $d->keuze) ?>"><?= count($k) > 1 ? e($d->voornaam) . ': ' : '' ?><?= $d->geweigerd ? 'geweigerd' : e(KEUZES[$d->keuze]) ?></span><?php endforeach; ?>
      <?php if (!$k): ?><span class="badge">Nog geen keuze</span><?php endif; ?>
      <?php if (!empty($eigen[$w->id])): ?><span class="badge ok"><?= (int) $eigen[$w->id] ?> ingeschreven</span><?php endif; ?>
      <?php if (!empty($nieuw[$w->id])): ?><span class="badge wacht">🔔 gewijzigd door de club</span><?php endif; ?>
    <?php endif; ?>
  </div>
</a>
<?php endforeach; ?>
<?php if (!$lijst): ?><div class="leeg"><?= $is_secretaris ? 'Nog geen wedstrijden. Upload een Lenex-bestand om te starten.' : 'Er staan momenteel geen wedstrijden open.' ?></div><?php endif; ?>
