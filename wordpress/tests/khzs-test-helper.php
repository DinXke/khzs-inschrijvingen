<?php
/**
 * Plugin Name: KHZS testhulp (ENKEL voor een testsite!)
 * Description: Hulp-API voor de end-to-end-tests in wordpress/tests. Zet dit bestand NOOIT op een echte site.
 */

defined('ABSPATH') || exit;

// Datum simuleren via de header X-Vandaag.
add_filter('khzs_vandaag', fn($d) => $_SERVER['HTTP_X_VANDAAG'] ?? $d);
// Push synchroon en zonder netwerk: berichten worden in een optie bijgehouden.
add_filter('khzs_push_synchroon', '__return_true');
add_filter('khzs_push_verzend', function ($kort, $a, $bericht) {
    $log = get_option('khzs_test_push', []);
    $log[] = ['endpoint' => $a->endpoint, 'bericht' => $bericht];
    update_option('khzs_test_push', $log, false);
    return 201;
}, 10, 3);

add_action('init', function () {
    if (empty($_GET['khzs_test'])) {
        return;
    }
    global $wpdb;
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    switch ($_GET['khzs_test']) {
        case 'reset':
            foreach (\KHZS\TABELLEN as $t) {
                $wpdb->query('TRUNCATE ' . \KHZS\t($t));
            }
            require_once ABSPATH . 'wp-admin/includes/user.php';
            foreach (get_users(['role__not_in' => ['administrator']]) as $u) {
                wp_delete_user($u->ID);
            }
            delete_option('khzs_test_push');
            foreach (\KHZS\backup_lijst() as $f) {
                unlink($f);
            }
            wp_send_json(['ok' => true]);
        case 'user':
            $id = wp_insert_user(['user_login' => $d['login'], 'user_email' => $d['login'], 'user_pass' => $d['pass'] ?? 'geheim123!',
                'first_name' => $d['first_name'] ?? '', 'role' => $d['wprol'] ?? 'subscriber']);
            if (!empty($d['rol'])) {
                update_user_meta($id, 'khzs_rol', $d['rol']);
            }
            wp_send_json(['id' => $id]);
        case 'sql':
            wp_send_json(['rijen' => $wpdb->get_results(str_replace('{p}', $wpdb->prefix . 'khzs_', $d['sql']), ARRAY_A), 'fout' => $wpdb->last_error]);
        case 'exec':
            wp_send_json(['n' => $wpdb->query(str_replace('{p}', $wpdb->prefix . 'khzs_', $d['sql'])), 'id' => $wpdb->insert_id, 'fout' => $wpdb->last_error]);
        case 'push':
            wp_send_json(get_option('khzs_test_push', []));
        case 'herinneringen':
            wp_send_json(\KHZS\verstuur_herinneringen());
        case 'meta':
            wp_send_json(['v' => get_user_meta((int) $d['id'], $d['key'], true)]);
        case 'parse':
            try {
                $root = \KHZS\lees_xml(base64_decode($d['data']));
                wp_send_json(['v' => $d['soort'] === 'uitslagen' ? \KHZS\parse_uitslagen($root) : \KHZS\parse_meet($root)]);
            } catch (\Throwable $e) {
                wp_send_json(['fout' => (new \ReflectionClass($e))->getShortName(), 'tekst' => $e->getMessage()]);
            }
        case 'deluser':
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_send_json(['ok' => wp_delete_user((int) $d['id'])]);
        case 'versleutel':
            wp_send_json(['v' => base64_encode(\KHZS\versleutel($d['tekst'], $d['p256dh'], $d['auth']))]);
        case 'eval':
            // Kleine unit-tests van pure functies.
            $f = '\\KHZS\\' . $d['f'];
            try {
                wp_send_json(['v' => $f(...($d['args'] ?? []))]);
            } catch (\Throwable $e) {
                wp_send_json(['fout' => get_class($e), 'tekst' => $e->getMessage()]);
            }
    }
}, 1);
