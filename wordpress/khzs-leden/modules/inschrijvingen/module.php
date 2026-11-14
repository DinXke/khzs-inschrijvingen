<?php
/** Module inschrijvingen: wedstrijdinschrijvingen met Lenex. */

namespace KHZS;

defined('ABSPATH') || exit;

require_once __DIR__ . '/regels.php';
require_once __DIR__ . '/data.php';
require_once __DIR__ . '/lenex.php';
require_once __DIR__ . '/export.php';
require_once __DIR__ . '/acties.php';

add_filter('khzs_routes', fn($r) => array_merge($r, [
    ['registreren', 'registreren', 'v_registreren'],
    ['welkom', 'welkom', 'v_welkom'],
    ['zwemmers/(\d+)/ploeg', 'zwemmer', 'v_zwemmer_ploeg'],
    ['wedstrijden', 'wedstrijden', 'v_wedstrijden'],
    ['wedstrijd/nieuw', 'upload', 'v_upload'],
    ['wedstrijd/(\d+)', 'wedstrijd', 'v_wedstrijd'],
    ['wedstrijd/(\d+)/inschrijven', 'inschrijven', 'v_inschrijven'],
    ['wedstrijd/(\d+)/nakijken', 'nakijken', 'v_nakijken'],
    ['wedstrijd/(\d+)/overzicht', 'overzicht', 'v_overzicht'],
    ['wedstrijd/(\d+)/finaliseren', 'finaliseren', 'v_finaliseren'],
    ['wedstrijd/(\d+)/bijlage', 'bijlage_toevoegen', 'v_bijlage_toevoegen'],
    ['bijlage/(\d+)', 'bijlage', 'v_bijlage'],
    ['bijlage/(\d+)/verwijderen', 'bijlage_verwijderen', 'v_bijlage_verwijderen'],
    ['wedstrijd/(\d+)/deelname', 'deelname', 'v_deelname'],
    ['wedstrijd/(\d+)/deelname/beoordelen', 'deelname_beoordelen', 'v_deelname_beoordelen'],
    ['wedstrijd/(\d+)/beoordelen', 'beoordelen', 'v_beoordelen'],
    ['wedstrijd/(\d+)/instellingen', 'instellingen', 'v_instellingen'],
    ['wedstrijd/(\d+)/opnieuw', 'upload', 'v_upload'],
    ['wedstrijd/(\d+)/verwijderen', 'verwijderen', 'v_verwijderen'],
    ['wedstrijd/(\d+)/export\.lxf', 'export_lxf', 'v_export_lxf'],
    ['wedstrijd/(\d+)/export\.xlsx', 'export_xlsx', 'v_export_xlsx'],
    ['zwemmers', 'zwemmers', 'v_zwemmers'],
    ['zwemmers/uitslagen', 'uitslagen', 'v_uitslagen'],
    ['zwemmers/nieuw', 'zwemmer_nieuw', 'v_zwemmer_nieuw'],
    ['zwemmers/(\d+)', 'zwemmer', 'v_zwemmer'],
    ['zwemmers/koppelen', 'zwemmers', 'v_zwemmer_koppelen'],
    ['zwemmers/(\d+)/delen', 'zwemmer', 'v_zwemmer_delen'],
    ['zwemmers/(\d+)/ontkoppelen', 'zwemmer', 'v_zwemmer_ontkoppelen'],
    ['zwemmers/account', 'zwemmer', 'v_zwemmer_account'],
    ['tijd/(\d+)/verwijderen', 'tijd_verwijderen', 'v_tijd_verwijderen'],
    ['uitleg', 'uitleg', 'v_uitleg'],
]));

add_filter('khzs_menu', fn($m) => $m + [
    10 => ['Wedstrijden', 'wedstrijden/', ['wedstrijden', 'wedstrijd', 'nakijken', 'instellingen', 'upload'],
        '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>', fn() => !zonder_wedstrijden()],
    20 => [is_staf() ? 'Zwemmers' : 'Profiel', 'zwemmers/', ['zwemmers', 'zwemmer', 'zwemmer_nieuw', 'uitslagen'],
        '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/>', fn() => true],
    70 => ['Uitleg', 'uitleg/', ['uitleg'], '<circle cx="12" cy="12" r="10"/><path d="M10 8l6 4-6 4z" fill="currentColor"/>', fn() => true],
]);

add_filter('khzs_taken', fn($t) => $t + ['khzs_herinneringen' => '08:00']);
add_action('khzs_herinneringen', __NAMESPACE__ . '\\verstuur_herinneringen');
