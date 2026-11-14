<?php
/** Module communicatie: kanalen per ploeg en per wedstrijd (zoals WhatsApp en Discord), vastgepinde berichten, aankondigingen en hulpvragen. */

namespace KHZS;

defined('ABSPATH') || exit;

require_once __DIR__ . '/regels.php';
require_once __DIR__ . '/acties.php';

const COM_ROUTES = ['com', 'com_kanaal', 'com_aankondiging', 'com_beheer'];

add_filter('khzs_routes', fn($r) => array_merge($r, [
    ['berichten', 'com', 'v_com_overzicht'],
    ['berichten/kanaal/(\d+)', 'com_kanaal', 'v_com_kanaal'],
    ['berichten/kanaal/(\d+)/nieuw', 'com_kanaal', 'v_com_nieuw'],
    ['berichten/kanaal/(\d+)/instellingen', 'com_kanaal', 'v_com_kanaal_instellingen'],
    ['berichten/bericht/(\d+)', 'com_kanaal', 'v_com_bericht'],
    ['berichten/bijlage/(\d+)', 'com_kanaal', 'v_com_bijlage'],
    ['berichten/aankondigingen', 'com_aankondiging', 'v_com_aankondigingen'],
    ['berichten/aankondiging/(\d+)', 'com_aankondiging', 'v_com_aankondiging'],
    ['berichten/aankondiging/(\d+)/export\.xlsx', 'com_aankondiging', 'v_com_rsvp_export'],
    ['berichten/beheer', 'com_beheer', 'v_com_beheer'],
]));

add_filter('khzs_menu', fn($m) => $m + [
    15 => ['Berichten', 'berichten/', COM_ROUTES,
        '<path d="M21 12a8 8 0 0 1-11.6 7.1L4 20.5l1.4-4.9A8 8 0 1 1 21 12z"/><path d="M8.5 10.5h7M8.5 13.5h4.5"/>', fn() => true,
        fn() => com_menu_badge()],
]);

add_filter('khzs_voorkeuren', fn($v) => $v + [
    'push_com_bericht' => ['Nieuw bericht in een kanaal (dempen kan per kanaal)', true, false],
    'push_com_vermelding' => ['Iemand vermeldt mij in een bericht (@naam)', true, false],
    'push_com_aankondiging' => ['Nieuwe aankondiging', true, false],
    'push_com_hulpvraag' => ['Nieuwe vraag naar hulp (vrijwilligers)', true, false],
]);

// Een nieuwe of gewijzigde wedstrijd krijgt meteen haar thread (anders bij het openen van Berichten).
add_action('khzs_wedstrijd_bewaard', function () {
    try {
        com_sync(true);
    } catch (\Throwable $e) {
        error_log('khzs communicatie: ' . $e->getMessage());
    }
}, 20);

// Een verwijderde ploeg of wedstrijd: het kanaal blijft (met de berichten), maar gaat naar het archief.
add_action('khzs_ploeg_verwijderd', fn(int $id) => db()->query(db()->prepare("UPDATE " . t('com_kanalen') . " SET gearchiveerd=1 WHERE ploeg_id=%d", $id)));
add_action('khzs_wedstrijd_verwijderd', fn(int $id) => db()->query(db()->prepare("UPDATE " . t('com_kanalen') . " SET gearchiveerd=1 WHERE wedstrijd_id=%d", $id)));

// Knop naar de wedstrijdthread op de pagina van een wedstrijd.
add_action('khzs_wedstrijd_knoppen', function (object $w) {
    com_sync();
    $uid = get_current_user_id();
    foreach (com_kanalen() as $k) {
        if ((int) $k->wedstrijd_id === (int) $w->id && com_ziet($k, $uid)) {
            echo '<p><a class="knop secundair mini" href="' . esc_url(app_url("berichten/kanaal/{$k->id}/")) . '">💬 Wedstrijdchat</a></p>';
            return;
        }
    }
});
