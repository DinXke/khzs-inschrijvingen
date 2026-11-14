<?php namespace KHZS; defined('ABSPATH') || exit; include __DIR__ . '/_stijl.php'; $vandaag = vandaag()->format('Y-m-d'); ?>
<div class="rij tussen"><h1>Mijn trainingen</h1>
  <div class="rij"><a class="knop secundair" href="<?= esc_url(app_url('trainers/planning/')) ?>">📅 Planning</a>
  <?php if (tr_mag_beheren()): ?><a class="knop secundair" href="<?= esc_url(app_url('trainers/overzicht/')) ?>">Overzicht trainers</a><?php endif; ?>
  <?php if ($is_secretaris): ?><a class="knop secundair" href="<?= esc_url(app_url('trainers/beheer/')) ?>">Trainingen beheren</a><?php endif; ?></div></div>

<?php if ($ongelezen): ?>
<h2>✏️ Aangepast door het bestuur</h2>
<div class="kaart" style="border-left:4px solid var(--wacht)">
  <?php $_log = $ongelezen; include __DIR__ . '/_log.php'; ?>
  <p style="margin-bottom:0"><a class="knop mini secundair" href="<?= esc_url(app_url('trainers/wijzigingen/')) ?>">Gezien · alle wijzigingen</a></p>
</div>
<?php endif; ?>

<?php if ($te_bevestigen): ?>
<h2>🙋 Was je aanwezig?</h2>
<div class="kaart" style="border-left:4px solid var(--accent,#0e6b73)">
  <?php $_naam = false; foreach ($te_bevestigen as $b) { $_wie = $uid; include __DIR__ . '/_bevestiging.php'; } ?>
  <?php if (count($te_bevestigen) > 1): ?>
  <form method="post" action="<?= esc_url(app_url('trainers/')) ?>" style="margin-top:.6rem"><?= nonce_veld() ?><input type="hidden" name="actie" value="alles_ja">
    <button class="mini ok">✓ Ik was overal aanwezig (<?= count($te_bevestigen) ?>)</button></form>
  <?php endif; ?>
  <p class="help">Enkel trainingen waarop je aanwezig was, tellen mee voor je uren.</p>
</div>
<?php endif; ?>

<a class="kaart" href="<?= esc_url(app_url('trainers/uren/')) ?>">
  <div class="rij tussen"><strong>Deze maand (<?= e(tr_maandnaam(vandaag()->format('Y-m'))) ?>)</strong><span class="klein">Mijn uren →</span></div>
  <div class="tr-cijfers">
    <div><span class="tr-getal"><?= (int) ($maand['trainingen'] ?? 0) ?></span><span class="klein">trainingen gegeven</span></div>
    <div><span class="tr-getal"><?= e(tr_uren((int) ($maand['minuten'] ?? 0))) ?></span><span class="klein">uren</span></div>
    <div><span class="tr-getal"><?= (int) ($maand['open'] ?? 0) ?></span><span class="klein">nog te bevestigen</span></div>
    <div><span class="tr-getal"><?= (int) ($maand['gepland'] ?? 0) ?></span><span class="klein">nog gepland</span></div>
  </div>
</a>

<?php if ($open): ?>
<h2>🔔 Vervanger gezocht</h2>
<div class="kaart" style="border-left:4px solid var(--fout)"><?php foreach ($open as $b) { include __DIR__ . '/_training.php'; } ?></div>
<?php endif; ?>

<h2>Komende 4 weken</h2>
<div class="kaart">
  <?php foreach ($mijn as $b) { include __DIR__ . '/_training.php'; } ?>
  <?php if (!$mijn): ?><p class="klein">Je staat de komende 4 weken op geen enkele training. Duid hieronder aan welke trainingen je normaal geeft.</p><?php endif; ?>
</div>

<h2>Welke trainingen geef ik normaal?</h2>
<div class="kaart">
  <?php foreach ($momenten as $m): $aan = isset($vast[(int) $m->id]); ?>
  <div class="tr-rij">
    <div class="tr-info"><strong><?= e($m->naam) ?></strong>
      <span class="klein"><?= e(ucfirst(TR_DAGEN[$m->weekdag])) ?> <?= e(tr_tijd($m)) ?><?= $m->locatie ? ' · ' . e($m->locatie) : '' ?><?= $m->tot ? ' · tot ' . e(datum($m->tot, 'j/m/Y')) : '' ?>
        <?= $aan ? ' · ik geef ze sinds ' . e(datum($vast[(int) $m->id]->van, 'j/m/Y')) : '' ?></span></div>
    <form method="post" action="<?= esc_url(app_url('trainers/vast/')) ?>" class="tr-status"><?= nonce_veld() ?>
      <input type="hidden" name="moment" value="<?= (int) $m->id ?>"><input type="hidden" name="aan" value="<?= $aan ? '0' : '1' ?>">
      <?php if ($aan): ?><span class="badge goedgekeurd">✓ Ik geef deze training</span><?php endif; ?>
      <details class="tr-kannie"><summary class="knop mini <?= $aan ? 'secundair' : '' ?>"><?= $aan ? 'Stoppen' : 'Ik geef deze training' ?></summary>
        <div class="rij" style="align-items:end">
          <div><label for="vanaf-<?= (int) $m->id ?>"><?= $aan ? 'Niet meer vanaf' : 'Vanaf' ?></label>
            <input id="vanaf-<?= (int) $m->id ?>" type="date" name="vanaf" value="<?= esc_attr(max($vandaag, $m->van)) ?>" min="<?= esc_attr(max($terug_tot, $m->van)) ?>"></div>
          <button class="mini <?= $aan ? 'gevaar' : 'ok' ?>"><?= $aan ? 'Stoppen' : 'Bewaren' ?></button>
        </div></details>
    </form>
  </div>
  <?php endforeach; ?>
  <?php if (!$momenten): ?><p class="klein">Er zijn nog geen trainingen ingesteld.<?= $is_secretaris ? ' Voeg ze toe via ‘Trainingen beheren’.' : ' Het sportsecretariaat stelt ze in.' ?></p><?php endif; ?>
  <p class="help">Een datum in het verleden kan tot het begin van vorige maand (niet in een maand die al uitbetaald is). Die trainingen moet je daarna nog bevestigen.</p>
</div>

<?php if ($afgelopen): ?>
<h2>Afgelopen 4 weken</h2>
<div class="kaart">
  <?php $_naam = false; foreach ($afgelopen as $b) { $_wie = $uid; include __DIR__ . '/_bevestiging.php'; } ?>
  <p class="help">Iets vergeten of verkeerd aangeduid? Pas het hier aan, of open de training om er toch bij te staan (bv. als extra trainer).</p>
</div>
<?php endif; ?>
