<?php namespace KHZS; defined('ABSPATH') || exit; ?>
<style>
main.com-main{max-width:820px}
.com-sectie{margin:1.4rem 0 .5rem;font-size:.8rem;letter-spacing:.06em;text-transform:uppercase;font-style:normal;color:var(--zacht)}
.com-lijst{padding:4px 0}
.com-rij{display:flex;gap:.7rem;align-items:center;padding:.65rem 1rem;color:inherit;text-decoration:none;border-bottom:1px solid var(--lijn)}
.com-lijst .com-rij:last-child{border-bottom:0}
.com-rij:hover{background:color-mix(in srgb,var(--accent) 8%,transparent)}
.com-rij.com-thread{padding-left:2.4rem}
.com-icoon{width:1.6rem;flex:none;text-align:center;color:var(--zacht);font-weight:700}
.com-rij-info{flex:1;min-width:0;display:flex;flex-direction:column}
.com-rij-info .klein{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.com-rij-rechts{display:flex;flex-direction:column;align-items:flex-end;gap:2px;flex:none}
.com-rij.com-nieuw b{font-weight:800}
.com-teller{background:var(--primair);color:var(--primair-tekst);border-radius:999px;min-width:22px;padding:0 6px;font-size:.75rem;font-weight:700;text-align:center;line-height:20px}
.com-teller.stil{background:var(--lijn);color:var(--zacht)}
.com-meer{display:block;padding:.4rem 1rem .5rem 2.4rem;font-size:.85rem}
/* Aankondigingen */
.com-aank{border-left:5px solid var(--primair)}
.com-aank-dringend{border-left-color:var(--fout);background:color-mix(in srgb,var(--fout) 6%,var(--kaart))}
.com-aank-aankondiging{border-left-color:var(--accent)}
.com-aank-hulp{border-left-color:var(--ok)}
.com-aank h3{margin:.3rem 0 .4rem;font-size:1.1rem}.com-aank h3 a{color:inherit;text-decoration:none}
.com-aank-soort{font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--zacht)}
.com-aank-dringend .com-aank-soort{color:var(--fout)}
.com-aank-tekst{overflow-wrap:anywhere}
.com-aank.afgelopen{opacity:.7}
.com-voor-wie{border:0;padding:0;margin:0 0 1rem}.com-voor-wie legend{font-weight:700;padding:0;margin-bottom:.3rem}
.com-vinkjes{max-height:15rem;overflow:auto;border:1px solid var(--lijn);border-radius:10px;padding:.2rem .7rem}
.com-vinkjes label{display:flex;gap:.5rem;align-items:center;font-weight:400;margin:0;padding:.45rem 0;border-bottom:1px solid var(--lijn)}
.com-vinkjes label:last-child{border-bottom:0}
.com-rsvp{margin-top:.8rem;padding-top:.6rem;border-top:1px dashed var(--lijn)}
.com-rsvp .segment button.gekozen.r-ja{background:var(--ok)}
.com-rsvp .segment button.gekozen.r-misschien{background:var(--accent);color:#1c1200}
.com-rsvp .segment button.gekozen.r-nee{background:var(--zacht)}
.com-rsvp input[type=number]{width:5.5rem}
.com-rsvp .rij{margin-top:.5rem}
.com-rsvp .rij label{margin:0;font-weight:400;display:flex;gap:.4rem;align-items:center}
.com-rsvp .rij input[type=text]{flex:1;min-width:12rem}
/* Kanaal */
.com-pad{margin-bottom:.2rem}.com-pad a{text-decoration:none}
.com-kop h1{margin:.1rem 0 .2rem;overflow-wrap:anywhere}
.com-kop form{display:inline}
.com-vast summary{cursor:pointer;font-weight:700}
.com-vast-item{display:block;padding:.45rem 0;border-bottom:1px solid var(--lijn);color:inherit;text-decoration:none}
.com-vast-item:last-child{border-bottom:0}
.com-threads{display:flex;gap:6px;overflow-x:auto;padding:2px 0 10px;align-items:center}
.com-threads>a{flex:none;border:1px solid var(--lijn);background:var(--kaart);border-radius:999px;padding:.3rem .8rem;text-decoration:none;color:var(--tekst);font-size:.88rem}
.com-threads>a .com-teller{display:inline-block;margin-left:.3rem}
.com-thread-nieuw summary{cursor:pointer;list-style:none;display:inline-block;border:1px dashed var(--primair);color:var(--primair);border-radius:999px;padding:.3rem .8rem;font-size:.88rem;font-weight:600}
.com-thread-nieuw summary::-webkit-details-marker{display:none}
.com-chat{display:flex;flex-direction:column;gap:2px;padding-bottom:.5rem}
.com-dag{align-self:center;margin:.9rem 0 .4rem;font-size:.78rem;color:var(--zacht);background:var(--kaart);border:1px solid var(--lijn);border-radius:999px;padding:.1rem .7rem}
.com-b{display:flex;gap:.5rem;align-items:flex-end;max-width:88%;position:relative;margin-top:.45rem}
.com-b.com-vervolg{margin-top:0}
.com-b.com-eigen{align-self:flex-end;flex-direction:row-reverse}
.com-av{width:30px;height:30px;border-radius:50%;flex:none;display:flex;align-items:center;justify-content:center;font-size:.72rem;font-weight:700;background:color-mix(in srgb,var(--primair) 18%,var(--kaart));color:var(--primair)}
.com-vervolg .com-av{visibility:hidden;height:0}
.com-eigen .com-av{display:none}
.com-ballon{background:var(--kaart);border:1px solid var(--lijn);border-radius:16px 16px 16px 4px;padding:.45rem .75rem .3rem;min-width:4rem;overflow-wrap:anywhere;box-shadow:var(--schaduw)}
.com-eigen .com-ballon{background:color-mix(in srgb,var(--primair) 14%,var(--kaart));border-color:transparent;border-radius:16px 16px 4px 16px}
.com-naam{font-size:.8rem;font-weight:700;color:var(--primair);margin-bottom:.1rem}
.com-naam .com-rol{font-weight:600;color:var(--zacht);margin-left:.3rem}
.com-tijd{display:block;text-align:right;font-size:.7rem;color:var(--zacht);margin-top:.1rem}
.com-weg{color:var(--zacht);font-style:italic}
.com-foto{display:block;max-width:100%;max-height:340px;border-radius:10px;margin:.3rem 0}
.com-pdf{display:inline-block;margin:.3rem 0;font-weight:600}
.com-vermeld{background:color-mix(in srgb,var(--accent) 25%,transparent);border-radius:4px;padding:0 2px;font-weight:600}
.com-b.com-mij .com-ballon{box-shadow:0 0 0 2px var(--accent)}
.com-menu{align-self:center}
.com-menu summary{list-style:none;cursor:pointer;color:var(--zacht);padding:.2rem .4rem;font-size:1.2rem;line-height:1}
.com-menu summary::-webkit-details-marker{display:none}
.com-menu-blad{position:absolute;z-index:5;top:100%;left:2.4rem;background:var(--kaart);border:1px solid var(--lijn);border-radius:12px;padding:8px;box-shadow:0 8px 24px rgba(0,0,0,.18);display:flex;flex-direction:column;gap:6px;width:min(280px,80vw)}
.com-eigen .com-menu-blad{left:auto;right:0}
.com-menu textarea{min-height:70px}
.com-b:target .com-ballon,.com-flits .com-ballon{animation:com-flits 2s}
@keyframes com-flits{0%{box-shadow:0 0 0 3px var(--accent)}100%{box-shadow:var(--schaduw)}}
.com-schrijf{position:sticky;bottom:calc(var(--nav-h) + env(safe-area-inset-bottom) + 6px);z-index:4;background:var(--kaart);border:1px solid var(--lijn);border-radius:22px;padding:6px;box-shadow:0 6px 24px rgba(0,0,0,.12);margin-top:.5rem}
@media (min-width:900px){.com-schrijf{bottom:10px}}
.com-schrijf-rij{display:flex;gap:6px;align-items:flex-end}
.com-schrijf textarea{flex:1;min-height:40px;max-height:160px;resize:none;border:0;background:transparent;padding:.5rem .6rem}
.com-schrijf textarea:focus{outline:0}
.com-schrijf button{min-height:40px;width:40px;padding:0;border-radius:50%;flex:none}
.com-bijlage-knop{flex:none;display:flex;align-items:center;justify-content:center;width:40px;height:40px;border-radius:50%;cursor:pointer;margin:0;font-size:1.2rem;color:var(--zacht)}
.com-bijlage-knop input{display:none}
.com-bijlage-naam{font-size:.75rem;color:var(--zacht);padding:0 .6rem}
.com-alleen-lezen{text-align:center;padding:.8rem;color:var(--zacht);font-size:.9rem}
.com-suggesties{position:absolute;bottom:100%;left:8px;right:8px;margin-bottom:6px;background:var(--kaart);border:1px solid var(--lijn);border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,.15);max-height:220px;overflow:auto}
.com-suggesties button{display:block;width:100%;text-align:left;background:none;color:var(--tekst);border:0;border-radius:0;min-height:40px;font-weight:500;padding:0 1rem}
.com-suggesties button:hover,.com-suggesties button.actief{background:color-mix(in srgb,var(--accent) 14%,transparent)}
.com-fout{color:var(--fout);font-size:.85rem;margin:.3rem .5rem 0}
</style>
