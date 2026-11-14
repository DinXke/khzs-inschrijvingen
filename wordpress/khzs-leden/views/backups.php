<?php namespace KHZS; defined('ABSPATH') || exit; $url = app_url('backup/'); ?>
<h1>Backup</h1>
<div class="grid twee">
  <div class="kaart">
    <h2 style="margin-top:0">Backup maken</h2>
    <p class="klein">Een backup bevat alle gegevens van de app (zwemmers, tijden, wedstrijden, inschrijvingen, meldingen, rollen) en de opgeladen Lenex-bestanden en bijlagen.
    De WordPress-accounts zelf zitten in de backup van je WordPress-site. Elke nacht om 02:30 wordt automatisch een backup gemaakt (30 dagen bewaard).</p>
    <form method="post" action="<?= esc_url($url) ?>"><?= nonce_veld() ?><button name="actie" value="maak">💾 Nu backup maken</button></form>
  </div>
  <form method="post" enctype="multipart/form-data" class="kaart" action="<?= esc_url($url) ?>"><?= nonce_veld() ?>
    <h2 style="margin-top:0">Backup terugzetten</h2>
    <p class="klein"><b>Let op:</b> alle huidige gegevens worden vervangen. Er wordt eerst automatisch een veiligheidsbackup gemaakt.</p>
    <label for="bestand">Backupbestand (.zip) uploaden</label><input type="file" id="bestand" name="bestand" accept=".zip">
    <label for="bevestig">Typ HERSTEL om te bevestigen</label><input type="text" id="bevestig" name="bevestig" autocomplete="off">
    <p><button name="actie" value="herstel" class="gevaar">Terugzetten</button></p>
  </form>
</div>
<h2>Bewaarde backups</h2>
<div class="kaart tabelwrap"><table>
  <tr><th>Bestand</th><th>Gemaakt</th><th>Grootte</th><th></th></tr>
  <?php foreach ($bestanden as [$naam, $grootte, $tijd]): ?><tr>
    <td><a href="<?= esc_url(app_url('backup/' . rawurlencode($naam))) ?>"><?= e($naam) ?></a></td><td><?= e(datum(wp_date('Y-m-d H:i:s', $tijd), 'j M Y H:i')) ?></td><td><?= e(size_format($grootte)) ?></td>
    <td class="rij"><form method="post" action="<?= esc_url($url) ?>" onsubmit="return (prompt(<?= esc_attr(wp_json_encode("Typ HERSTEL om $naam terug te zetten")) ?>) || '') === 'HERSTEL' && (this.bevestig.value='HERSTEL')"><?= nonce_veld() ?>
        <input type="hidden" name="naam" value="<?= esc_attr($naam) ?>"><input type="hidden" name="bevestig"><button name="actie" value="herstel" class="mini secundair">Terugzetten</button></form>
      <form method="post" action="<?= esc_url($url) ?>" onsubmit="return confirm('Backup verwijderen?')"><?= nonce_veld() ?><input type="hidden" name="naam" value="<?= esc_attr($naam) ?>"><button name="actie" value="verwijder" class="mini gevaar">✕</button></form></td>
  </tr><?php endforeach; ?>
  <?php if (!$bestanden): ?><tr><td colspan="4" class="klein">Nog geen backups.</td></tr><?php endif; ?>
</table></div>
