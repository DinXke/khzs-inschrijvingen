<?php namespace KHZS; defined('ABSPATH') || exit;
include __DIR__ . '/_stijl.php';
$_uid = get_current_user_id();
$_a = $a;
?>
<div class="com-pad klein"><a href="<?= esc_url(app_url('berichten/')) ?>">← Berichten</a> · <a href="<?= esc_url(app_url('berichten/aankondigingen/')) ?>">Aankondigingen</a></div>
<?php include __DIR__ . '/_aankondiging.php'; ?>

<?php if ($a->soort === 'hulp' && com_ziet_rsvp_lijst($a, $_uid)): $_t = com_rsvp_totalen((int) $a->id); ?>
<div class="kaart" id="lijst">
  <div class="rij tussen"><h2 style="margin:0">Wie komt helpen?</h2>
    <a class="knop mini secundair" href="<?= esc_url(app_url("berichten/aankondiging/{$a->id}/export.xlsx")) ?>">⬇ Excel</a></div>
  <div class="stappen">
    <?php foreach (COM_RSVP as $_k => $_l): ?><div class="stap"><?= e($_l) ?><b><?= (int) $_t[$_k]['personen'] ?> <?= $_k === 'nee' ? '' : ((int) $_t[$_k]['personen'] === 1 ? 'persoon' : 'personen') ?></b><span class="klein"><?= (int) $_t[$_k]['antwoorden'] ?> antwoord<?= (int) $_t[$_k]['antwoorden'] === 1 ? '' : 'en' ?></span></div><?php endforeach; ?>
    <?php if ((int) $a->nodig): ?><div class="stap">Nodig<b><?= (int) $a->nodig ?></b><span class="klein">nog <?= max(0, (int) $a->nodig - (int) $_t['ja']['personen']) ?> te vinden</span></div><?php endif; ?>
  </div>
  <?php if ($lijst): ?>
  <div class="tabelwrap"><table>
    <tr><th>Naam</th><th>Antwoord</th><th>Personen</th><th>Opmerking</th><th>Wanneer</th></tr>
    <?php foreach ($lijst as $_r): ?><tr><td><?= e(com_naam((int) $_r->user_id)) ?></td><td><span class="badge <?= $_r->antwoord === 'ja' ? 'ok' : ($_r->antwoord === 'nee' ? 'doorgestuurd' : 'wacht') ?>"><?= e(COM_RSVP[$_r->antwoord]) ?></span></td>
      <td><?= $_r->antwoord === 'nee' ? '' : (int) $_r->personen ?></td><td><?= e($_r->opmerking) ?></td><td class="klein"><?= e(com_wanneer($_r->gewijzigd)) ?></td></tr><?php endforeach; ?>
  </table></div>
  <?php else: ?><p class="klein">Nog niemand antwoordde.</p><?php endif; ?>
</div>
<?php endif; ?>

<?php if ($wijzigen): ?>
<details class="kaart" <?= $f ? 'open' : '' ?>><summary><strong>Bewerken</strong></summary>
  <form method="post" action="<?= esc_url(app_url("berichten/aankondiging/{$a->id}/")) ?>"><?= nonce_veld() ?><input type="hidden" name="actie" value="bewaar">
    <?php include __DIR__ . '/_aank_velden.php'; ?>
    <p><button class="vol">Bewaren</button></p>
    <p class="help">Bewaren stuurt geen nieuwe melding.</p></form>
</details>
<div class="rij">
  <?php if (!$a->tot || $a->tot >= vandaag()->format('Y-m-d')): ?>
  <form method="post" action="<?= esc_url(app_url("berichten/aankondiging/{$a->id}/")) ?>"><?= nonce_veld() ?><button class="secundair" name="actie" value="beeindigen">Niet meer bovenaan tonen</button></form>
  <?php endif; ?>
  <form method="post" action="<?= esc_url(app_url("berichten/aankondiging/{$a->id}/")) ?>" onsubmit="return confirm('Deze aankondiging en alle antwoorden verwijderen?')"><?= nonce_veld() ?><button class="gevaar" name="actie" value="verwijderen">Verwijderen</button></form>
</div>
<?php endif; ?>
