<?php namespace KHZS; defined('ABSPATH') || exit; include __DIR__ . '/_stijl.php'; ?>
<p><a href="<?= esc_url(app_url('trainers/')) ?>">← Mijn trainingen</a></p>
<h1>Wijzigingen aan mijn planning</h1>
<p class="klein">Wat het bestuur of het sportsecretariaat aan je vaste trainingen of je aanwezigheid veranderde.</p>
<div class="kaart">
  <?php $_log = $log; include __DIR__ . '/_log.php'; ?>
  <?php if (!$log): ?><p class="klein">Nog geen wijzigingen door anderen.</p><?php endif; ?>
</div>
