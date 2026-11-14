<?php namespace KHZS; defined('ABSPATH') || exit;
$_stap = fn($s) => esc_url(app_url('welkom/', ['stap' => $s]));
$_badge = fn($l) => '<span class="badge ' . ($l->status === PL_GOEDGEKEURD ? 'ok' : ($l->status === PL_OPEN ? 'wacht' : 'fout')) . '">' . e($l->ploegnaam) . ($l->status === PL_OPEN ? ' (wacht)' : '') . '</span> ';
$_ploegveld = fn($p) => $ploegen ? veld_select("{$p}ploeg", 'Ploeg', ['' => 'Weet ik nog niet'] + array_column($ploegen, 'naam', 'id'), $v['ploeg'] ?? '', null, 'De trainer of het bestuur keurt de ploeg goed.') : ''; ?>
<?php if ($stap !== 'klaar'): ?><p class="klein">Stap <?= $nr ?> van 3 · <a href="<?= $_stap($volgende) ?>">Overslaan</a></p><?php endif; ?>

<?php if ($stap === 'zelf'): ?>
<h1>Zwem je zelf?</h1>
<?php if ($zelf_ontbreekt): ?>
<p class="klein">Bijvoorbeeld bij de masters. Vul dan je gegevens aan; je naam staat er al.</p>
<form method="post" class="kaart" action="<?= $_stap('zelf') ?>"><?= nonce_veld() ?><?= zwemmer_velden('', $v, $f) ?><?= $_ploegveld('') ?><?= toestemming_veld($f) ?>
  <p><button class="vol">Ja, voeg mij toe als zwemmer</button></p></form>
<?php else: ?><div class="kaart"><p>Je staat al bij je zwemmers.</p></div><?php endif; ?>
<p><a class="knop secundair vol" href="<?= $_stap('kinderen') ?>"><?= $zelf_ontbreekt ? 'Nee, ik zwem niet zelf' : 'Volgende' ?></a></p>

<?php elseif ($stap === 'kinderen'): ?>
<h1>Heb je kinderen die zwemmen?</h1>
<?php if ($kinderen): ?><div class="kaart"><?php foreach ($kinderen as $z): ?><div class="rij tussen" style="padding:.3rem 0"><strong><?= e("{$z->voornaam} {$z->naam}") ?></strong>
  <span><?php foreach (ploegen_van_zwemmer((int) $z->id) as $_l) { echo $_badge($_l); } ?></span></div><?php endforeach; ?></div><?php endif; ?>
<details class="kaart" <?= !$kinderen || $f ? 'open' : '' ?>><summary><strong><?= $kinderen ? '+ Nog een kind toevoegen' : 'Ja, een kind toevoegen' ?></strong></summary>
  <form method="post" action="<?= $_stap('kinderen') ?>"><?= nonce_veld() ?><input type="hidden" name="actie" value="kind"><?= zwemmer_velden('', $v, $f) ?><?= $_ploegveld('') ?><?= toestemming_veld($f) ?>
    <p><button class="vol">Kind toevoegen</button></p></form></details>
<details class="kaart" <?= $koppel_fout ? 'open' : '' ?>><summary><strong>Mijn kind staat al bij de andere ouder</strong></summary>
  <p class="klein">Vraag de andere ouder om een koppelcode (bij de zwemmer → ‘Koppelcode maken’) en vul ze hier in.</p>
  <form method="post" action="<?= $_stap('kinderen') ?>"><?= nonce_veld() ?><input type="hidden" name="actie" value="koppel">
    <?= veld_tekst('code', 'Koppelcode', '', $koppel_fout, ['placeholder' => 'bv. K7QM-2PXA', 'autocomplete' => 'off', 'autocapitalize' => 'characters', 'required' => true]) ?>
    <p><button class="vol secundair">Koppelen</button></p></form></details>
<p><a class="knop vol <?= $kinderen ? '' : 'secundair' ?>" href="<?= $_stap('staf') ?>"><?= $kinderen ? 'Volgende' : 'Nee, volgende' ?></a></p>

<?php elseif ($stap === 'staf'): ?>
<h1>Help je mee bij de club?</h1>
<form method="post" class="kaart" action="<?= $_stap('staf') ?>"><?= nonce_veld() ?>
  <p class="klein">Ben je trainer, bestuurder of sportsecretaris? Duid het aan. Het bestuur keurt je aanvraag goed; je krijgt daarna een melding.</p>
  <div class="veld">
    <label><input type="checkbox" name="rollen[]" value="trainer"> Trainer</label>
    <label><input type="checkbox" name="rollen[]" value="bestuurder"> Bestuurder</label>
    <label><input type="checkbox" name="rollen[]" value="secretaris"> Sportsecretaris</label></div>
  <div class="veld"><label for="motivatie">Toelichting (optioneel)</label><input type="text" id="motivatie" name="motivatie" maxlength="300" placeholder="bv. trainer van de B-groep"></div>
  <p><button class="vol">Aanvragen</button></p>
</form>
<p><a class="knop secundair vol" href="<?= $_stap('klaar') ?>">Nee, volgende</a></p>

<?php else: ?>
<h1>Klaar!</h1>
<div class="kaart">
  <p><b>Je rollen:</b> <?php foreach ($eigen_rollen as $r): ?><span class="badge rol"><?= e(ROLLEN[$r]) ?></span> <?php endforeach; ?><?php if (!$eigen_rollen): ?><span class="klein">nog geen</span><?php endif; ?>
    <?php foreach ($open_rollen as $a): ?><span class="badge wacht"><?= e(ROLLEN[$a->rol]) ?> (wacht)</span> <?php endforeach; ?></p>
  <?php if ($zwemmers): ?><p><b>Je zwemmers:</b></p><?php foreach ($zwemmers as $z): ?><div class="rij tussen" style="padding:.2rem 0"><span><?= e("{$z->voornaam} {$z->naam}") ?></span>
    <span><?php foreach (ploegen_van_zwemmer((int) $z->id) as $_l) { echo $_badge($_l); } ?></span></div><?php endforeach; endif; ?>
  <p class="help">Dit kan je later altijd aanpassen: zwemmers en ploegen bij <i>Mijn zwemmers</i>, rollen bij <i>Mijn rollen</i>.</p>
</div>
<p><a class="knop vol" href="<?= esc_url(app_url('wedstrijden/')) ?>">Naar de wedstrijden</a></p>
<?php endif; ?>
