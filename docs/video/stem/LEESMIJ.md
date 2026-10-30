# Voice-over uit een stemgenerator (bv. Artlist)

1. `.venv/bin/python docs/video.py --script` maakt `docs/video/script/<rol>.txt`, met één alinea per stap.
2. Plak de volledige tekst in je stemgenerator. Laat de lege regels tussen de alinea's staan: zo komt er tussen de stappen een korte pauze.
3. Bewaar de audio hier als `zwemmer.mp3`, `trainer.mp3` en `sportsecretaris.mp3`. Te lang voor één keer? Gebruik dan delen, bv. `zwemmer-1.mp3` en `zwemmer-2.mp3`. Ook `.wav` en `.m4a` werken.
4. `.venv/bin/python docs/video.py` knipt de opname per stap (Whisper, lokaal) en maakt de filmpjes.

Verander je later een tekst in `docs/video.py`, dan gebruikt die ene stap de macOS-stem tot je de opname opnieuw maakt. Het script meldt welke stappen niet gevonden werden.
