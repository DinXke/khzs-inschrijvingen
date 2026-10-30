"""Voice-over uit één lange opname (bv. Artlist) per stap knippen met Whisper-woordtijden.

Werkwijze:
  1. .venv/bin/python docs/video.py --script          → docs/video/script/<rol>.txt (één alinea per stap)
  2. De volledige tekst in Artlist (of een andere stemgenerator) plakken, één audiobestand per filmpje
     bewaren als docs/video/stem/<rol>.mp3 (of .wav/.m4a)
  3. .venv/bin/python docs/video.py                    → gebruikt automatisch die opname i.p.v. de macOS-stem

Whisper (faster-whisper, lokaal) zet de opname om naar woorden met tijdstempels. Die worden met difflib uitgelijnd
op de scripttekst, zodat we per stap begin en einde kennen. Stappen die niet in de opname te vinden zijn
(bv. omdat de tekst sindsdien veranderd is) vallen terug op de standaardstem (Piper nathalie).
"""
import json
import re
import subprocess
import unicodedata
from difflib import SequenceMatcher
from pathlib import Path

import imageio_ffmpeg

FFMPEG = imageio_ffmpeg.get_ffmpeg_exe()
STEMMAP = Path(__file__).resolve().parent / "video" / "stem"
MODEL = "small"  # 'medium' is nauwkeuriger maar trager


def woorden(tekst):
    t = unicodedata.normalize("NFKD", tekst.lower())
    t = "".join(c for c in t if not unicodedata.combining(c))
    return re.findall(r"[a-z0-9]+", t)


AUDIO = ("mp3", "wav", "m4a", "aac", "flac")


def opname_voor(rol):
    """<rol>.mp3, of meerdere delen <rol>-1.mp3, <rol>-2.mp3 … (worden samengevoegd)."""
    for ext in AUDIO:
        p = STEMMAP / f"{rol}.{ext}"
        if p.exists():
            return p
    delen = sorted((p for p in STEMMAP.glob(f"{rol}-*") if p.suffix[1:] in AUDIO),
                   key=lambda p: int(re.sub(r"\D", "", p.stem.rsplit("-", 1)[-1]) or 0))
    if not delen:
        return None
    samen = STEMMAP / f".{rol}-samengevoegd.wav"
    if not samen.exists() or samen.stat().st_mtime < max(d.stat().st_mtime for d in delen):
        lijst = STEMMAP / f".{rol}-delen.txt"
        lijst.write_text("".join(f"file '{d.resolve()}'\n" for d in delen))
        subprocess.run([FFMPEG, "-loglevel", "error", "-y", "-f", "concat", "-safe", "0", "-i", str(lijst),
                        "-ac", "1", "-ar", "44100", str(samen)], check=True)
    return samen


def transcribeer(audio):
    """Woorden met tijden; gecachet naast de opname (opnieuw berekend als de opname wijzigt)."""
    cache = audio.with_suffix(".woorden.json")
    if cache.exists() and cache.stat().st_mtime >= audio.stat().st_mtime:
        return json.loads(cache.read_text())
    from faster_whisper import WhisperModel

    import numpy as np

    # Zelf decoderen met ffmpeg (16 kHz mono): onafhankelijk van de PyAV-versie.
    pcm = subprocess.run([FFMPEG, "-loglevel", "error", "-i", str(audio), "-ac", "1", "-ar", "16000", "-f", "s16le", "-"],
                         capture_output=True, check=True).stdout
    golf = np.frombuffer(pcm, np.int16).astype(np.float32) / 32768.0
    model = WhisperModel(MODEL, device="cpu", compute_type="int8")
    segmenten, _ = model.transcribe(golf, language="nl", word_timestamps=True, vad_filter=False)
    lijst = [{"w": w.word, "s": round(w.start, 3), "e": round(w.end, 3)} for seg in segmenten for w in seg.words]
    cache.write_text(json.dumps(lijst, ensure_ascii=False))
    return lijst


def lijn_uit(teksten, gesproken):
    """Geeft per stap (start, einde) in seconden, of None als de stap niet gevonden werd."""
    script, stap_van = [], []
    for i, t in enumerate(teksten):
        for w in woorden(t):
            script.append(w)
            stap_van.append(i)
    trans, tijden = [], []
    for g in gesproken:
        for w in woorden(g["w"]):
            trans.append(w)
            tijden.append((g["s"], g["e"]))
    koppeling = {}
    for blok in SequenceMatcher(None, script, trans, autojunk=False).get_matching_blocks():
        for k in range(blok.size):
            koppeling[blok.a + k] = blok.b + k
    resultaat = []
    for i, t in enumerate(teksten):
        idx = [j for j, s in enumerate(stap_van) if s == i]
        gevonden = [koppeling[j] for j in idx if j in koppeling]
        if not idx or len(gevonden) / len(idx) < 0.5:
            resultaat.append(None)
            continue
        resultaat.append((tijden[min(gevonden)][0], tijden[max(gevonden)][1]))
    return resultaat


def knip(rol, teksten, uitmap):
    """Knipt de opname van deze rol per stap. Geeft een lijst met (pad, duur) of None per stap."""
    audio = opname_voor(rol)
    if not audio:
        return None
    gesproken = transcribeer(audio)
    grenzen = lijn_uit(teksten, gesproken)
    uitmap = Path(uitmap)
    uitmap.mkdir(parents=True, exist_ok=True)
    stukken = []
    for i, g in enumerate(grenzen):
        if g is None:
            stukken.append(None)
            continue
        vorige = next((grenzen[k][1] for k in range(i - 1, -1, -1) if grenzen[k]), 0.0)
        volgende = next((grenzen[k][0] for k in range(i + 1, len(grenzen)) if grenzen[k]), g[1] + 1.0)
        begin = max(vorige, g[0] - 0.12)
        einde = min(volgende, g[1] + 0.30)
        pad = uitmap / f"{rol}-{i:02d}.wav"
        subprocess.run([FFMPEG, "-loglevel", "error", "-y", "-ss", f"{begin:.3f}", "-to", f"{einde:.3f}",
                        "-i", str(audio), "-ac", "1", "-ar", "44100", "-af", "afade=t=in:d=0.03,areverse,afade=t=in:d=0.06,areverse",
                        str(pad)], check=True)
        stukken.append((pad, einde - begin))
    gemist = sum(1 for s in stukken if s is None)
    print(f"    {rol}: {len(stukken) - gemist}/{len(stukken)} stappen uit {audio.name}"
          + (f", {gemist} met de standaardstem (tekst niet gevonden)" if gemist else ""))
    return stukken
