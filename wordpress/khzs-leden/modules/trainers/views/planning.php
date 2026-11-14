<?php namespace KHZS; defined('ABSPATH') || exit; include __DIR__ . '/_stijl.php';
$vorige = $maandag->modify('-7 days')->format('Y-m-d');
$volgende = $maandag->modify('+7 days')->format('Y-m-d');
$zondag = $maandag->modify('+6 days'); ?>
<div class="rij tussen"><h1>Planning</h1>
  <div class="rij"><a class="knop secundair" href="<?= esc_url(app_url('trainers/')) ?>">Mijn trainingen</a>
  <?php if (tr_mag_beheren()): ?><a class="knop secundair" href="<?= esc_url(app_url('trainers/beheer/')) ?>">Trainingen beheren</a><?php endif; ?></div></div>
<div class="kaart tr-week">
  <a class="knop mini secundair" href="<?= esc_url(app_url('trainers/planning/', ['week' => $vorige])) ?>">← Vorige</a>
  <strong><?= e(datum($maandag, 'j M')) ?> – <?= e(datum($zondag, 'j M Y')) ?></strong>
  <a class="knop mini secundair" href="<?= esc_url(app_url('trainers/planning/', ['week' => $volgende])) ?>">Volgende →</a>
</div>
<?php for ($i = 0; $i < 7; $i++): $d = $maandag->modify("+$i days")->format('Y-m-d'); if (empty($per_dag[$d])) { continue; } ?>
  <h2 class="tr-dag"><?= e(datum($d, 'l j F')) ?></h2>
  <div class="kaart"><?php foreach ($per_dag[$d] as $b) { include __DIR__ . '/_training.php'; } ?></div>
<?php endfor; ?>
<?php if ($buiten): ?><p class="klein"><?= $buiten === 7 ? 'Buiten het seizoen: deze week zijn er geen trainingen.' : 'Een deel van deze week valt buiten het seizoen: dan zijn er geen trainingen.' ?></p><?php endif; ?>
<?php if (!$per_dag && !$buiten): ?><div class="leeg">Geen trainingen deze week.</div><?php endif; ?>
