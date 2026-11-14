<?php
/** Een zwemmer delen met een ander account (bv. gescheiden ouders) via een koppelcode. */

namespace KHZS;

defined('ABSPATH') || exit;

const KOPPEL_ALFABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // zonder O/0 en I/1
const KOPPEL_DAGEN = 7;
const KOPPEL_MAX_POGINGEN = 10; // foute codes per account (of IP) per uur

/** Account-ID's van een zwemmer: eerst het eerste account, dan de gekoppelde. */
function zwemmer_accounts(object $z): array
{
    $extra = array_map('intval', db()->get_col(db()->prepare("SELECT user_id FROM " . t('zwemmer_accounts') . " WHERE zwemmer_id=%d ORDER BY id", $z->id)));
    return array_values(array_unique(array_merge([(int) $z->account_id], $extra)));
}

function heeft_account(object $z, int $uid): bool
{
    return $uid > 0 && in_array($uid, zwemmer_accounts($z), true);
}

function koppel_normaliseer(string $code): string
{
    return preg_replace('/[^' . KOPPEL_ALFABET . ']/', '', strtoupper($code));
}

function koppel_toon(string $code): string
{
    return strlen($code) === 8 ? substr($code, 0, 4) . '-' . substr($code, 4) : $code;
}

/** Nieuwe code (de vorige vervalt), 7 dagen geldig en meermaals te gebruiken binnen die periode. */
function koppel_nieuwe_code(object $z): string
{
    do {
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= KOPPEL_ALFABET[random_int(0, strlen(KOPPEL_ALFABET) - 1)];
        }
    } while (db()->get_var(db()->prepare("SELECT id FROM " . t('zwemmers') . " WHERE koppelcode=%s", $code)));
    $tot = current_datetime()->modify('+' . KOPPEL_DAGEN . ' days')->format('Y-m-d H:i:s');
    db()->update(t('zwemmers'), ['koppelcode' => $code, 'koppelcode_tot' => $tot], ['id' => $z->id]);
    return $code;
}

function koppel_geldige_code(object $z): ?string
{
    return $z->koppelcode && $z->koppelcode_tot && $z->koppelcode_tot > nu_sql() ? $z->koppelcode : null;
}

/** Zwemmer bij een geldige code, of null. Gooit een fout na te veel foute pogingen (tegen raden). */
function koppel_zoek(string $code): ?object
{
    $wie = get_current_user_id() ?: md5((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    $sleutel = 'khzs_koppelpoging_' . $wie;
    $pogingen = (int) get_transient($sleutel);
    if ($pogingen >= KOPPEL_MAX_POGINGEN) {
        throw new \RuntimeException('Te veel foute codes. Probeer het over een uur opnieuw.');
    }
    $code = koppel_normaliseer($code);
    $z = strlen($code) === 8 ? db()->get_row(db()->prepare("SELECT * FROM " . t('zwemmers') . " WHERE koppelcode=%s AND koppelcode_tot>%s", $code, nu_sql())) : null;
    if (!$z) {
        set_transient($sleutel, $pogingen + 1, HOUR_IN_SECONDS);
    }
    return $z ?: null;
}

/** Koppelt een account aan de zwemmer. False als het al gekoppeld was. */
function koppel(object $z, int $uid): bool
{
    if (heeft_account($z, $uid)) {
        return false;
    }
    db()->insert(t('zwemmer_accounts'), ['zwemmer_id' => $z->id, 'user_id' => $uid, 'aangemaakt' => nu_sql()]);
    return true;
}

/** Haalt een account weg; is het het eerste account, dan neemt een gekoppeld account over. Het laatste kan niet weg. */
function ontkoppel(object $z, int $uid): void
{
    $accounts = zwemmer_accounts($z);
    if (count($accounts) < 2) {
        throw new \RuntimeException('Dit is het enige account van deze zwemmer.');
    }
    if ((int) $z->account_id === $uid) {
        $nieuw = $accounts[1];
        db()->update(t('zwemmers'), ['account_id' => $nieuw], ['id' => $z->id]);
        db()->delete(t('zwemmer_accounts'), ['zwemmer_id' => $z->id, 'user_id' => $nieuw]);
    } else {
        db()->delete(t('zwemmer_accounts'), ['zwemmer_id' => $z->id, 'user_id' => $uid]);
    }
}
