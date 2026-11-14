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
// Demo (KHZS_DEMO in wp-config.php) voor één verzoek uitzetten via de header X-KHZS-Demo-Uit.
add_filter('khzs_demo_aan', fn($aan) => isset($_SERVER['HTTP_X_KHZS_DEMO_UIT']) ? false : $aan);
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
            foreach (\KHZS\alle_tabellen() as $t) {
                $wpdb->query('TRUNCATE ' . \KHZS\t($t));
            }
            require_once ABSPATH . 'wp-admin/includes/user.php';
            foreach (get_users(['role__not_in' => ['administrator']]) as $u) {
                wp_delete_user($u->ID);
            }
            delete_option('khzs_test_push');
            $wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key IN ('khzs_penningmeester','khzs_rollen','khzs_rol')");
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
            if (!empty($d['penningmeester'])) {
                update_user_meta($id, 'khzs_penningmeester', '1');
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
        case 'cache':
            wp_cache_flush();
            wp_send_json(['ok' => true]);
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

// Websitekalender (The Events Calendar): pas na init, want dan bestaan de posttypes.
add_action('init', function () {
    if (empty($_GET['khzs_test']) || !str_starts_with($_GET['khzs_test'], 'tec')) {
        return;
    }
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $types = ['tribe_events', 'tribe_venue', 'tribe_organizer'];
    switch ($_GET['khzs_test']) {
        case 'tec':
            wp_send_json(['actief' => function_exists('\KHZS\tec_actief') && \KHZS\tec_actief()]);
        case 'tec_leeg':
            foreach (get_posts(['post_type' => $types, 'post_status' => ['publish', 'future', 'draft', 'pending', 'private', 'trash'], 'numberposts' => -1, 'fields' => 'ids',
                'suppress_filters' => true, 'tribe_suppress_query_filters' => true]) as $id) {
                wp_delete_post($id, true);
            }
            foreach (['aan', 'statussen', 'categorie', 'publiceren', 'info', 'bijlagen', 'fout', 'laatst'] as $k) {
                delete_option("khzs_kalender_$k");
            }
            wp_send_json(['ok' => true]);
        case 'tec_evenementen':
            $uit = [];
            foreach (get_posts(['post_type' => 'tribe_events', 'post_status' => ['publish', 'future', 'draft', 'pending', 'private', 'trash'], 'numberposts' => -1, 'orderby' => 'ID', 'order' => 'ASC',
                'suppress_filters' => true, 'tribe_suppress_query_filters' => true]) as $p) {
                $meta = array_map(fn($v) => count($v) === 1 ? $v[0] : $v, get_post_meta($p->ID));
                $venue = (int) ($meta['_EventVenueID'] ?? 0);
                $org = (int) ($meta['_EventOrganizerID'] ?? 0);
                $uit[] = ['id' => $p->ID, 'status' => $p->post_status, 'titel' => $p->post_title, 'inhoud' => $p->post_content,
                    'meta' => $meta, 'categorieen' => wp_get_object_terms($p->ID, 'tribe_events_cat', ['fields' => 'names']),
                    'locatie' => $venue ? get_the_title($venue) : '', 'stad' => $venue ? get_post_meta($venue, '_VenueCity', true) : '',
                    'organisator' => $org ? get_the_title($org) : ''];
            }
            wp_send_json($uit);
        case 'tec_wijzig':
            // Zoals iemand die het evenement op de website met de hand aanpast.
            wp_send_json(['ok' => wp_update_post($d)]);
    }
}, 999);
