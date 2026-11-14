<?php namespace KHZS; defined('ABSPATH') || exit;
include __DIR__ . '/_stijl.php';
$_uid = get_current_user_id();
$_vandaag = vandaag()->format('Y-m-d');
?>
<div class="com-pad klein"><a href="<?= esc_url(app_url('berichten/')) ?>">← Berichten</a></div>
<h1>Aankondigingen</h1>
<?php if ($kanalen): ?>
<details class="kaart" <?= $f || get('soort') ? 'open' : '' ?>><summary><strong>+ Nieuwe aankondiging</strong></summary>
  <form method="post" action="<?= esc_url(app_url('berichten/aankondigingen/')) ?>"><?= nonce_veld() ?>
    <?php include __DIR__ . '/_aank_velden.php'; ?>
    <p><button class="vol">Plaatsen en melden</button></p>
    <p class="help">Wie de aankondiging ziet, krijgt een pushmelding (als die aanstaat bij Meldingen).</p></form>
</details>
<?php endif; ?>
<?php foreach ($lijst as $_a): $_afgelopen = $_a->tot && $_a->tot < $_vandaag; ?>
<a class="kaart" href="<?= esc_url(app_url("berichten/aankondiging/{$_a->id}/")) ?>" style="<?= $_afgelopen ? 'opacity:.65' : '' ?>">
  <div class="rij tussen"><strong><?= COM_AANK_ICOON[$_a->soort] ?> <?= e($_a->titel) ?></strong>
    <span class="klein"><?= e(COM_AANK_SOORT[$_a->soort]) ?> · <?= e(com_aank_doel($_a)) ?> · <?= e(com_wanneer($_a->aangemaakt)) ?><?= $_afgelopen ? ' · afgelopen' : '' ?></span></div>
  <?php if ($_a->soort === 'hulp'): $_t = com_rsvp_totalen((int) $_a->id); ?><div class="klein"><?= (int) $_t['ja']['personen'] ?><?= (int) $_a->nodig ? ' / ' . (int) $_a->nodig : '' ?> personen komen · <?= (int) $_t['misschien']['personen'] ?> misschien</div><?php endif; ?>
</a>
<?php endforeach; ?>
<?php if (!$lijst): ?><div class="leeg">Nog geen aankondigingen.</div><?php endif; ?>
