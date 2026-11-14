<?php namespace KHZS; defined('ABSPATH') || exit;
$_namen = fn(array $l) => e(implode(', ', array_map(fn($z) => "{$z->voornaam} {$z->naam}", $l))); ?>
<h1>Mijn gegevens</h1>
<div class="kaart">
  <h2 style="margin-top:0">Privacyverklaring</h2>
  <?php if ($akkoord): ?><p>Je aanvaardde versie <b><?= e($akkoord_versie) ?></b> op <?= e(datum($akkoord, 'j/m/Y H:i')) ?>.
    <?= $akkoord_versie !== $versie ? 'Er is een nieuwe versie (' . e($versie) . ').' : '' ?></p>
  <?php else: ?><p>Je hebt de privacyverklaring nog niet aanvaard.</p><?php endif; ?>
  <p><a class="knop secundair" href="<?= esc_url(app_url('privacy/')) ?>">Lees de privacyverklaring</a></p>
</div>
<div class="kaart">
  <h2 style="margin-top:0">Mijn gegevens downloaden</h2>
  <p class="klein">Alles wat de app over jou en je zwemmers bijhoudt, in één bestand (JSON): je account, rollen, meldingsvoorkeuren, zwemmers, ploegen, tijden, inschrijvingen en meldingen<?= module_actief('trainers') ? ', en als trainer je trainingen, uren en vergoedingen' : '' ?>.</p>
  <p><a class="knop" href="<?= esc_url(app_url('privacy/mijn-gegevens.json')) ?>">⬇ Download mijn gegevens</a></p>
  <p class="help">Iets aanpassen? Je eigen zwemmers pas je aan via <a href="<?= esc_url(app_url('zwemmers/')) ?>">Mijn zwemmers</a>, je meldingen via <a href="<?= esc_url(app_url('meldingen/')) ?>">Meldingen</a>. Voor de rest kan je terecht bij de club.</p>
</div>
<div class="kaart">
  <h2 style="margin-top:0">Account verwijderen</h2>
  <?php if ($geblokkeerd): ?><p><?= e($geblokkeerd) ?></p>
  <?php else: ?>
  <p>Je account, je rollen, je aanvragen en je meldingsvoorkeuren worden meteen verwijderd. Dat kan je niet ongedaan maken.</p>
  <?php if ($weg): ?><p><b>Deze zwemmers verdwijnen mee</b>, met hun tijden, inschrijvingen en ploegen: <?= $_namen($weg) ?>.</p><?php endif; ?>
  <?php if ($blijft): ?><p><b>Deze zwemmers blijven</b> bij het andere gekoppelde account (bv. de andere ouder): <?= $_namen($blijft) ?>.</p><?php endif; ?>
  <?php if ($bewaren_tot): ?><p><b>Bewaarplicht</b>: je gaf trainingen voor de club. Je naam, uren en vergoedingen blijven bewaard voor de boekhouding, tot <?= e(datum($bewaren_tot, 'j/m/Y')) ?>. Je account wordt afgesloten: aanmelden kan niet meer, en je e-mailadres en de rest van je gegevens verdwijnen.</p><?php endif; ?>
  <p class="help">Inschrijvingen die de club al doorgaf aan een organisator, blijven daar bewaard. Download eventueel eerst je gegevens.</p>
  <form method="post" action="<?= esc_url(app_url('privacy/verwijderen/')) ?>"><?= nonce_veld() ?>
    <label for="bevestig">Typ VERWIJDER om te bevestigen</label>
    <input id="bevestig" name="bevestig" autocomplete="off" autocapitalize="characters" required>
    <label for="wachtwoord">Je wachtwoord</label>
    <input id="wachtwoord" name="wachtwoord" type="password" autocomplete="current-password" required>
    <p><button class="gevaar vol">Mijn account definitief verwijderen</button></p>
  </form>
  <?php endif; ?>
</div>
