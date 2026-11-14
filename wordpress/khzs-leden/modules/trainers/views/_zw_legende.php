<?php namespace KHZS; defined('ABSPATH') || exit; ?>
<p class="klein tr-zw-legende"><?php foreach (TR_ZW_SOORT as $_k => $_n): ?><span><i class="st-<?= $_k ?>"></i><?= e($_n) ?></span><?php endforeach; ?></p>
