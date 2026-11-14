<?php
/**
 * Plugin Name:       KHZS inschrijvingen
 * Description:       Inschrijfsysteem voor zwemwedstrijden: Lenex-uitnodigingen inlezen, zwemmers laten inschrijven, trainers laten nakijken en de inschrijvingen exporteren als Lenex en Excel. Gebruikt de WordPress-gebruikers.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Author:            Hasseltse Zwemvereniging Spartacus
 * License:           GPL-2.0-or-later
 * Text Domain:       khzs-inschrijvingen
 */

namespace KHZS;

defined('ABSPATH') || exit;

const VERSIE = '1.0.0';
const DB_VERSIE = '1';
define('KHZS_PAD', plugin_dir_path(__FILE__));
define('KHZS_URL', plugin_dir_url(__FILE__));

require_once KHZS_PAD . 'includes/db.php';
require_once KHZS_PAD . 'includes/regels.php';
require_once KHZS_PAD . 'includes/data.php';
require_once KHZS_PAD . 'includes/lenex.php';
require_once KHZS_PAD . 'includes/xlsx.php';
require_once KHZS_PAD . 'includes/push.php';
require_once KHZS_PAD . 'includes/backup.php';
require_once KHZS_PAD . 'includes/app.php';
require_once KHZS_PAD . 'includes/velden.php';
require_once KHZS_PAD . 'includes/acties.php';
require_once KHZS_PAD . 'includes/admin.php';

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
    wp_clear_scheduled_hook('khzs_herinneringen');
    wp_clear_scheduled_hook('khzs_backup');
    flush_rewrite_rules();
}

/** Dagelijkse taken: deadline-herinneringen (08:00) en backup (02:30), in de tijdzone van de site. */
function plan_taken(): void
{
    $tz = wp_timezone();
    foreach (['khzs_herinneringen' => '08:00', 'khzs_backup' => '02:30'] as $hook => $uur) {
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
add_action('khzs_herinneringen', __NAMESPACE__ . '\\verstuur_herinneringen');
add_action('khzs_backup', __NAMESPACE__ . '\\nachtelijke_backup');
add_action('khzs_push', __NAMESPACE__ . '\\push_achtergrond', 10, 2);
add_action('delete_user', __NAMESPACE__ . '\\verwijder_gebruiker_data');
