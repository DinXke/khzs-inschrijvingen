<?php namespace KHZS; defined('ABSPATH') || exit; ?>
<h1><?= e($titel) ?></h1>
<form method="post" class="kaart" action="<?= esc_url(app_url('zwemmers/nieuw/', !empty($zelf) ? ['zelf' => 1] : [])) ?>"><?= nonce_veld() ?><?= zwemmer_velden('', $v, $f) ?><?= $ploegen ? veld_select('ploeg', 'Ploeg', ['' => 'Weet ik nog niet'] + array_column($ploegen, 'naam', 'id'), $v['ploeg'] ?? '', null, 'De trainer of het bestuur keurt de ploeg goed.') : '' ?><p><button class="vol">Bewaren</button></p></form>
