<?php namespace KHZS; defined('ABSPATH') || exit; ?>
<h1><?= e($titel) ?></h1>
<form method="post" class="kaart" action="<?= esc_url(app_url('zwemmers/nieuw/')) ?>"><?= nonce_veld() ?><?= zwemmer_velden('', $v, $f) ?><p><button class="vol">Bewaren</button></p></form>
