<?php namespace KHZS; defined('ABSPATH') || exit; include __DIR__ . '/_stijl.php'; ?>
<div class="rij tussen"><h1>Mijn trainingen</h1>
  <div class="rij"><a class="knop secundair" href="<?= esc_url(app_url('trainers/planning/')) ?>">📅 Planning</a>
  <?php if ($is_secretaris): ?><a class="knop secundair" href="<?= esc_url(app_url('trainers/beheer/')) ?>">Trainingen beheren</a><?php endif; ?></div></div>

<a class="kaart" href="<?= esc_url(app_url('trainers/uren/')) ?>">
  <div class="rij tussen"><strong>Deze maand (<?= e(tr_maandnaam(vandaag()->format('Y-m'))) ?>)</strong><span class="klein">Mijn uren →</span></div>
  <div class="tr-cijfers">
    <div><span class="tr-getal"><?= (int) ($maand['trainingen'] ?? 0) ?></span><span class="klein">trainingen gegeven</span></div>
    <div><span class="tr-getal"><?= e(tr_uren((int) ($maand['minuten'] ?? 0))) ?></span><span class="klein">uren</span></div>
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
      <span class="klein"><?= e(ucfirst(TR_DAGEN[$m->weekdag])) ?> <?= e(tr_tijd($m)) ?><?= $m->locatie ? ' · ' . e($m->locatie) : '' ?><?= $m->tot ? ' · tot ' . e(datum($m->tot, 'j/m/Y')) : '' ?></span></div>
    <form method="post" action="<?= esc_url(app_url('trainers/vast/')) ?>" class="tr-status"><?= nonce_veld() ?>
      <input type="hidden" name="moment" value="<?= (int) $m->id ?>"><input type="hidden" name="aan" value="<?= $aan ? '0' : '1' ?>">
      <?php if ($aan): ?><span class="badge goedgekeurd">✓ Ik geef deze training</span>
        <button class="mini secundair" onclick="return confirm('Deze training niet meer geven vanaf vandaag?')">Stoppen</button>
      <?php else: ?><button class="mini">Ik geef deze training</button><?php endif; ?>
    </form>
  </div>
  <?php endforeach; ?>
  <?php if (!$momenten): ?><p class="klein">Er zijn nog geen trainingen ingesteld.<?= $is_secretaris ? ' Voeg ze toe via ‘Trainingen beheren’.' : ' Het sportsecretariaat stelt ze in.' ?></p><?php endif; ?>
</div>
