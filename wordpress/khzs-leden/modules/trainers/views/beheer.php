<?php namespace KHZS; defined('ABSPATH') || exit; include __DIR__ . '/_stijl.php'; $url = app_url('trainers/beheer/'); $vandaag = vandaag()->format('Y-m-d'); ?>
<div class="rij tussen"><h1>Trainingen beheren</h1><a class="knop secundair" href="<?= esc_url(app_url('trainers/planning/')) ?>">📅 Planning</a></div>
<?php if ($seizoenen && !$nu_seizoen): ?>
<div class="kaart" style="border-left:4px solid var(--wacht)"><strong>Buiten het seizoen.</strong>
  <?= $laatste->tot < $vandaag ? 'Het laatste seizoen eindigde op ' . e(datum($laatste->tot, 'j/m/Y')) . '. Voeg het volgende seizoen toe, anders staan er geen trainingen in de planning.' : 'Er zijn nu geen trainingen tot het volgende seizoen begint.' ?></div>
<?php endif; ?>

<h2>Seizoen</h2>
<div class="kaart">
  <?php foreach ($seizoenen as $s): $_nu = $s->van <= $vandaag && $s->tot >= $vandaag; ?>
  <div class="tr-rij"<?= $s->tot < $vandaag ? ' style="opacity:.55"' : '' ?>><div class="tr-info"><strong><?= e($s->naam) ?><?= $_nu ? ' <span class="badge goedgekeurd">nu</span>' : '' ?></strong>
    <span class="klein"><?= e(datum($s->van, 'j/m/Y')) ?> – <?= e(datum($s->tot, 'j/m/Y')) ?></span></div>
    <form method="post" action="<?= esc_url($url) ?>" class="tr-status" onsubmit="return confirm('Seizoen verwijderen? De trainingen in deze periode tellen dan als buiten het seizoen (tenzij er geen seizoenen meer zijn).')"><?= nonce_veld() ?><input type="hidden" name="wat" value="seizoen_weg"><input type="hidden" name="id" value="<?= (int) $s->id ?>"><button class="mini secundair" aria-label="Seizoen verwijderen">✕</button></form></div>
  <?php endforeach; ?>
  <?php if (!$seizoenen): ?><p class="klein">Nog geen seizoen: de trainingen lopen het hele jaar door.</p><?php endif; ?>
  <form method="post" action="<?= esc_url($url) ?>" class="rij" style="align-items:end;margin-top:.6rem"><?= nonce_veld() ?><input type="hidden" name="wat" value="seizoen">
    <div style="min-width:150px;flex:1"><label for="sz-n">Naam</label><input id="sz-n" type="text" name="naam" maxlength="60" placeholder="bv. Seizoen 2026–2027"></div>
    <div><label for="sz-van">Begin</label><input id="sz-van" type="date" name="van" required></div>
    <div><label for="sz-tot">Einde</label><input id="sz-tot" type="date" name="tot" required></div>
    <button class="secundair">Seizoen toevoegen</button></form>
  <p class="help">Buiten een seizoen zijn er geen trainingen: ze staan niet in de planning, tellen niet voor de uren en de trainers krijgen geen vraag ‘Was je aanwezig?’. Trainingen van vóór het eerste seizoen blijven zoals ze waren.</p>
</div>

<h2>Vaste trainingen</h2>
<div class="kaart">
  <?php foreach ($momenten as $m): $voorbij = $m->tot && $m->tot < $vandaag; $_pl = tr_ploegnamen($moment_ploegen[(int) $m->id] ?? []); ?>
  <a class="tr-rij" href="<?= esc_url(app_url("trainers/moment/{$m->id}/")) ?>" style="text-decoration:none;color:inherit<?= $voorbij ? ';opacity:.5' : '' ?>">
    <div class="tr-info"><strong><?= e($m->naam) ?></strong>
      <span class="klein"><?= e(ucfirst(TR_DAGEN[$m->weekdag])) ?> <?= e(tr_tijd($m)) ?><?= $m->locatie ? ' · ' . e($m->locatie) : '' ?> · vanaf <?= e(datum($m->van, 'j/m/Y')) ?><?= $m->tot ? ' tot ' . e(datum($m->tot, 'j/m/Y')) : '' ?></span>
      <?php if ($_pl): ?><span class="klein">🏊 <?= e($_pl) ?></span><?php endif; ?></div>
    <div class="tr-status klein"><?php $tr = $vast[(int) $m->id] ?? []; echo $tr ? '👥 ' . e(implode(', ', array_map(__NAMESPACE__ . '\\tr_voornaam', $tr))) : '<span class="badge afgekeurd">nog geen trainer</span>'; ?> ›</div>
  </a>
  <?php endforeach; ?>
  <?php if (!$momenten): ?><p class="klein">Nog geen trainingen. Voeg hieronder de vaste trainingen van de club toe.</p><?php endif; ?>
</div>
<details class="kaart"<?= ($f || !$momenten) ? ' open' : '' ?>><summary><strong>+ Training toevoegen</strong></summary>
  <form method="post" action="<?= esc_url($url) ?>"><?= nonce_veld() ?><input type="hidden" name="wat" value="moment">
    <?php include __DIR__ . '/_moment_velden.php'; ?><p><button>Toevoegen</button></p></form>
</details>

<h2>Verlof en afgelast</h2>
<div class="kaart">
  <?php foreach ($afgelast as $a): ?>
  <div class="tr-rij"<?= $a->tot < $vandaag ? ' style="opacity:.55"' : '' ?>><div class="tr-info"><strong><?= e(tr_afgelast_voor($a)) ?></strong>
    <span class="klein"><?= e(datum($a->van, 'j/m/Y')) ?><?= $a->tot !== $a->van ? ' – ' . e(datum($a->tot, 'j/m/Y')) : '' ?> · <span class="badge concept"><?= e(TR_AFGELAST_SOORT[$a->soort] ?? 'afgelast') ?></span><?= $a->reden ? ' ' . e($a->reden) : '' ?></span></div>
    <form method="post" action="<?= esc_url($url) ?>" class="tr-status" onsubmit="return confirm('Verwijderen? De trainingen gaan dan toch door; de trainers krijgen een melding.')"><?= nonce_veld() ?><input type="hidden" name="wat" value="afgelast_weg"><input type="hidden" name="id" value="<?= (int) $a->id ?>"><button class="mini secundair" aria-label="Verwijderen">✕</button></form></div>
  <?php endforeach; ?>
  <?php if (!$afgelast): ?><p class="klein">Geen verlof of afgelaste trainingen<?= $alles ? '' : ' (vanaf vorige maand)' ?>.</p><?php endif; ?>
  <?php if (!$alles): ?><p class="klein"><a href="<?= esc_url(app_url('trainers/beheer/', ['alles' => 1])) ?>">Ook oudere tonen</a></p><?php endif; ?>
  <form method="post" action="<?= esc_url($url) ?>" class="rij" style="align-items:end;margin-top:.6rem"><?= nonce_veld() ?><input type="hidden" name="wat" value="afgelast">
    <div style="min-width:150px;flex:1"><label for="af-voor">Voor</label><select id="af-voor" name="voor"><option value="alle">Alle trainingen</option>
      <?php if ($ploegen): ?><optgroup label="Ploeg (al haar trainingen)"><?php foreach ($ploegen as $p): if (!(int) $p->actief) { continue; } ?><option value="p<?= (int) $p->id ?>"><?= e($p->naam) ?></option><?php endforeach; ?></optgroup><?php endif; ?>
      <?php if ($momenten): ?><optgroup label="Eén training"><?php foreach ($momenten as $m): if ($m->tot && $m->tot < $vandaag) { continue; } ?><option value="m<?= (int) $m->id ?>"><?= e($m->naam . ' (' . TR_DAGEN[$m->weekdag] . ')') ?></option><?php endforeach; ?></optgroup><?php endif; ?></select></div>
    <div><label for="af-s">Soort</label><select id="af-s" name="soort"><?php foreach (TR_AFGELAST_SOORT as $k => $l): ?><option value="<?= e($k) ?>"><?= e($k === 'ander' ? 'andere reden' : ucfirst($l)) ?></option><?php endforeach; ?></select></div>
    <div><label for="af-van">Van</label><input id="af-van" type="date" name="van" required></div>
    <div><label for="af-tot">Tot en met</label><input id="af-tot" type="date" name="tot"></div>
    <div style="min-width:150px;flex:1"><label for="af-r">Reden (optioneel)</label><input id="af-r" type="text" name="reden" maxlength="200" placeholder="bv. Kerstvakantie"></div>
    <button class="secundair">Toevoegen</button></form>
  <p class="help">Afgelaste trainingen blijven zichtbaar in de planning maar tellen niet mee voor de uren en verloning, en de trainers krijgen er geen vraag ‘Was je aanwezig?’ voor. De trainers van de komende trainingen die wegvallen (of toch doorgaan) krijgen een melding.</p>
</div>
