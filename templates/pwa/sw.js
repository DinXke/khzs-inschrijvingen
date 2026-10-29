{% load static %}// Service worker: statische bestanden uit cache, pagina's altijd vers van het net (inschrijvingen moeten actueel zijn).
const CACHE = "hzs-v3";
const STATISCH = ["{% static 'app.css' %}", "{% static 'img/icon-192.png' %}", "{% static 'img/logo.png' %}", "{% url 'offline' %}"];

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

  if (url.pathname.startsWith("/static/")) {
    // Stale-while-revalidate: snel uit cache, op de achtergrond vernieuwen.
    e.respondWith(caches.open(CACHE).then(c => c.match(req).then(hit => {
      const vers = fetch(req).then(res => { if (res.ok) c.put(req, res.clone()); return res; }).catch(() => hit);
      return hit || vers;
    })));
    return;
  }
  if (req.mode === "navigate") {
    e.respondWith(fetch(req).catch(() => caches.match("{% url 'offline' %}")));
  }
});

self.addEventListener("push", e => {
  let d = {};
  try { d = e.data.json(); } catch (_) { d = {titel: "HZS inschrijvingen", tekst: e.data ? e.data.text() : ""}; }
  e.waitUntil(self.registration.showNotification(d.titel || "HZS inschrijvingen", {
    body: d.tekst || "", icon: "{% static 'img/icon-192.png' %}", badge: "{% static 'img/favicon-32.png' %}",
    data: {url: d.url || "/"}, lang: "nl-BE",
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
