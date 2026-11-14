<?php namespace KHZS; defined('ABSPATH') || exit;
$url = app_url("zwemmers/{$z->id}/"); ?>
<h1><?= e("{$z->voornaam} {$z->naam}") ?></h1>
<div class="grid twee">
  <div>
    <h2>Beste tijden</h2>
    <div class="kaart">
      <?php if ($tijden): ?><div class="tabelwrap"><table>
        <tr><th>Nummer</th><th>Tijd</th><th>Datum</th><th></th></tr>
        <?php foreach ($tijden as $t): ?><tr><td><?= (int) $t->afstand ?> m <?= e(mb_strtolower(SLAG[$t->slag] ?? $t->slag)) ?> <span class="klein"><?= e(BAAN[$t->baan]) ?></span></td>
          <td><b><?= e(toon_tijd($t->tijd)) ?></b></td><td class="klein"><?= e(datum($t->datum, 'j/m/y')) ?></td>
          <td><?php if ($is_staf): ?><form method="post" action="<?= esc_url(app_url("tijd/{$t->id}/verwijderen/")) ?>"><?= nonce_veld() ?><button class="mini secundair" title="Verwijderen">✕</button></form><?php endif; ?></td></tr><?php endforeach; ?>
      </table></div><?php else: ?><p class="klein">Nog geen tijden. Je beste tijden worden door de club bijgehouden op basis van de wedstrijduitslagen.</p><?php endif; ?>
      <?php if (swimrankings_url($z)): ?><p><a class="knop secundair mini" href="<?= esc_url(swimrankings_url($z)) ?>" target="_blank" rel="noopener">Bekijk op Swimrankings ↗</a></p>
      <?php else: ?><p class="help">Vul je Swimrankings-ID in bij je gegevens voor een directe link naar je tijden.</p><?php endif; ?>
    </div>
    <?php if ($is_staf): ?><details class="kaart"<?= $tf ? ' open' : '' ?>>
      <summary><strong>+ Tijd toevoegen (staf)</strong></summary>
      <form method="post" action="<?= esc_url($url) ?>"><?= nonce_veld() ?>
        <?= veld_select('t-slag', 'Slag', SLAG, $tijd['slag'], $tf['slag'] ?? null) ?>
        <?= veld_select('t-afstand', 'Afstand', array_combine(AFSTANDEN, array_map(fn($d) => "$d m", AFSTANDEN)), $tijd['afstand'], $tf['afstand'] ?? null) ?>
        <?= veld_select('t-baan', 'Baan', BAAN, $tijd['baan'], $tf['baan'] ?? null) ?>
        <?= veld_tekst('t-tijd', 'Tijd', $tijd['tijd'], $tf['tijd'] ?? null, ['inputmode' => 'decimal', 'placeholder' => 'm:ss.hh', 'required' => true], 'bv. 1:02.86 of 34.12') ?>
        <?= veld_tekst('t-datum', 'Datum', $tijd['datum'], $tf['datum'] ?? null, ['type' => 'date', 'required' => true]) ?>
        <?= veld_tekst('t-wedstrijd', 'Wedstrijd', $tijd['wedstrijd'], null, ['maxlength' => 120]) ?>
        <p><button class="vol">Toevoegen</button></p></form>
    </details><?php endif; ?>
  </div>
  <div>
    <?php if ($meldingen): ?><h2>Wijzigingen door de club</h2>
    <div class="kaart melding-lijst"><?php foreach ($meldingen as $m): ?>
      <div class="melding-item<?= $m->gelezen ? '' : ' nieuw' ?>"><div class="klein"><?= e($m->door_naam) ?> · <?= e($m->wedstrijd_naam) ?> · <?= e(datum($m->aangemaakt, 'j M H:i')) ?></div>
      <ul><?php foreach ($m->regels as $r): ?><li><?= e($r) ?></li><?php endforeach; ?></ul></div><?php endforeach; ?>
    </div><?php endif; ?>
    <h2>Inschrijvingen</h2>
    <div class="kaart">
      <?php foreach ($inschrijvingen as $i): ?><div class="rij tussen" style="padding:.35rem 0;border-bottom:1px solid var(--lijn)">
        <span><a href="<?= esc_url(app_url("wedstrijd/{$i->wid}/", ['zwemmer' => $z->id])) ?>"><?= e($i->wnaam) ?></a><br><span class="klein">#<?= (int) $i->n_nummer ?> <?= e(omschrijving((object) ['naam' => $i->n_naam, 'afstand' => $i->afstand, 'slag' => $i->slag, 'aflossing' => $i->aflossing])) ?> · <?= e(toon_tijd($i->inschrijftijd)) ?></span></span>
        <span class="badge <?= esc_attr($i->status) ?>"><?= e(I_STATUS[$i->status]) ?></span></div>
      <?php endforeach; ?>
      <?php if (!$inschrijvingen): ?><p class="klein">Nog geen inschrijvingen.</p><?php endif; ?>
    </div>
    <h2>Gegevens</h2>
    <form method="post" class="kaart" action="<?= esc_url($url) ?>"><?= nonce_veld() ?><?= zwemmer_velden('z-', $v, $f) ?><p><button>Bewaren</button></p></form>
  </div>
</div>
