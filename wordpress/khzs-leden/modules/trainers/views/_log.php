<?php namespace KHZS; defined('ABSPATH') || exit;
/** Lijst van aanpassingen (tr_log). Verwacht $_log. Ongelezen staan in het vet. */
foreach ($_log as $_w): $_mm = $_w->moment_id ? tr_moment((int) $_w->moment_id) : null; ?>
<div class="tr-rij<?= $_w->gelezen ? '' : ' tr-nieuw' ?>">
  <div class="tr-info">
    <span><?= $_w->gelezen ? '' : '<span class="badge wacht">nieuw</span> ' ?><?= e($_w->wat) ?></span>
    <span class="klein">door <?= e(tr_naam((int) $_w->door_id)) ?> · <?= e(datum($_w->op, 'D j M Y H:i')) ?></span>
  </div>
  <?php if ($_mm && $_w->datum && tr_geldig($_mm, $_w->datum)): ?><div class="tr-status"><a class="knop mini secundair" href="<?= esc_url(tr_training_url($_mm, $_w->datum)) ?>">Training ›</a></div><?php endif; ?>
</div>
<?php endforeach;
