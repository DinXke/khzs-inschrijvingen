<?php namespace KHZS; defined('ABSPATH') || exit; include __DIR__ . '/_stijl.php';
$basis = app_url('trainers/uren/');
$totaal = ['trainingen' => array_sum(array_column($overzicht, 'trainingen')), 'minuten' => array_sum(array_column($overzicht, 'minuten')),
    'bedrag_cent' => array_sum(array_column($overzicht, 'bedrag_cent'))]; ?>
<div class="rij tussen"><h1><?= $pm ? 'Verloning trainers' : 'Mijn uren' ?></h1>
  <div class="rij"><?php if ($pm): ?><a class="knop secundair" href="<?= esc_url(app_url('trainers/tarieven/')) ?>">Tarieven</a>
  <a class="knop" href="<?= esc_url(app_url('trainers/uren/export/', ['maand' => $maand])) ?>">⬇ Excel</a><?php else: ?><a class="knop secundair" href="<?= esc_url(app_url('trainers/')) ?>">Mijn trainingen</a><?php endif; ?></div></div>
<div class="kaart tr-week">
  <a class="knop mini secundair" href="<?= esc_url(app_url('trainers/uren/', ['maand' => $vorige])) ?>">← Vorige</a>
  <strong style="text-transform:capitalize"><?= e(tr_maandnaam($maand)) ?></strong>
  <a class="knop mini secundair" href="<?= esc_url(app_url('trainers/uren/', ['maand' => $volgende])) ?>">Volgende →</a>
</div>
<p class="klein">Enkel trainingen waarvoor de trainer bevestigde dat hij of zij <b>aanwezig</b> was, tellen mee. Niet bevestigde trainingen tellen pas mee na bevestiging<?= $maand >= vandaag()->format('Y-m') ? '; latere trainingen staan als ‘gepland’' : '' ?>.</p>

<?php if ($pm): ?>
<div class="stappen">
  <div class="stap">Trainingen<b><?= (int) $totaal['trainingen'] ?></b></div>
  <div class="stap">Uren<b><?= e(tr_uren($totaal['minuten'])) ?></b></div>
  <div class="stap">Totaal<b><?= e(euro($totaal['bedrag_cent'])) ?></b></div>
</div>
<div class="kaart tabelwrap"><table>
  <tr><th>Trainer</th><th>Trainingen</th><th>Uren</th><th>Bedrag</th><th>Niet bevestigd</th><th>Status</th><th></th></tr>
  <?php foreach ($overzicht as $uid => $r): $ub = $uitbetaald[$uid] ?? null; $anders = $ub && ((int) $ub->bedrag_cent !== $r['bedrag_cent'] || (int) $ub->minuten !== $r['minuten']); ?>
  <tr>
    <td><a href="<?= esc_url(app_url('trainers/uren/', ['maand' => $maand, 'trainer' => $uid])) ?>"><?= e(tr_naam($r['user'])) ?></a>
      <?php if ($r['gepland']): ?><div class="klein"><?= (int) $r['gepland'] ?> nog gepland</div><?php endif; ?></td>
    <td><?= (int) $r['trainingen'] ?></td>
    <td><?= e(tr_uren($r['minuten'])) ?></td>
    <td><b><?= e(euro($r['bedrag_cent'])) ?></b><?php if ($r['zonder_tarief']): ?><div><a class="badge afgekeurd" href="<?= esc_url(app_url('trainers/tarieven/', ['trainer' => $uid])) ?>"><?= (int) $r['zonder_tarief'] ?> zonder tarief</a></div><?php endif; ?></td>
    <td><?php if ($r['open']): ?><a class="badge wacht" href="<?= esc_url(app_url('trainers/overzicht/', ['maand' => $maand, 'trainer' => $uid])) ?>"><?= (int) $r['open'] ?> (<?= e(tr_uren($r['open_minuten'])) ?>)</a><?php endif; ?></td>
    <td><?php if ($ub): ?><span class="badge goedgekeurd">✓ uitbetaald <?= e(datum($ub->op, 'j/m')) ?></span>
      <?php if ($anders): ?><div class="badge afgekeurd" title="Uitbetaald: <?= esc_attr(euro($ub->bedrag_cent) . ' voor ' . tr_uren((int) $ub->minuten)) ?>">⚠ gewijzigd na uitbetaling (was <?= e(euro($ub->bedrag_cent)) ?>)</div><?php endif; ?>
      <?php else: ?><span class="badge ingediend">nog niet uitbetaald</span><?php endif; ?></td>
    <td><form method="post" action="<?= esc_url(app_url('trainers/uren/', ['maand' => $maand])) ?>"><?= nonce_veld() ?><input type="hidden" name="user" value="<?= (int) $uid ?>">
      <?php if ($ub && !$anders): ?><button name="actie" value="niet_uitbetaald" class="mini secundair">Ongedaan</button>
      <?php else: ?><button name="actie" value="uitbetaald" class="mini ok"><?= $anders ? 'Opnieuw markeren' : '✓ Uitbetaald' ?></button><?php endif; ?></form></td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$overzicht): ?><tr><td colspan="7" class="klein">Geen trainingen in deze maand.</td></tr><?php endif; ?>
</table></div>
<?php endif; ?>

<?php if ($detail !== null && isset($overzicht[$detail])): $r = $overzicht[$detail]; ?>
<h2><?= $pm ? e(tr_naam($r['user'])) : 'Overzicht' ?></h2>
<?php if (!$pm): ?><div class="stappen">
  <div class="stap">Trainingen<b><?= (int) $r['trainingen'] ?></b></div>
  <div class="stap">Uren<b><?= e(tr_uren($r['minuten'])) ?></b></div>
  <div class="stap">Vergoeding<b><?= e(euro($r['bedrag_cent'])) ?></b></div>
  <?php if (isset($uitbetaald[$detail])): ?><div class="stap">Uitbetaald<b><?= e(datum($uitbetaald[$detail]->op, 'j/m')) ?></b></div><?php endif; ?>
</div><?php endif; ?>
<div class="kaart tabelwrap"><table>
  <tr><th>Datum</th><th>Training</th><th>Uren</th><th>Tarief</th><th>Bedrag</th></tr>
  <?php foreach ($r['regels'] as $g): ?><tr>
    <td><?= e(datum($g['datum'], 'D j/m')) ?></td>
    <td><?= e($g['moment']->naam) ?> <span class="klein"><?= e(tr_tijd($g['moment'])) ?><?= $g['soort'] === 'vervanger' ? ' · vervangt ' . e(tr_voornaam($g['vervangt'])) : ($g['soort'] === 'extra' ? ' · extra' : '') ?></span></td>
    <td><?= e(tr_uren($g['minuten'])) ?></td>
    <td class="klein"><?= $g['tarief'] ? e(euro($g['tarief']->bedrag_cent) . ' ' . TR_TARIEF[$g['tarief']->soort]) : '<span class="badge afgekeurd">geen tarief</span>' ?></td>
    <td><?= e(euro($g['bedrag_cent'])) ?></td>
  </tr><?php endforeach; ?>
  <?php foreach ($r['andere'] as $g): ?><tr class="klein" style="opacity:.75">
    <td><?= e(datum($g['datum'], 'D j/m')) ?></td>
    <td><?= e($g['moment']->naam) ?> <span class="klein"><?= e(tr_tijd($g['moment'])) ?></span></td>
    <td><s><?= e(tr_uren($g['minuten'])) ?></s></td>
    <td colspan="2"><span class="badge <?= $g['status'] === 'open' ? 'wacht' : 'afgekeurd' ?>"><?= e(TR_STATUS[$g['status']]) ?></span> telt niet mee</td>
  </tr><?php endforeach; ?>
  <?php if (!$r['regels'] && !$r['andere']): ?><tr><td colspan="5" class="klein">Nog geen gegeven trainingen deze maand.</td></tr><?php endif; ?>
</table></div>
<?php if (!$pm && $r['open']): ?><p class="help"><?= (int) $r['open'] ?> training(en) nog niet bevestigd. <a href="<?= esc_url(app_url('trainers/')) ?>">Bevestig ze bij Mijn trainingen</a>, dan tellen ze mee.</p><?php endif; ?>
<?php if ($pm && tr_mag_beheren()): ?><p><a class="knop mini secundair" href="<?= esc_url(app_url('trainers/overzicht/', ['maand' => $maand, 'trainer' => $detail])) ?>">Aanwezigheid en schema aanpassen ›</a></p><?php endif; ?>
<?php if (!$pm && $r['zonder_tarief']): ?><p class="help">Voor sommige trainingen is nog geen tarief ingesteld; de penningmeester vult dit aan.</p><?php endif; ?>
<?php endif; ?>
