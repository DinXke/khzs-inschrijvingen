<?php namespace KHZS; defined('ABSPATH') || exit; ?>
<div class="rij tussen"><h1><?= e($w->naam) ?></h1>
  <div class="rij"><a class="knop secundair" href="<?= esc_url(app_url("wedstrijd/{$w->id}/nakijken/")) ?>">Inschrijvingen nakijken</a></div></div>
<div class="klein"><?= e($w->zwembad) ?>, <?= e($w->stad) ?> · <?= e(BAAN[$w->baan]) ?> · kwalificatie <?= e(datum($w->kwalificatie_van, 'j/m/Y')) ?> – <?= e(datum($w->kwalificatie_tot, 'j/m/Y')) ?></div>
<div class="stappen">
  <div class="stap">Zwemmers tot<b><?= e(datum(deadline_zwemmer($w), 'D j M')) ?></b></div>
  <div class="stap">Trainers tot<b><?= e(datum(deadline_trainer($w), 'D j M')) ?></b></div>
  <div class="stap">Organisator<b><?= e(datum($w->deadline, 'D j M Y')) ?></b></div>
</div>
<?php include __DIR__ . '/_info_bijlagen.php'; ?>
<div class="grid twee">
  <form method="post" class="kaart" action="<?= esc_url(app_url("wedstrijd/{$w->id}/instellingen/")) ?>"><?= nonce_veld() ?>
    <h2 style="margin-top:0">Instellingen</h2>
    <?= veld_tekst('naam', 'Naam', $w->naam, $fouten['naam'] ?? null, ['maxlength' => 200, 'required' => true]) ?>
    <?= veld_tekst('deadline', 'Laatste inschrijfdatum organisator', $w->deadline, $fouten['deadline'] ?? null, ['type' => 'date', 'required' => true]) ?>
    <?= veld_tekst('dagen_secretaris', 'Dagen secretaris', $w->dagen_secretaris, $fouten['dagen_secretaris'] ?? null, ['type' => 'number', 'min' => 0, 'required' => true],
      'Aantal dagen vóór de deadline van de organisator dat alles bij het sportsecretariaat moet zijn.') ?>
    <?= veld_select('status', 'Status', W_STATUS, $w->status, $fouten['status'] ?? null) ?>
    <?= veld_textarea('info', 'Info voor zwemmers', $w->info, null, 3, 'Bv. ‘Deze wedstrijd is enkel voor zwemmers van de A-groep.’ Zichtbaar voor iedereen.') ?>
    <div class="veld"><label>Selectiecriteria (limieten die gehaald moeten zijn)</label>
      <?php if ($groepen): ?><ul id="id_limietgroepen"><?php $i = 0; foreach ($groepen as $sleutel => [$label, $aan]): ?>
        <li><label for="id_limietgroepen_<?= $i ?>"><input type="checkbox" name="limietgroepen[]" value="<?= esc_attr($sleutel) ?>" id="id_limietgroepen_<?= $i++ ?>"<?= $aan ? ' checked' : '' ?>> <?= e($label) ?></label></li>
      <?php endforeach; ?></ul><?php else: ?><div class="help">Geen limieten in dit Lenex-bestand.</div><?php endif; ?>
    </div>
    <p class="help">Dagen trainer (nu <?= (int) $w->dagen_trainer ?>) stelt de trainer in via ‘Inschrijvingen nakijken’.</p>
    <p><button>Bewaren</button></p>
  </form>
  <div class="kaart">
    <h2 style="margin-top:0">Doorsturen</h2>
    <p class="klein">Enkel <b>goedgekeurde</b> inschrijvingen worden geëxporteerd.</p>
    <p class="rij"><a class="knop" href="<?= esc_url(app_url("wedstrijd/{$w->id}/export.lxf")) ?>">⬇ Lenex (.lxf)</a><a class="knop secundair" href="<?= esc_url(app_url("wedstrijd/{$w->id}/export.xlsx")) ?>">⬇ Excel</a><a class="knop secundair" href="<?= esc_url(app_url("wedstrijd/{$w->id}/overzicht/")) ?>">📄 Overzicht</a></p>
    <p class="klein">Na het doorsturen: zet de status op ‘Doorgestuurd’.</p>
    <h3>Nieuwe versie van de uitnodiging?</h3>
    <p><a class="knop secundair" href="<?= esc_url(app_url("wedstrijd/{$w->id}/opnieuw/")) ?>">Lenex opnieuw uploaden</a></p>
    <form method="post" action="<?= esc_url(app_url("wedstrijd/{$w->id}/verwijderen/")) ?>" onsubmit="return confirm('Wedstrijd en alle inschrijvingen verwijderen?')"><?= nonce_veld() ?>
      <button class="gevaar mini">Wedstrijd verwijderen</button></form>
  </div>
</div>
<h2>Programma</h2>
<?php foreach ($sessies as $s): ?>
<div class="kaart"><strong>Sessie <?= (int) $s->nummer ?> · <?= e(datum($s->datum, 'l j F')) ?><?= $s->starttijd ? ' · ' . e(substr($s->starttijd, 0, 5)) : '' ?><?php if ($s->opwarmen): ?> <span class="klein">(opwarmen <?= e(substr($s->opwarmen, 0, 5)) ?>)</span><?php endif; ?></strong>
  <div class="tabelwrap"><table>
  <?php foreach ($s->nummers as $n): ?><tr<?= $n->inschrijfbaar ? '' : ' class="klein"' ?>>
    <td>#<?= (int) $n->nummer ?></td><td><?= e(GESLACHT_NUMMER[$n->geslacht] ?? $n->geslacht) ?></td><td><?= e(omschrijving($n)) ?></td>
    <td><?= e(implode(', ', array_map(__NAMESPACE__ . '\\groep_tekst', $n->groepen))) ?> j</td>
    <td><?= $n->ronde === 'FIN' ? 'finale' : ($n->ronde === 'PRE' ? 'reeksen' : 'tijdfinale') ?></td>
    <td><?= $n->kost_cent ? e(euro($n->kost_cent)) : '' ?></td>
  </tr><?php endforeach; ?>
  </table></div>
</div>
<?php endforeach; ?>
