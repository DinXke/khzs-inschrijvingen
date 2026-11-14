<?php
/** Pagina's van de kern: aanmelden, gebruikers & rollen, meldingen, backup, PWA. */

namespace KHZS;

defined('ABSPATH') || exit;

function terug(string $standaard): string
{
    $t = post('terug');
    return $t ? wp_validate_redirect($t, $standaard) : $standaard;
}

function v_start(): void
{
    vereist_login();
    $menu = menu();
    naar(app_url($menu[0][1] ?? 'meldingen/'));
}

function v_aanmelden(): void
{
    if (is_user_logged_in()) {
        naar(app_url(''));
    }
    $fout = false;
    $next = post('next') ?: get('next');
    if (is_post()) {
        $u = wp_signon(['user_login' => post('username'), 'user_password' => post_ruw('password') ?? '', 'remember' => true], is_ssl());
        if (!is_wp_error($u)) {
            naar($next ? wp_validate_redirect($next, app_url('')) : app_url(''));
        }
        $fout = true;
    }
    toon('aanmelden', ['fout' => $fout, 'next' => $next, 'gebruiker' => post('username')], 'Aanmelden', 'smal');
}

function v_afmelden(): void
{
    vereist_post();
    wp_logout();
    naar(app_url('aanmelden/'));
}

function registratie_open(): bool
{
    return (bool) get_option('khzs_registratie', 1);
}

function v_gebruikers(): void
{
    vereist_rol(SECRETARIAAT);
    $beheerder = rol() === BEHEERDER;
    if (is_post()) {
        $u = get_userdata((int) post('user')) ?: niet_gevonden();
        if (isset($_POST['penningmeester'])) {
            if (!$beheerder) {
                geen_toegang('Enkel een beheerder kan de penningmeester aanduiden.');
            }
            update_user_meta($u->ID, 'khzs_penningmeester', post('penningmeester') === '1' ? '1' : '0');
            flash((volle_naam($u) ?: $u->user_login) . (post('penningmeester') === '1' ? ' is nu penningmeester.' : ' is geen penningmeester meer.'));
            naar(app_url('gebruikers/'));
        }
        $nieuw = post('rol');
        $huidig = rol($u);
        if (user_can($u, 'manage_options')) {
            flash('WordPress-beheerders zijn altijd beheerder in de app.', 'error');
            naar(app_url('gebruikers/'));
        }
        if (!$beheerder && in_array(BEHEERDER, [$huidig, $nieuw], true)) {
            flash('Enkel een beheerder kan beheerders aanstellen of wijzigen.', 'error');
            naar(app_url('gebruikers/'));
        }
        if (isset(ROLLEN[$nieuw])) {
            update_user_meta($u->ID, 'khzs_rol', $nieuw);
            flash((volle_naam($u) ?: $u->user_login) . ' is nu ' . mb_strtolower(ROLLEN[$nieuw]) . '.');
        }
        naar(app_url('gebruikers/'));
    }
    $q = get('q');
    $args = ['orderby' => 'meta_value', 'meta_key' => 'last_name', 'order' => 'ASC', 'number' => 500];
    if ($q !== '') {
        $args['search'] = '*' . $q . '*';
        $args['search_columns'] = ['user_login', 'user_email', 'display_name'];
    }
    $users = get_users($args);
    if ($q !== '') {
        // Ook op voor- en achternaam zoeken (user meta).
        $extra = get_users(['meta_query' => ['relation' => 'OR', ['key' => 'first_name', 'value' => $q, 'compare' => 'LIKE'],
            ['key' => 'last_name', 'value' => $q, 'compare' => 'LIKE']], 'number' => 500]);
        foreach ($extra as $u) {
            $users[$u->ID] = $u;
        }
        $users = array_values(array_reduce($users, function ($c, $u) {
            $c[$u->ID] = $u;
            return $c;
        }, []));
        usort($users, fn($a, $b) => [$a->last_name, $a->first_name] <=> [$b->last_name, $b->first_name]);
    }
    $rollen = $beheerder ? ROLLEN : array_diff_key(ROLLEN, [BEHEERDER => 1]);
    toon('gebruikers', ['users' => $users, 'q' => $q, 'rollen' => $rollen], 'Gebruikers');
}

function v_backups(): void
{
    vereist_rol([BEHEERDER]);
    if (is_post()) {
        $actie = post('actie');
        try {
            if ($actie === 'maak') {
                flash('Backup gemaakt: ' . basename(backup_maak()));
            } elseif ($actie === 'herstel') {
                if (post('bevestig') !== 'HERSTEL') {
                    flash('Typ HERSTEL om te bevestigen.', 'error');
                    naar(app_url('backup/'));
                }
                $bron = !empty($_FILES['bestand']['tmp_name']) && $_FILES['bestand']['error'] === UPLOAD_ERR_OK
                    ? $_FILES['bestand']['tmp_name'] : backup_zoek(post('naam'));
                [$n, $veilig] = backup_herstel($bron);
                flash("Backup teruggezet ($n rijen). Veiligheidsbackup van daarvoor: " . basename($veilig));
            } elseif ($actie === 'verwijder') {
                unlink(backup_zoek(post('naam')));
                flash('Backup verwijderd.');
            }
        } catch (\Throwable $e) {
            flash('Mislukt: ' . $e->getMessage(), 'error');
        }
        naar(app_url('backup/'));
    }
    $bestanden = array_map(fn($f) => [basename($f), filesize($f), filemtime($f)], backup_lijst());
    toon('backups', ['bestanden' => $bestanden], 'Backup');
}

function v_backup_download($naam): void
{
    vereist_rol([BEHEERDER]);
    try {
        $pad = backup_zoek(rawurldecode($naam));
    } catch (\RuntimeException) {
        niet_gevonden();
    }
    download(file_get_contents($pad), 'application/zip', basename($pad));
}

function v_meldingen(): void
{
    vereist_login();
    $uid = get_current_user_id();
    $staf = is_staf();
    $velden = array_keys(array_filter(voorkeuren(), fn($v) => $staf || empty($v[2])));
    if (is_post()) {
        foreach ($velden as $k) {
            update_user_meta($uid, "khzs_$k", isset($_POST[$k]) ? '1' : '0');
        }
        flash('Voorkeuren bewaard.');
        naar(app_url('meldingen/'));
    }
    $toestellen = (int) db()->get_var(db()->prepare("SELECT COUNT(*) FROM " . t('push') . " WHERE user_id=%d", $uid));
    toon('meldingen', ['velden' => $velden, 'staf' => $staf, 'vapid' => publieke_sleutel(), 'toestellen' => $toestellen], 'Meldingen', 'smal');
}

function v_push_abonneer(): void
{
    $d = json_verzoek();
    $endpoint = $d['endpoint'] ?? null;
    $keys = $d['keys'] ?? null;
    if (!is_string($endpoint) || !str_starts_with($endpoint, 'https://') || !is_array($keys) || empty($keys['p256dh']) || empty($keys['auth'])) {
        wp_send_json(['ok' => false], 400);
    }
    $hash = hash('sha256', $endpoint);
    $rij = ['user_id' => get_current_user_id(), 'endpoint' => mb_substr($endpoint, 0, 1000), 'endpoint_hash' => $hash,
        'p256dh' => substr((string) $keys['p256dh'], 0, 200), 'auth' => substr((string) $keys['auth'], 0, 100),
        'toestel' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200)];
    $bestaand = db()->get_var(db()->prepare("SELECT id FROM " . t('push') . " WHERE endpoint_hash=%s", $hash));
    if ($bestaand) {
        db()->update(t('push'), $rij, ['id' => $bestaand]);
    } else {
        db()->insert(t('push'), $rij + ['aangemaakt' => nu_sql()]);
    }
    wp_send_json(['ok' => true]);
}

function v_push_afmelden(): void
{
    $d = json_verzoek();
    if (!is_string($d['endpoint'] ?? null)) {
        wp_send_json(['ok' => false], 400);
    }
    db()->delete(t('push'), ['user_id' => get_current_user_id(), 'endpoint_hash' => hash('sha256', $d['endpoint'])]);
    wp_send_json(['ok' => true]);
}

function v_push_test(): void
{
    json_verzoek();
    $n = stuur([get_current_user_id()], 'Testmelding', 'Pushmeldingen werken op dit toestel 👍', app_url('meldingen/'), false);
    wp_send_json(['ok' => (bool) $n, 'toestellen' => $n]);
}

function v_manifest(): void
{
    header('Content-Type: application/manifest+json; charset=utf-8');
    $club = club();
    echo wp_json_encode([
        'id' => app_url(''),
        'name' => app_naam(),
        'short_name' => app_kort(),
        'description' => "Ledenapp van {$club['naam']}",
        'lang' => 'nl-BE',
        'start_url' => app_url('', ['bron' => 'app']),
        'scope' => app_url(''),
        'display' => 'standalone',
        'orientation' => 'portrait-primary',
        'background_color' => '#f4f6f7',
        'theme_color' => '#0e6b73',
        'icons' => [
            ['src' => asset('img/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png'],
            ['src' => asset('img/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png'],
            ['src' => asset('img/icon-maskable-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
        ],
        'shortcuts' => array_map(fn($i) => ['name' => $i[0], 'url' => app_url($i[1])], array_slice(menu(), 0, 4)),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function v_sw(): void
{
    header('Content-Type: application/javascript; charset=utf-8');
    header('Service-Worker-Allowed: ' . wp_parse_url(app_url(''), PHP_URL_PATH));
    header('Cache-Control: no-cache');
    include KHZS_PAD . 'views/sw.js.php';
    exit;
}

function v_installeer(): void
{
    toon('installeer', [], 'App installeren', 'smal');
}

function v_offline(): void
{
    header('Content-Type: text/html; charset=utf-8');
    include KHZS_PAD . 'views/offline.php';
    exit;
}
