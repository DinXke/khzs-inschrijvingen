<?php
/**
 * Web Push met VAPID (RFC 8292) en aes128gcm-versleuteling (RFC 8291), enkel met OpenSSL — geen externe bibliotheek.
 * De VAPID-sleutel wordt bij eerste gebruik aangemaakt en bewaard in de optie khzs_vapid_pem.
 */

namespace KHZS;

defined('ABSPATH') || exit;

const P256_SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

function b64url(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function b64url_dec(string $s): string
{
    return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
}

function vapid_pem(): string
{
    $pem = get_option('khzs_vapid_pem');
    if (!$pem) {
        $k = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        openssl_pkey_export($k, $pem);
        add_option('khzs_vapid_pem', $pem, '', false);
        $pem = get_option('khzs_vapid_pem');
    }
    return $pem;
}

/** Ongecomprimeerd EC-punt (65 bytes) van een sleutel. */
function ec_punt($sleutel): string
{
    $d = openssl_pkey_get_details($sleutel)['ec'];
    return "\x04" . str_pad($d['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['y'], 32, "\0", STR_PAD_LEFT);
}

function publieke_sleutel(): string
{
    return b64url(ec_punt(openssl_pkey_get_private(vapid_pem())));
}

function punt_naar_sleutel(string $punt)
{
    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode(hex2bin(P256_SPKI_PREFIX) . $punt), 64, "\n") . "-----END PUBLIC KEY-----\n";
    return openssl_pkey_get_public($pem);
}

/** DER-ECDSA-handtekening → r||s (64 bytes) zoals JWT ES256 verwacht. */
function der_naar_raw(string $der): string
{
    $pos = 2;
    if (ord($der[1]) & 0x80) {
        $pos += ord($der[1]) & 0x7f;
    }
    $uit = '';
    for ($i = 0; $i < 2; $i++) {
        $len = ord($der[$pos + 1]);
        $int = substr($der, $pos + 2, $len);
        $uit .= str_pad(ltrim($int, "\0"), 32, "\0", STR_PAD_LEFT);
        $pos += 2 + $len;
    }
    return $uit;
}

function vapid_header(string $endpoint): string
{
    $p = wp_parse_url($endpoint);
    $claims = ['aud' => $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : ''),
        'exp' => time() + 12 * 3600, 'sub' => 'mailto:' . get_option('admin_email')];
    $invoer = b64url(wp_json_encode(['typ' => 'JWT', 'alg' => 'ES256'])) . '.' . b64url(wp_json_encode($claims));
    openssl_sign($invoer, $der, openssl_pkey_get_private(vapid_pem()), OPENSSL_ALGO_SHA256);
    return 'vapid t=' . $invoer . '.' . b64url(der_naar_raw($der)) . ', k=' . publieke_sleutel();
}

/** Versleutelt een bericht voor één abonnement (aes128gcm, één record). */
function versleutel(string $tekst, string $p256dh, string $auth): string
{
    $ua_publiek = b64url_dec($p256dh);
    $auth_geheim = b64url_dec($auth);
    $eph = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $as_publiek = ec_punt($eph);
    $gedeeld = openssl_pkey_derive(punt_naar_sleutel($ua_publiek), $eph, 32);
    $ikm = hash_hkdf('sha256', $gedeeld, 32, "WebPush: info\0" . $ua_publiek . $as_publiek, $auth_geheim);
    $zout = random_bytes(16);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $zout);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $zout);
    $cijfer = openssl_encrypt($tekst . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    return $zout . pack('N', 4096) . chr(strlen($as_publiek)) . $as_publiek . $cijfer . $tag;
}

/** Testbaar: in tests vervangen via de filter khzs_push_verzend. */
function verzend_een(object $a, array $bericht): int
{
    $kort = apply_filters('khzs_push_verzend', null, $a, $bericht);
    if ($kort !== null) {
        return (int) $kort;
    }
    $resp = wp_remote_post($a->endpoint, [
        'timeout' => 10,
        'headers' => ['Content-Type' => 'application/octet-stream', 'Content-Encoding' => 'aes128gcm', 'TTL' => '86400',
            'Urgency' => 'normal', 'Authorization' => vapid_header($a->endpoint)],
        'body' => versleutel(wp_json_encode($bericht), $a->p256dh, $a->auth),
    ]);
    return is_wp_error($resp) ? 0 : (int) wp_remote_retrieve_response_code($resp);
}

function verstuur_abonnementen(array $ids, array $bericht): void
{
    if (!$ids) {
        return;
    }
    foreach (db()->get_results("SELECT * FROM " . t('push') . " WHERE id IN (" . in_lijst($ids) . ")") as $a) {
        $code = verzend_een($a, $bericht);
        if (in_array($code, [404, 410], true)) {
            db()->delete(t('push'), ['id' => $a->id]); // abonnement bestaat niet meer
        } elseif ($code < 200 || $code >= 300) {
            error_log("khzs push mislukt ($code) voor abonnement {$a->id}");
        }
    }
}

function push_achtergrond(array $ids, array $bericht): void
{
    verstuur_abonnementen($ids, $bericht);
}

/** Stuurt een melding naar alle toestellen van deze gebruikers. Geeft het aantal toestellen terug. */
function stuur(array $user_ids, string $titel, string $tekst, string $url = '', bool $achtergrond = true): int
{
    if (!$user_ids || is_demo()) {
        return 0;
    }
    $ids = array_map('intval', db()->get_col("SELECT id FROM " . t('push') . " WHERE user_id IN (" . in_lijst($user_ids) . ")"));
    if (!$ids) {
        return 0;
    }
    $bericht = ['titel' => $titel, 'tekst' => $tekst, 'url' => $url ?: app_url('')];
    if ($achtergrond && !apply_filters('khzs_push_synchroon', false)) {
        // Via WP-Cron, zodat de gebruiker niet hoeft te wachten.
        wp_schedule_single_event(time(), 'khzs_push', [$ids, $bericht + ['_' => wp_generate_uuid4()]]);
        spawn_cron();
    } else {
        verstuur_abonnementen($ids, $bericht);
    }
    return count($ids);
}

/**
 * Gebruikers met deze voorkeur aan en minstens één toestel. Met $deelname_aan: enkel accounts met een zwemmer
 * die voor die wedstrijd 'interesse' of 'ik doe mee' koos.
 */
function users_met(string $voorkeur, ?array $rollen = null, ?int $deelname_aan = null): array
{
    $sql = "SELECT DISTINCT p.user_id FROM " . t('push') . " p";
    if ($deelname_aan !== null) {
        // Alle accounts (ook gekoppelde ouders) van zwemmers met interesse of 'ik doe mee'.
        $zids = "SELECT zwemmer_id FROM " . t('deelnames') . db()->prepare(" WHERE wedstrijd_id=%d AND keuze IN ('interesse','doet_mee') AND geweigerd=0", $deelname_aan);
        $sql .= " WHERE p.user_id IN (SELECT account_id FROM " . t('zwemmers') . " WHERE id IN ($zids) UNION SELECT user_id FROM " . t('zwemmer_accounts') . " WHERE zwemmer_id IN ($zids))";
    }
    $uit = [];
    foreach (db()->get_col($sql) as $uid) {
        $u = get_userdata((int) $uid);
        if (!$u || !voorkeur($u->ID, $voorkeur) || ($rollen !== null && !heeft_rol($rollen, $u))) {
            continue;
        }
        $uit[] = $u->ID;
    }
    return $uit;
}
