<?php namespace KHZS; defined('ABSPATH') || exit; ?>
<h1>Uitslagen importeren</h1>
<form method="post" enctype="multipart/form-data" class="kaart" action="<?= esc_url(app_url('zwemmers/uitslagen/')) ?>"><?= nonce_veld() ?>
  <?= veld_html('id_bestand', 'Lenex-bestand (.lxf of .lef)', '<input type="file" name="bestand" id="id_bestand" required accept=".lxf,.lef,.xml">', $fout ?: null) ?>
  <p class="klein">Upload de uitslagen-Lenex van een wedstrijd (bv. van de organisator of uit SPLASH). Voor elke clubzwemmer
  wordt per nummer en badlengte de beste tijd bijgehouden. Koppeling via licentienummer, anders via naam + geboortedatum.</p>
  <p><button class="vol">Importeren</button></p>
</form>
<?php if ($stats): ?>
<div class="kaart">
  <strong>Resultaat</strong>
  <ul><li><?= (int) $stats['resultaten'] ?> individuele resultaten in het bestand</li>
  <li><?= (int) $stats['gekoppeld'] ?> van onze zwemmers · <?= (int) $stats['nieuw'] ?> nieuwe · <?= (int) $stats['verbeterd'] ?> verbeterde beste tijden</li></ul>
  <?php if ($stats['onbekend']): ?><details><summary class="klein"><?= count($stats['onbekend']) ?> zwemmers niet gekend in de app</summary>
  <p class="klein"><?= e(implode(', ', $stats['onbekend'])) ?></p></details><?php endif; ?>
</div>
<?php endif; ?>
