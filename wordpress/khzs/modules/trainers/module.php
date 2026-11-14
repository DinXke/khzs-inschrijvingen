<?php
/** Module trainers: trainingsrooster, wie geeft welke training, afmelden/vervangen, uren en verloning. */

namespace KHZS;

defined('ABSPATH') || exit;

require_once __DIR__ . '/regels.php';
require_once __DIR__ . '/acties.php';

add_filter('khzs_routes', fn($r) => array_merge($r, [
    ['trainers', 'tr_mijn', 'v_tr_mijn'],
    ['trainers/planning', 'tr_planning', 'v_tr_planning'],
    ['trainers/training/(\d+)/(\d{4}-\d{2}-\d{2})', 'tr_training', 'v_tr_training'],
    ['trainers/actie', 'tr_actie', 'v_tr_actie'],
    ['trainers/vast', 'tr_vast', 'v_tr_vast'],
    ['trainers/beheer', 'tr_beheer', 'v_tr_beheer'],
    ['trainers/moment/(\d+)', 'tr_moment', 'v_tr_moment'],
    ['trainers/uren', 'tr_uren', 'v_tr_uren'],
    ['trainers/uren/export', 'tr_export', 'v_tr_export'],
    ['trainers/tarieven', 'tr_tarieven', 'v_tr_tarieven'],
]));

add_filter('khzs_menu', fn($m) => $m + [
    30 => ['Trainingen', 'trainers/', ['tr_mijn', 'tr_planning', 'tr_training', 'tr_beheer', 'tr_moment'],
        '<path d="M2 12c2-2 4-2 6 0s4 2 6 0 4-2 6 0 2 1 2 1M2 17c2-2 4-2 6 0s4 2 6 0 4-2 6 0"/><circle cx="16" cy="6" r="2"/>', fn() => is_staf()],
    40 => ['Verloning', 'trainers/uren/', ['tr_uren', 'tr_tarieven'],
        '<rect x="2" y="6" width="20" height="13" rx="2"/><circle cx="12" cy="12.5" r="2.5"/><path d="M6 9.5h.01M18 15.5h.01"/>', fn() => is_penningmeester()],
]);
