<?php
/** Module trainers: trainingsrooster, wie geeft welke training, afmelden/vervangen, aanwezigheid, uren en verloning. */

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
    ['trainers/bevestig', 'tr_bevestig', 'v_tr_bevestig'],
    ['trainers/wijzigingen', 'tr_wijzigingen', 'v_tr_wijzigingen'],
    ['trainers/overzicht', 'tr_overzicht', 'v_tr_overzicht'],
    ['trainers/beheer', 'tr_beheer', 'v_tr_beheer'],
    ['trainers/moment/(\d+)', 'tr_moment', 'v_tr_moment'],
    ['trainers/uren', 'tr_uren', 'v_tr_uren'],
    ['trainers/uren/export', 'tr_export', 'v_tr_export'],
    ['trainers/tarieven', 'tr_tarieven', 'v_tr_tarieven'],
]));

// Antwoorden vanuit de pushmelding: de service worker heeft geen nonce; het token in het bericht beveiligt het verzoek.
add_filter('khzs_zonder_nonce', fn($r) => array_merge($r, ['tr_bevestig']));

add_filter('khzs_menu', fn($m) => $m + [
    30 => ['Trainingen', 'trainers/', ['tr_mijn', 'tr_planning', 'tr_training', 'tr_beheer', 'tr_moment', 'tr_overzicht', 'tr_wijzigingen'],
        '<path d="M2 12c2-2 4-2 6 0s4 2 6 0 4-2 6 0 2 1 2 1M2 17c2-2 4-2 6 0s4 2 6 0 4-2 6 0"/><circle cx="16" cy="6" r="2"/>', fn() => is_staf() || tr_mag_beheren()],
    40 => ['Verloning', 'trainers/uren/', ['tr_uren', 'tr_tarieven'],
        '<rect x="2" y="6" width="20" height="13" rx="2"/><circle cx="12" cy="12.5" r="2.5"/><path d="M6 9.5h.01M18 15.5h.01"/>', fn() => is_penningmeester()],
]);

// Na elke training: "Was je aanwezig?" (WP-Cron, elk kwartier).
add_filter('cron_schedules', fn($s) => $s + ['khzs_kwartier' => ['interval' => 15 * MINUTE_IN_SECONDS, 'display' => 'Elk kwartier (KHZS Leden)']]);
add_action('init', function () {
    if (!wp_next_scheduled('khzs_tr_vragen')) {
        wp_schedule_event(time() + MINUTE_IN_SECONDS, 'khzs_kwartier', 'khzs_tr_vragen');
    }
});
add_action('khzs_tr_vragen', fn() => tr_vraag_aanwezigheid());
add_action('deactivate_' . plugin_basename(KHZS_PAD . 'khzs-leden.php'), fn() => wp_clear_scheduled_hook('khzs_tr_vragen'));
