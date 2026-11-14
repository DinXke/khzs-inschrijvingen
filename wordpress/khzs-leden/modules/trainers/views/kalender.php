<?php namespace KHZS; defined('ABSPATH') || exit; include __DIR__ . '/_stijl.php';
/**
 * Kalender: dag, week of maand. Verwacht $weergave, $dag, $van, $tot, $per_dag, $uid, $beheer, $wie, $ploeg, $ploegen,
 * $trainers, $vorige en $volgende (zie v_tr_kalender).
 */
$vandaag = vandaag()->format('Y-m-d');
$filter = array_filter(['wie' => $wie, 'ploeg' => $ploeg ?: null], fn($v) => $v !== null);
$url = fn(array $q) => app_url('trainers/kalender/', $q + ['weergave' => $weergave, 'datum' => $dag->format('Y-m-d')] + $filter);
$ploegnaam = array_column($ploegen, 'naam', 'id');
$merk = ['ja' => '✓', 'nee' => '✗', 'open' => '?'];
$titel = match ($weergave) {
    'dag' => datum($dag, 'l j F Y'),
    'week' => datum($van, 'j M') . ' – ' . datum($tot, 'j M Y'),
    default => tr_maandnaam($dag->format('Y-m')),
};

/** Eén training als blokje in de week- of maandweergave. */
$blok = function (object $b, bool $kort) use ($uid, $wie, $merk, $ploegnaam): string {
    $m = $b->moment;
    $af = tr_afgelopen($b) && !$b->afgelast;
    $ik = $wie === 'mij' || $wie === 'alle' ? $uid : (int) $wie;
    $klasse = ['tr-k-blok'];
    if ($b->afgelast) {
        $klasse[] = 'afgelast';
    } elseif (isset($b->afwezig[$ik])) {
        $klasse[] = 'afgemeld';
    } elseif (isset($b->aanwezig[$ik])) {
        $klasse[] = 'mijn';
        if ($af) {
            $klasse[] = 'st-' . tr_status($b, $ik);
        }
    }
    if ($b->open && !$b->afgelast) {
        $klasse[] = 'gezocht';
    }
    $h = '<a class="' . implode(' ', $klasse) . '" href="' . esc_url(tr_training_url($m, $b->datum)) . '">'
        . '<span class="tr-k-uur">' . e(substr($m->start, 0, 5)) . (!$kort ? '–' . e(substr($m->einde, 0, 5)) : '') . '</span> '
        . '<strong>' . e($m->naam) . '</strong>';
    if ($kort) {
        return $h . '</a>';
    }
    $pl = array_filter(array_map(fn($p) => $ploegnaam[$p] ?? '', $b->ploegen ?? []));
    if ($pl) {
        $h .= '<span class="tr-k-klein">' . e(implode(', ', $pl)) . '</span>';
    }
    if ($b->afgelast) {
        return $h . '<span class="tr-k-klein">Afgelast: ' . e($b->afgelast) . '</span></a>';
    }
    $namen = [];
    foreach ($b->aanwezig as $id => [$soort, $vervangt]) {
        $st = $af ? tr_status($b, $id) : '';
        $namen[] = e(tr_voornaam($id)) . ($soort === 'vervanger' && $vervangt ? ' <i>voor ' . e(tr_voornaam($vervangt)) . '</i>' : '')
            . ($soort === 'extra' ? ' <i>(extra)</i>' : '') . ($st ? '<span class="tr-merk ' . $st . '">' . $merk[$st] . '</span>' : '');
    }
    foreach ($b->afwezig as $id => $_) {
        $namen[] = '<s title="afgemeld">' . e(tr_voornaam($id)) . '</s>';
    }
    if ($namen) {
        $h .= '<span class="tr-k-klein">' . implode(', ', $namen) . '</span>';
    }
    if ($b->open) {
        $h .= '<span class="tr-k-gezocht">vervanger gezocht</span>';
    }
    if (tr_zw_kan($b) && ($zp = tr_zw_ploegen_van($b))) {
        $t = tr_zw_telling($b, $zp);
        $h .= '<span class="tr-k-klein">🏊 ' . ($t ? $t[0] . '/' . $t[1] . ' zwemmers' : 'zwemmers aanduiden') . '</span>';
    }
    return $h . '</a>';
};
// Velden van app_url() die in een GET-formulier mee moeten (zonder mooie permalinks staat het pad in de query).
parse_str((string) wp_parse_url(app_url('trainers/kalender/'), PHP_URL_QUERY), $vast);
?>
<div class="rij tussen"><h1>Kalender</h1>
  <div class="rij"><a class="knop secundair" href="<?= esc_url(app_url('trainers/')) ?>">Mijn trainingen</a>
  <?php if (tr_zw_ploegen()): ?><a class="knop secundair" href="<?= esc_url(app_url('trainers/zwemmers/')) ?>">🏊 Zwemmers</a><?php endif; ?>
  <?php if ($beheer): ?><a class="knop secundair" href="<?= esc_url(app_url('trainers/overzicht/')) ?>">Overzicht trainers</a><?php endif; ?></div></div>

<div class="kaart tr-k-balk">
  <div class="tr-k-weergave" role="group" aria-label="Weergave">
    <?php foreach (['dag' => 'Dag', 'week' => 'Week', 'maand' => 'Maand'] as $_w => $_n): ?>
    <a class="knop mini <?= $weergave === $_w ? '' : 'secundair' ?>" href="<?= esc_url($url(['weergave' => $_w])) ?>"<?= $weergave === $_w ? ' aria-current="page"' : '' ?>><?= $_n ?></a>
    <?php endforeach; ?>
  </div>
  <div class="tr-k-nav">
    <a class="knop mini secundair" href="<?= esc_url($url(['datum' => $vorige])) ?>" aria-label="Vorige">←</a>
    <a class="knop mini secundair" href="<?= esc_url($url(['datum' => $vandaag])) ?>">Vandaag</a>
    <a class="knop mini secundair" href="<?= esc_url($url(['datum' => $volgende])) ?>" aria-label="Volgende">→</a>
    <strong class="tr-k-titel"><?= e($titel) ?></strong>
  </div>
  <form method="get" action="<?= esc_url(app_url('trainers/kalender/')) ?>" class="tr-k-filter">
    <?php foreach ($vast + ['weergave' => $weergave, 'datum' => $dag->format('Y-m-d')] as $_k => $_v): ?><input type="hidden" name="<?= esc_attr($_k) ?>" value="<?= esc_attr($_v) ?>"><?php endforeach; ?>
    <select name="wie" aria-label="Wiens trainingen" onchange="this.form.submit()">
      <option value="mij" <?= $wie === 'mij' ? 'selected' : '' ?>>Mijn trainingen</option>
      <option value="alle" <?= $wie === 'alle' ? 'selected' : '' ?>>Alle trainingen</option>
      <?php if ($trainers): ?><optgroup label="Trainer"><?php foreach ($trainers as $_t): ?>
        <option value="<?= (int) $_t->ID ?>" <?= $wie === (string) $_t->ID ? 'selected' : '' ?>><?= e(tr_naam($_t)) ?></option><?php endforeach; ?></optgroup><?php endif; ?>
    </select>
    <?php if ($ploegen): ?><select name="ploeg" aria-label="Ploeg" onchange="this.form.submit()">
      <option value="">Alle ploegen</option>
      <?php foreach ($ploegen as $_p): ?><option value="<?= (int) $_p->id ?>" <?= $ploeg === (int) $_p->id ? 'selected' : '' ?>><?= e($_p->naam) ?></option><?php endforeach; ?>
    </select><?php endif; ?>
    <noscript><button class="mini">Toon</button></noscript>
  </form>
</div>

<?php if ($weergave === 'dag'): $d = $dag->format('Y-m-d'); ?>
  <?php if (!empty($per_dag[$d])): ?>
  <div class="kaart tr-k-dag"><?php foreach ($per_dag[$d] as $b) {
      $_pl = array_filter(array_map(fn($p) => $ploegnaam[$p] ?? '', $b->ploegen));
      include __DIR__ . '/_training.php';
      if ($_pl) { echo '<p class="klein tr-k-ploegen">Ploegen: ' . e(implode(', ', $_pl)) . '</p>'; }
  } ?></div>
  <?php else: ?><div class="leeg"><?= tr_in_seizoen($d) ? ($wie === 'mij' ? 'Je hebt geen training op deze dag.' : 'Geen trainingen op deze dag.') : 'Buiten het seizoen: geen trainingen.' ?></div><?php endif; ?>

<?php elseif ($weergave === 'week'): ?>
<div class="tr-k-week">
  <?php for ($i = 0; $i < 7; $i++): $_d = $van->modify("+$i days"); $d = $_d->format('Y-m-d'); $_lijst = $per_dag[$d] ?? []; ?>
  <section class="tr-k-kol<?= $d === $vandaag ? ' vandaag' : '' ?><?= $_lijst ? '' : ' leeg' ?>">
    <a class="tr-k-kop" href="<?= esc_url($url(['weergave' => 'dag', 'datum' => $d])) ?>"><b><?= e(datum($_d, 'D')) ?></b> <?= e(datum($_d, 'j M')) ?></a>
    <?php foreach ($_lijst as $b) { echo $blok($b, false); } ?>
    <?php if (!$_lijst): ?><span class="tr-k-niets">—</span><?php endif; ?>
  </section>
  <?php endfor; ?>
</div>
<?php if (!$per_dag): ?><div class="leeg"><?= $wie === 'mij' ? 'Je hebt geen trainingen deze week.' : 'Geen trainingen deze week.' ?></div><?php endif; ?>

<?php else: $_maand = $dag->format('m'); ?>
<div class="tr-k-maand">
  <?php foreach (['ma', 'di', 'wo', 'do', 'vr', 'za', 'zo'] as $_n): ?><div class="tr-k-wd"><?= $_n ?></div><?php endforeach; ?>
  <?php for ($_d = $van; $_d <= $tot; $_d = $_d->modify('+1 day')): $d = $_d->format('Y-m-d'); $_lijst = $per_dag[$d] ?? []; ?>
  <div class="tr-k-cel<?= $_d->format('m') !== $_maand ? ' ander' : '' ?><?= $d === $vandaag ? ' vandaag' : '' ?>">
    <a class="tr-k-nr" href="<?= esc_url($url(['weergave' => 'dag', 'datum' => $d])) ?>" aria-label="<?= esc_attr(datum($_d, 'l j F') . ': ' . count($_lijst) . ' training' . (count($_lijst) === 1 ? '' : 'en')) ?>"><?= (int) $_d->format('j') ?></a>
    <?php foreach ($_lijst as $b) { echo $blok($b, true); } ?>
  </div>
  <?php endfor; ?>
</div>
<?php endif; ?>

<p class="klein tr-k-legende"><span class="tr-k-blok mijn">ik geef training</span> <span class="tr-k-blok mijn st-open">niet bevestigd</span>
  <span class="tr-k-blok afgemeld">afgemeld</span> <span class="tr-k-blok gezocht">vervanger gezocht</span> <span class="tr-k-blok afgelast">afgelast</span>
  · <span class="tr-merk ja">✓</span> aanwezig <span class="tr-merk nee">✗</span> niet aanwezig <span class="tr-merk open">?</span> niet bevestigd</p>
