<?php namespace KHZS; defined('ABSPATH') || exit;
/** Balk bovenaan in de demo: uitleg en (voor beheerders) de knop om de demo terug te zetten. */
?>
<div class="demo-balk" role="note">
  <span><b>Demo</b>: alle gegevens zijn verzonnen. Je kan alles uitproberen; elke nacht staat de demo terug in de begintoestand.<?php
  if (!is_user_logged_in()): ?> Aanmelden: <b>demo</b> / <b>demo</b>.<?php endif; ?></span>
  <?php if ($is_beheerder): ?>
  <form method="post" action="<?= esc_url(app_url('demo/terugzetten/')) ?>" onsubmit="return confirm('Alle wijzigingen in de demo wissen en opnieuw beginnen?')"><?= nonce_veld() ?>
    <button class="mini secundair">Demo terugzetten</button></form>
  <?php endif; ?>
</div>
<style>
.demo-balk{display:flex;flex-wrap:wrap;gap:.5rem 1rem;align-items:center;justify-content:space-between;margin:0 auto;max-width:1100px;
  padding:.55rem 1rem;background:var(--wacht-bg);color:var(--wacht);font-size:.9rem;border-radius:0 0 12px 12px}
.demo-balk form{margin:0}
</style>
