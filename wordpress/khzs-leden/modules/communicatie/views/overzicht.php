<?php namespace KHZS; defined('ABSPATH') || exit;
include __DIR__ . '/_stijl.php';
$_uid = get_current_user_id();
/** Een rij in de lijst van kanalen. */
$_rij = function (object $k, bool $thread = false) {
    $laatste = $k->laatste && !$k->laatste->verwijderd
        ? com_naam((int) $k->laatste->user_id) . ': ' . ($k->laatste->tekst !== '' ? $k->laatste->tekst : '📎 ' . $k->laatste->bijlage_naam)
        : ($k->beschrijving ?: 'Nog geen berichten');
    $naam = $thread || !(int) $k->ouder_id ? $k->naam : com_volle_naam($k); ?>
  <a class="com-rij<?= $thread ? ' com-thread' : '' ?><?= $k->ongelezen && $k->volgt ? ' com-nieuw' : '' ?>" href="<?= esc_url(app_url("berichten/kanaal/{$k->id}/")) ?>">
    <span class="com-icoon" aria-hidden="true"><?= com_icoon($k) ?></span>
    <span class="com-rij-info"><b><?= e($naam) ?><?php if ((int) $k->gearchiveerd): ?> <span class="badge doorgestuurd">archief</span><?php endif; ?></b>
      <span class="klein"><?= e(mb_strimwidth(preg_replace('/\s+/', ' ', $laatste), 0, 90, '…')) ?></span></span>
    <span class="com-rij-rechts"><span class="klein"><?= e(com_wanneer($k->laatste_op)) ?></span>
      <?php if ($k->ongelezen): ?><span class="com-teller<?= $k->volgt ? '' : ' stil' ?>" title="Ongelezen"><?= $k->ongelezen > 99 ? '99+' : (int) $k->ongelezen ?></span><?php endif; ?></span>
  </a>
<?php }; ?>
<div class="rij tussen"><h1>Berichten</h1>
  <div class="rij">
    <a class="knop secundair mini" href="<?= esc_url(app_url('berichten/aankondigingen/')) ?>"><?= $mag_aankondigen ? '+ Aankondiging' : 'Aankondigingen' ?></a>
    <?php if ($bestuur): ?><a class="knop secundair mini" href="<?= esc_url(app_url('berichten/beheer/')) ?>">⚙ Kanalen beheren</a><?php endif; ?>
  </div>
</div>
<?php // Hoogstens drie bovenaan (dringend eerst), zodat de kanalen op de gsm niet te ver naar beneden schuiven.
foreach (array_slice($aankondigingen, 0, 3) as $_a) { include __DIR__ . '/_aankondiging.php'; } ?>
<?php if (count($aankondigingen) > 3): ?><p><a href="<?= esc_url(app_url('berichten/aankondigingen/')) ?>">+ <?= count($aankondigingen) - 3 ?> andere aankondiging<?= count($aankondigingen) - 3 === 1 ? '' : 'en' ?></a></p><?php endif; ?>
<?php foreach ($secties as $s): ?>
<h2 class="com-sectie"><?= e($s->sectie->naam) ?></h2>
<div class="kaart com-lijst">
  <?php foreach ($s->kanalen as $k):
    $_rij($k);
    foreach (array_slice($k->threads, 0, 4) as $t) { $_rij($t, true); }
    if (count($k->threads) > 4): ?><a class="com-meer" href="<?= esc_url(app_url("berichten/kanaal/{$k->id}/")) ?>">+ <?= count($k->threads) - 4 ?> andere threads</a><?php endif;
  endforeach; ?>
</div>
<?php endforeach; ?>
<?php if (!$secties): ?><div class="leeg">Je ziet nog geen kanalen. Zodra je zwemmer in een ploeg zit of meedoet aan een wedstrijd, verschijnen de kanalen hier.</div><?php endif; ?>
<p class="help">🔔 Bij <a href="<?= esc_url(app_url('meldingen/')) ?>">Meldingen</a> kies je voor welke berichten je een melding krijgt. Per kanaal kan je ook dempen.</p>
