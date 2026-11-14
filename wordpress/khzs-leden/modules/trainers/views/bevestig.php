<?php namespace KHZS; defined('ABSPATH') || exit;
/** Antwoordscherm vanuit de pushmelding: twee grote knoppen, werkt ook zonder aan te melden (token). */
$_knop = fn(string $antwoord, string $label, string $klasse) => '<form method="post" action="' . esc_url(app_url('trainers/bevestig/')) . '">'
    . '<input type="hidden" name="t" value="' . esc_attr($token) . '"><input type="hidden" name="antwoord" value="' . esc_attr($antwoord) . '">'
    . '<button class="' . esc_attr($klasse) . '" style="width:100%;font-size:1.15rem;padding:.9rem">' . e($label) . '</button></form>'; ?>
<h1>Was je aanwezig?</h1>
<div class="kaart">
  <p style="margin-top:0"><strong><?= e($m->naam) ?></strong><br>
    <span class="klein"><?= e(datum($datum, 'l j F Y')) ?> · <?= e(tr_tijd($m)) ?><?= $m->locatie ? ' · ' . e($m->locatie) : '' ?></span></p>
  <?php if (wp_get_current_user()->ID !== $wie): ?><p class="klein">Voor <?= e(tr_naam($wie)) ?>.</p><?php endif; ?>
  <?php if ($status === 'ja'): ?><p><span class="badge goedgekeurd">✓ aanwezig</span> <span class="klein"><?= e($door) ?></span></p>
  <?php elseif ($status === 'nee'): ?><p><span class="badge afgekeurd">✗ niet aanwezig</span> <span class="klein"><?= e($door) ?></span></p>
  <?php elseif ($status === 'gepland'): ?><p class="klein">Deze training is nog niet voorbij. Je kan bevestigen zodra ze gedaan is.</p>
  <?php else: ?><p><span class="badge wacht">? nog niet bevestigd</span></p><?php endif; ?>
  <?php if ($vergrendeld): ?><p class="klein">Deze maand is al uitbetaald. Klopt er iets niet, vraag dan het bestuur om een correctie.</p>
  <?php elseif ($status !== 'gepland'): ?>
    <div class="grid twee" style="margin-top:1rem">
      <?= $_knop('ja', $status === 'nee' ? '✓ Toch aanwezig' : '✓ Ja, ik was er', 'ok') ?>
      <?= $_knop('nee', $status === 'ja' ? '✗ Toch niet' : '✗ Nee', 'secundair') ?>
    </div>
  <?php endif; ?>
</div>
<p class="klein">Enkel trainingen waarop je aanwezig was, tellen mee voor je uren. <a href="<?= esc_url(app_url('trainers/')) ?>">Naar mijn trainingen →</a></p>
