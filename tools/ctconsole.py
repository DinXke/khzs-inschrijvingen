"""Commando uitvoeren in de console van LXC 137 via de Proxmox API (termproxy + vncwebsocket).
Gebruik: ctconsole.py 'commando'   (leest ~/.config/khzs/proxmox.env en ct137.env)"""
import json
import os
import re
import ssl
import sys
import time
import urllib.parse
import urllib.request

import websocket


def env(pad):
    d = {}
    for regel in open(os.path.expanduser(pad)):
        if "=" in regel:
            k, v = regel.strip().split("=", 1)
            d[k] = v
    return d


pve, ct = env("~/.config/khzs/proxmox.env"), env("~/.config/khzs/ct137.env")
BASIS = f"https://{pve['PVE_HOST']}:8006/api2/json/nodes/pve01/lxc/137"
AUTH = f"PVEAPIToken={pve['PVE_TOKEN_ID']}={pve['PVE_TOKEN_SECRET']}"
CTX = ssl._create_unverified_context()

req = urllib.request.Request(BASIS + "/termproxy", method="POST", headers={"Authorization": AUTH})
d = json.load(urllib.request.urlopen(req, context=CTX))["data"]
url = (BASIS.replace("https://", "wss://") + f"/vncwebsocket?port={d['port']}&vncticket="
       + urllib.parse.quote(d["ticket"], safe=""))
ws = websocket.create_connection(url, header=[f"Authorization: {AUTH}"], sslopt={"cert_reqs": ssl.CERT_NONE},
                                 timeout=3)
ws.send(f"{d['user']}:{d['ticket']}\n")

buf = ""


def lees(sec):
    global buf
    eind = time.time() + sec
    while time.time() < eind:
        try:
            m = ws.recv()
        except websocket.WebSocketTimeoutException:
            continue
        buf += m.decode(errors="replace") if isinstance(m, bytes) else m
    return buf


def stuur(tekst):
    data = tekst.encode()
    ws.send(f"0:{len(data)}:{tekst}")


ws.send("1:200:50:")
lees(1)
stuur("\r")
lees(2)
if "login:" in buf.lower():
    stuur("root\r"); lees(1.5)
    stuur(ct["CT_ROOT_PW"] + "\r"); lees(2)
merk = "__KLAAR__"
buf = ""
stuur(f"{sys.argv[1]}; echo {merk}\r")
eind = time.time() + float(os.environ.get("WACHT", "60"))
# Wachten op de merker op een eigen regel (de echo van het commando kan over regels heen lopen).
while not re.search(r"\n" + merk + r"\r?\n", buf) and time.time() < eind:
    lees(1)
uit = re.sub(r"\x1b\[[0-9;?]*[A-Za-z]|\x1b[()][A-Z0-9]|\r", "", buf)
print(uit[-int(os.environ.get("MAX", "6000")):])
ws.close()
