<?php
/**
 * Plugin Name:       KHZS
 * Description:       Clubapp van de Hasseltse Zwemvereniging Spartacus, met modules: wedstrijdinschrijvingen (Lenex) en trainers (planning, afwezigheden, uren en verloning). Gebruikt de WordPress-gebruikers en werkt als app op de gsm.
 * Version:           2.0.0
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Author:            Hasseltse Zwemvereniging Spartacus
 * License:           GPL-2.0-or-later
 * Text Domain:       khzs
 */

namespace KHZS;

defined('ABSPATH') || exit;

const VERSIE = '2.0.0';
const DB_VERSIE = '2';
define('KHZS_PAD', plugin_dir_path(__FILE__));
define('KHZS_URL', plugin_dir_url(__FILE__));

// ---------- kern ----------
require_once KHZS_PAD . 'includes/kern.php';
require_once KHZS_PAD . 'includes/db.php';
require_once KHZS_PAD . 'includes/xlsx.php';
require_once KHZS_PAD . 'includes/push.php';
require_once KHZS_PAD . 'includes/backup.php';
require_once KHZS_PAD . 'includes/app.php';
require_once KHZS_PAD . 'includes/velden.php';
require_once KHZS_PAD . 'includes/kern-acties.php';
require_once KHZS_PAD . 'includes/admin.php';
require_once KHZS_PAD . 'includes/pwa.php';

// ---------- modules ----------
// De tabellen van elke module worden altijd geladen (ook uitgeschakeld blijven de gegevens bewaard en in de backup);
// de rest van een module enkel als ze aanstaat.
foreach (modules() as $id => $m) {
    require_once KHZS_PAD . "modules/$id/db.php";
    if (module_actief($id)) {
        require_once KHZS_PAD . "modules/$id/module.php";
    }
}

register_activation_hook(__FILE__, __NAMESPACE__ . '\\activeer');
register_deactivation_hook(__FILE__, __NAMESPACE__ . '\\deactiveer');

function activeer(): void
{
    installeer_db();
    prive_map();
    registreer_routes();
    flush_rewrite_rules();
    plan_taken();
}

function deactiveer(): void
{
    foreach (array_keys(geplande_taken()) as $hook) {
        wp_clear_scheduled_hook($hook);
    }
    flush_rewrite_rules();
}

/** Dagelijkse taken (hook => uur), in de tijdzone van de site. Modules voegen de hunne toe via khzs_taken. */
function geplande_taken(): array
{
    return apply_filters('khzs_taken', ['khzs_backup' => '02:30']);
}

function plan_taken(): void
{
    $tz = wp_timezone();
    foreach (geplande_taken() as $hook => $uur) {
        if (!wp_next_scheduled($hook)) {
            $t = new \DateTimeImmutable("today $uur", $tz);
            if ($t->getTimestamp() < time()) {
                $t = $t->modify('+1 day');
            }
            wp_schedule_event($t->getTimestamp(), 'daily', $hook);
        }
    }
}

add_action('plugins_loaded', function () {
    if (get_option('khzs_db_versie') !== DB_VERSIE) {
        installeer_db();
    }
});
add_action('init', __NAMESPACE__ . '\\registreer_routes');
add_action('init', __NAMESPACE__ . '\\plan_taken');
add_filter('query_vars', fn($v) => array_merge($v, ['khzs_pad']));
add_action('template_redirect', __NAMESPACE__ . '\\verwerk_verzoek', 0);
add_action('khzs_backup', __NAMESPACE__ . '\\nachtelijke_backup');
add_action('khzs_push', __NAMESPACE__ . '\\push_achtergrond', 10, 2);
add_action('delete_user', __NAMESPACE__ . '\\verwijder_gebruiker_data');
