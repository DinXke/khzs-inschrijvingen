<?php namespace KHZS; defined('ABSPATH') || exit;
$o = "wedstrijd/{$w->id}/overzicht/";
$knop = fn($weerg, $filt, $label, $aan) => '<a href="' . esc_url(app_url($o, ['weergave' => $weerg, 'filter' => $filt])) . '" class="' . ($aan ? 'aan' : '') . '">' . e($label) . '</a>';
$baan_kort = fn($b) => $b === 'LCM' ? '50m' : ($b ? '25m' : '');
?><!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Overzicht <?= e($w->naam) ?> · <?= e(get_option('khzs_app_kort', 'HZS')) ?></title>
<link rel="icon" href="<?= esc_url(asset('img/favicon-32.png')) ?>">
<style>
  @page { size: A4; margin: 12mm 12mm 14mm; }
  :root { --petrol: #0e6b73; --petrol-donker: #0a4f55; --amber: #e8962e; --inkt: #14232c; --zacht: #5d6d77; --lijn: #e0e7ea; --bg: #f4f6f7;
    --ok: #157a46; --ok-bg: #dff4e8; --fout: #b42318; --fout-bg: #fde8e6; --wacht: #8a5a00; --wacht-bg: #fff1cc; }
  * { box-sizing: border-box; }
  body { margin: 0; background: var(--bg); color: var(--inkt); font: 10.5pt/1.45 -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
    -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  .blad { max-width: 210mm; margin: 0 auto; padding: 14px; }
  .balk { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 12px; }
  .balk a, .balk button { font: inherit; font-weight: 600; padding: 7px 12px; border-radius: 999px; border: 1px solid var(--lijn);
    background: #fff; color: var(--petrol); text-decoration: none; cursor: pointer; }
  .balk a.aan { background: var(--petrol); color: #fff; border-color: var(--petrol); }
  .balk .druk { background: var(--amber); border-color: var(--amber); color: #1c1200; margin-left: auto; }
  header { position: relative; display: flex; gap: 14px; align-items: center; padding: 14px 18px 18px; border-radius: 14px; color: #fff;
    background: linear-gradient(135deg, var(--petrol), var(--petrol-donker)); overflow: hidden; }
  header::after { content: ""; position: absolute; left: 0; right: 0; bottom: 0; height: 5px;
    background: repeating-linear-gradient(90deg, var(--amber) 0 22px, #fff 22px 30px); }
  header .logo { background: #fff; border-radius: 10px; padding: 4px 8px; display: flex; }
  header .logo img { height: 40px; display: block; }
  header h1 { margin: 0; font-size: 17pt; font-style: italic; font-weight: 800; line-height: 1.15; }
  header .sub { opacity: .9; font-size: 9.5pt; }
  .feiten { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin: 10px 0; }
  .feit { background: #fff; border: 1px solid var(--lijn); border-top: 3px solid var(--amber); border-radius: 10px; padding: 6px 10px; font-size: 8.5pt; color: var(--zacht); }
  .feit b { display: block; font-size: 11pt; color: var(--petrol); }
  .info { background: #fff6e8; border: 1px solid #f6d7a8; border-radius: 10px; padding: 7px 12px; margin-bottom: 10px; }
  .kaart { background: #fff; border: 1px solid var(--lijn); border-radius: 12px; margin: 0 0 10px; break-inside: avoid; overflow: hidden; }
  .kaart .kop { display: flex; align-items: baseline; gap: 10px; padding: 8px 12px; background: #eef5f5; border-bottom: 1px solid var(--lijn); }
  .kaart .kop .naam { font-weight: 800; font-size: 11.5pt; }
  .kaart .kop .meta { color: var(--zacht); font-size: 9pt; }
  .kaart .kop .rechts { margin-left: auto; font-size: 9pt; color: var(--zacht); }
  table { width: 100%; border-collapse: collapse; font-size: 9.8pt; }
  th { text-align: left; font-size: 8pt; color: var(--zacht); font-weight: 600; padding: 4px 10px; }
  td { padding: 5px 10px; border-top: 1px solid var(--lijn); vertical-align: middle; }
  td.nr { width: 42px; color: var(--petrol); font-weight: 800; }
  td.tijd { font-variant-numeric: tabular-nums; font-weight: 700; white-space: nowrap; }
  td.vak { width: 26px; } .vakje { display: inline-block; width: 14px; height: 14px; border: 1.6px solid var(--inkt); border-radius: 3px; }
  td.notitie { width: 30%; } .lijn { border-bottom: 1px dotted #9aa8b0; height: 14px; }
  .badge { display: inline-block; padding: 1px 7px; border-radius: 999px; font-size: 8pt; font-weight: 700; white-space: nowrap; }
  .goedgekeurd, .doet_mee { background: var(--ok-bg); color: var(--ok); }
  .ingediend, .interesse { background: var(--wacht-bg); color: var(--wacht); }
  .afgekeurd, .geweigerd { background: var(--fout-bg); color: var(--fout); }
  .doet_niet_mee { background: var(--lijn); color: var(--zacht); }
  h2 { font-style: italic; color: var(--petrol); font-size: 12.5pt; margin: 16px 0 8px; padding-left: 9px; border-left: 5px solid var(--amber); }
  .voet { display: flex; justify-content: space-between; color: var(--zacht); font-size: 8pt; margin-top: 12px; }
  .leeg { color: var(--zacht); padding: 18px; text-align: center; }
  @media (max-width: 640px) { .feiten { grid-template-columns: 1fr 1fr; } td.notitie { display: none; } }
  @media print {
    body { background: #fff; } .blad { padding: 0; max-width: none; } .balk { display: none; }
    .kaart { box-shadow: none; }
  }
</style>
</head>
<body>
<div class="blad">
  <nav class="balk">
    <a href="<?= esc_url(app_url("wedstrijd/{$w->id}/nakijken/")) ?>">← Terug</a>
    <?= $knop('zwemmer', $filter, 'Per zwemmer', $weergave === 'zwemmer') ?>
    <?= $knop('nummer', $filter, 'Per nummer', $weergave === 'nummer') ?>
    <?= $knop($weergave, 'actief', 'Zonder afgekeurde', $filter === 'actief') ?>
    <?= $knop($weergave, 'goedgekeurd', 'Enkel goedgekeurd', $filter === 'goedgekeurd') ?>
    <?= $knop($weergave, 'alle', 'Alle', $filter === 'alle') ?>
    <button class="druk" onclick="window.print()">🖨 Afdrukken / PDF</button>
  </nav>

  <header>
    <span class="logo"><img src="<?= esc_url(asset('img/logo.png')) ?>" alt="<?= esc_attr(get_option('khzs_app_kort', 'HZS')) ?>"></span>
    <div><h1><?= e($w->naam) ?></h1>
      <div class="sub"><?= e($w->zwembad) ?>, <?= e($w->stad) ?> · <?= e(BAAN[$w->baan]) ?> · <?= e(datum(startdatum($w), 'l j F Y')) ?></div></div>
  </header>

  <div class="feiten">
    <div class="feit">Zwemmers<b><?= count($zwemmers) ?></b></div>
    <div class="feit">Starts<b><?= (int) $aantal ?></b></div>
    <div class="feit">Deadline organisator<b><?= e(datum($w->deadline, 'D j M')) ?></b></div>
    <div class="feit">Inschrijfgeld<b><?= e(euro($totaal)) ?></b></div>
  </div>
  <?php if ($w->info): ?><div class="info">ℹ️ <?= nl2br(e($w->info)) ?></div><?php endif; ?>
  <?php if ($bijlagen): ?><div class="info">📎 Bijlagen: <?= e(implode(', ', array_column($bijlagen, 'naam'))) ?></div><?php endif; ?>

  <?php if ($weergave === 'nummer'): ?>
    <?php foreach ($nummers as [$n, $lijst]): ?>
    <div class="kaart">
      <div class="kop"><span class="naam">#<?= (int) $n->nummer ?> <?= e(omschrijving($n)) ?></span>
        <span class="meta"><?= e(GESLACHT_NUMMER[$n->geslacht] ?? $n->geslacht) ?> · sessie <?= (int) $n->sessie->nummer ?><?= $n->sessie->starttijd ? ' (' . e(substr($n->sessie->starttijd, 0, 5)) . ')' : '' ?></span>
        <span class="rechts"><?= count($lijst) ?> zwemmer<?= count($lijst) !== 1 ? 's' : '' ?></span></div>
      <table>
        <?php foreach ($lijst as $i): ?><tr><td class="vak"><span class="vakje"></span></td>
          <td><b><?= e("{$i->zwemmer->naam} {$i->zwemmer->voornaam}") ?></b> <span class="meta" style="color:var(--zacht)">(<?= e(substr($i->zwemmer->geboortedatum, 0, 4)) ?>)</span></td>
          <td class="tijd"><?= e(toon_tijd($i->inschrijftijd)) ?> <small><?= e($baan_kort($i->inschrijfbaan)) ?></small></td>
          <td><span class="badge <?= esc_attr($i->status) ?>"><?= e(I_STATUS[$i->status]) ?></span></td></tr><?php endforeach; ?>
      </table>
    </div>
    <?php endforeach; ?>
    <?php if (!$nummers): ?><div class="kaart leeg">Geen inschrijvingen.</div><?php endif; ?>
  <?php else: ?>
    <?php foreach ($zwemmers as $x): $dl = $x['deelname']; ?>
    <div class="kaart">
      <div class="kop"><span class="naam"><?= e("{$x['z']->naam} {$x['z']->voornaam}") ?></span>
        <span class="meta"><?= e(substr($x['z']->geboortedatum, 0, 4)) ?> · <?= (int) $x['jaren'] ?> jaar<?= $x['z']->licentie ? ' · lic. ' . e($x['z']->licentie) : '' ?></span>
        <?php if ($dl): ?><span class="badge <?= esc_attr($dl->geweigerd ? 'geweigerd' : $dl->keuze) ?>"><?= $dl->geweigerd ? 'geweigerd' : e(KEUZES[$dl->keuze]) ?></span>
          <?php if ($dl->gefinaliseerd): ?><span class="badge goedgekeurd">🔒 gefinaliseerd</span><?php endif; ?><?php if ($dl->ingegeven): ?><span class="badge doet_niet_mee">✓ ingegeven</span><?php endif; ?><?php endif; ?>
        <span class="rechts"><?= count($x['lijst']) ?> start<?= count($x['lijst']) !== 1 ? 's' : '' ?> · <?= e(euro($x['kost'])) ?></span></div>
      <table>
        <tr><th></th><th>Nr</th><th>Nummer</th><th>Sessie</th><th>Inschrijftijd</th><th>Status</th><th>Notitie</th></tr>
        <?php foreach ($x['lijst'] as $i): ?><tr>
          <td class="vak"><span class="vakje"></span></td>
          <td class="nr">#<?= (int) $i->nummer->nummer ?></td>
          <td><?= e(omschrijving($i->nummer)) ?></td>
          <td><?= (int) $i->nummer->sessie->nummer ?><?= $i->nummer->sessie->starttijd ? ' · ' . e(substr($i->nummer->sessie->starttijd, 0, 5)) : '' ?></td>
          <td class="tijd"><?= e(toon_tijd($i->inschrijftijd)) ?> <small><?= e($baan_kort($i->inschrijfbaan)) ?></small></td>
          <td><span class="badge <?= esc_attr($i->status) ?>"><?= e(I_STATUS[$i->status]) ?></span><?php if ($i->opmerking): ?><div style="font-size:8pt;color:var(--zacht)"><?= e($i->opmerking) ?></div><?php endif; ?></td>
          <td class="notitie"><div class="lijn"></div></td>
        </tr><?php endforeach; ?>
      </table>
    </div>
    <?php endforeach; ?>
    <?php if (!$zwemmers): ?><div class="kaart leeg">Geen inschrijvingen.</div><?php endif; ?>
  <?php endif; ?>

  <?php if ($zonder): ?>
  <h2>Interesse of ‘doe mee’, maar nog geen nummers</h2>
  <div class="kaart"><table><?php foreach ($zonder as $z): ?><tr><td class="vak"><span class="vakje"></span></td><td><b><?= e("{$z->naam} {$z->voornaam}") ?></b> (<?= e(substr($z->geboortedatum, 0, 4)) ?>)</td><td class="notitie"><div class="lijn"></div></td></tr><?php endforeach; ?></table></div>
  <?php endif; ?>

  <div class="voet"><span><b style="color:var(--petrol)"><?= e(club()['naam']) ?></b> · <?= e(wp_parse_url(home_url(), PHP_URL_HOST)) ?></span><span>afgedrukt <?= e(current_datetime()->format('j/m/Y H:i')) ?></span></div>
</div>
</body>
</html>
