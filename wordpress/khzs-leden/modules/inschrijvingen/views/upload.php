<?php namespace KHZS; defined('ABSPATH') || exit; ?>
<h1><?= $bestaand ? 'Nieuwe versie: ' . e($bestaand->naam) : 'Wedstrijd toevoegen' ?></h1>
<form method="post" enctype="multipart/form-data" class="kaart" action="<?= esc_url(app_url($bestaand ? "wedstrijd/{$bestaand->id}/opnieuw/" : 'wedstrijd/nieuw/')) ?>"><?= nonce_veld() ?>
  <?= veld_html('id_bestand', 'Lenex-bestand (.lxf of .lef)', '<input type="file" name="bestand" id="id_bestand" required accept=".lxf,.lef,.xml">', $fout ?: null) ?>
  <p class="klein">Programma, sessies, laatste inschrijfdatum en limieten worden automatisch uit het bestand gehaald.
  <?= $bestaand ? 'Bestaande inschrijvingen blijven behouden.' : '' ?></p>
  <p><button class="vol">Inlezen</button></p>
</form>
