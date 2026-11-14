<?php namespace KHZS; defined('ABSPATH') || exit;
$statisch = [asset('app.css'), asset('img/icon-192.png'), asset('img/logo.png'), app_url('offline/')];
?>// Service worker: statische bestanden uit cache, pagina's altijd vers van het net (inschrijvingen moeten actueel zijn).
const CACHE = "hzs-wp-<?= esc_js(VERSIE) ?>";
const STATISCH = <?= wp_json_encode($statisch, JSON_UNESCAPED_SLASHES) ?>;
const ASSETS = <?= wp_json_encode(wp_parse_url(KHZS_URL . 'assets/', PHP_URL_PATH), JSON_UNESCAPED_SLASHES) ?>;
const OFFLINE = <?= wp_json_encode(app_url('offline/'), JSON_UNESCAPED_SLASHES) ?>;
const ICOON = <?= wp_json_encode(asset('img/icon-192.png'), JSON_UNESCAPED_SLASHES) ?>;
const BADGE = <?= wp_json_encode(asset('img/favicon-32.png'), JSON_UNESCAPED_SLASHES) ?>;
const START = <?= wp_json_encode(app_url(''), JSON_UNESCAPED_SLASHES) ?>;

self.addEventListener("install", e => {
  e.waitUntil(caches.open(CACHE).then(c => c.addAll(STATISCH)).then(() => self.skipWaiting()));
});

self.addEventListener("activate", e => {
  e.waitUntil(caches.keys()
    .then(keys => Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k))))
    .then(() => self.clients.claim()));
});

self.addEventListener("fetch", e => {
  const req = e.request;
  if (req.method !== "GET") return;
  const url = new URL(req.url);
  if (url.origin !== location.origin) return;

  if (url.pathname.startsWith(ASSETS)) {
    // Stale-while-revalidate: snel uit cache, op de achtergrond vernieuwen.
    e.respondWith(caches.open(CACHE).then(c => c.match(req).then(hit => {
      const vers = fetch(req).then(res => { if (res.ok) c.put(req, res.clone()); return res; }).catch(() => hit);
      return hit || vers;
    })));
    return;
  }
  if (req.mode === "navigate") {
    e.respondWith(fetch(req).catch(() => caches.match(OFFLINE)));
  }
});

self.addEventListener("push", e => {
  let d = {};
  try { d = e.data.json(); } catch (_) { d = {titel: <?= wp_json_encode(app_naam()) ?>, tekst: e.data ? e.data.text() : ""}; }
  e.waitUntil(self.registration.showNotification(d.titel || <?= wp_json_encode(app_naam()) ?>, {
    body: d.tekst || "", icon: ICOON, badge: BADGE, data: {url: d.url || START}, lang: "nl-BE",
  }));
});

self.addEventListener("notificationclick", e => {
  e.notification.close();
  const url = new URL(e.notification.data.url, location.origin).href;
  e.waitUntil(clients.matchAll({type: "window", includeUncontrolled: true}).then(lijst => {
    for (const c of lijst) if (c.url === url && "focus" in c) return c.focus();
    return clients.openWindow(url);
  }));
});
