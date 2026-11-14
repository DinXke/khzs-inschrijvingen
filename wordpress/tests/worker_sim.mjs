// Draait een Cloudflare Worker lokaal met Node (18+), voor de tests van het eigen domein (wordpress/leden-domein/).
//   node wordpress/tests/worker_sim.mjs <worker.js> <poort> <BRON>
//   bv. node wordpress/tests/worker_sim.mjs wordpress/leden-domein/worker.js 8098 http://localhost:8080/leden
// Het domein van de app is dan http://127.0.0.1:<poort> (een andere host dan localhost, dus ook andere cookies).
import http from "node:http";
import path from "node:path";
import { pathToFileURL } from "node:url";

const [bestand, poort, BRON] = process.argv.slice(2);
const worker = (await import(pathToFileURL(path.resolve(bestand)).href)).default;
const WEG = new Set(["host", "connection", "content-length", "transfer-encoding", "keep-alive"]);

http
  .createServer(async (req, res) => {
    try {
      const delen = [];
      for await (const d of req) delen.push(d);
      const koppen = new Headers();
      for (const [k, v] of Object.entries(req.headers)) {
        if (!WEG.has(k)) koppen.set(k, Array.isArray(v) ? v.join(", ") : v);
      }
      const verzoek = new Request(`http://${req.headers.host}${req.url}`, {
        method: req.method,
        headers: koppen,
        body: ["GET", "HEAD"].includes(req.method) ? undefined : Buffer.concat(delen),
      });
      const a = await worker.fetch(verzoek, { BRON });
      const uit = {};
      a.headers.forEach((v, k) => {
        // Node pakt gzip al uit; set-cookie apart (meerdere).
        if (!["content-encoding", "content-length", "transfer-encoding", "set-cookie", "connection"].includes(k)) uit[k] = v;
      });
      const cookies = a.headers.getSetCookie();
      if (cookies.length) uit["set-cookie"] = cookies;
      res.writeHead(a.status, uit);
      res.end(Buffer.from(await a.arrayBuffer()));
    } catch (e) {
      res.writeHead(502, { "Content-Type": "text/plain" });
      res.end(String(e && e.stack));
    }
  })
  .listen(Number(poort), "0.0.0.0", () => console.log(`Worker op http://127.0.0.1:${poort} → ${BRON}`));
