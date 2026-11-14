<?php namespace KHZS; defined('ABSPATH') || exit; ?>
<style>
.tr-rij{display:flex;gap:.8rem;align-items:center;padding:.7rem 0;border-bottom:1px solid var(--lijn);flex-wrap:wrap}
.tr-rij:last-child{border-bottom:0}
.tr-datum{display:flex;flex-direction:column;align-items:center;justify-content:center;min-width:3.2rem;padding:.35rem .4rem;border-radius:10px;background:var(--bg);text-decoration:none;color:inherit;line-height:1.15}
.tr-datum b{text-transform:capitalize;color:var(--accent,#0e6b73)}.tr-datum span{font-size:.8rem;color:var(--zacht)}
.tr-info{flex:1;min-width:12rem;display:flex;flex-direction:column;gap:.1rem}
.tr-info a{text-decoration:none;color:inherit}
.tr-status{display:flex;gap:.4rem;align-items:center;flex-wrap:wrap;justify-content:flex-end}
.tr-afgelast .tr-info{opacity:.55}
.tr-kannie{position:relative}.tr-kannie summary{list-style:none;cursor:pointer}.tr-kannie summary::-webkit-details-marker{display:none}
.tr-kannie[open] form{margin-top:.4rem;width:100%}
.tr-dag{margin:1.2rem 0 .4rem;text-transform:capitalize}
.tr-week{display:flex;gap:.5rem;align-items:center;justify-content:space-between;flex-wrap:wrap}
.tr-cijfers{display:grid;grid-template-columns:repeat(auto-fit,minmax(5.5rem,1fr));gap:.5rem;margin-top:.4rem}
.tr-cijfers .klein{display:block;line-height:1.2}
.tr-getal{font-size:1.6rem;font-weight:800;color:var(--accent,#0e6b73);display:block}
.tr-nieuw .tr-info>span:first-child{font-weight:700}
.tr-bev-open .tr-datum{outline:2px solid var(--wacht);outline-offset:-2px}
.tr-merk{font-size:.75rem;margin-left:.15rem}.tr-merk.ja{color:var(--ok)}.tr-merk.nee{color:var(--fout)}.tr-merk.open{color:var(--wacht)}
.tr-k-balk{display:flex;flex-wrap:wrap;gap:.6rem 1rem;align-items:center;justify-content:space-between}
.tr-k-weergave,.tr-k-nav,.tr-k-filter{display:flex;gap:.35rem;align-items:center;flex-wrap:wrap}
.tr-k-titel{text-transform:capitalize;margin-left:.3rem}
.tr-k-filter select{width:auto;max-width:14rem}
.tr-k-blok{display:block;text-decoration:none;color:inherit;font-size:.85rem;line-height:1.3;padding:.3rem .45rem;margin:.25rem 0;border-radius:8px;border-left:4px solid var(--lijn);background:var(--bg)}
.tr-k-blok strong{font-weight:700}
.tr-k-blok.mijn{border-left-color:var(--accent,#0e6b73);background:color-mix(in srgb,var(--accent,#0e6b73) 12%,transparent)}
.tr-k-blok.st-open{outline:2px dashed var(--wacht);outline-offset:-2px}
.tr-k-blok.st-ja{border-left-color:var(--ok)}.tr-k-blok.st-nee{border-left-color:var(--fout);opacity:.75}
.tr-k-blok.afgemeld{border-left-color:var(--fout);text-decoration:line-through}
.tr-k-blok.gezocht{box-shadow:inset 0 0 0 2px var(--fout)}
.tr-k-blok.afgelast{opacity:.5;text-decoration:line-through}
.tr-k-legende .tr-k-blok{display:inline-block;margin:.15rem .1rem;font-size:.75rem}
.tr-k-uur{font-variant-numeric:tabular-nums;color:var(--zacht)}
.tr-k-klein{display:block;font-size:.78rem;color:var(--zacht)}
.tr-k-gezocht{display:block;font-size:.75rem;font-weight:700;color:var(--fout)}
.tr-k-week{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:.4rem;margin-top:.6rem}
.tr-k-kol{background:var(--kaart,#fff);border:1px solid var(--lijn);border-radius:12px;padding:.4rem;min-height:6rem}
.tr-k-kol.vandaag,.tr-k-cel.vandaag{border-color:var(--accent,#0e6b73);box-shadow:0 0 0 1px var(--accent,#0e6b73)}
.tr-k-kop,.tr-k-nr{display:block;text-decoration:none;color:inherit;text-transform:capitalize;font-size:.85rem;margin-bottom:.2rem}
.tr-k-kop b{color:var(--accent,#0e6b73)}
.tr-k-niets{color:var(--zacht);font-size:.8rem}
.tr-k-maand{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:.25rem;margin-top:.6rem}
.tr-k-wd{text-align:center;font-size:.75rem;font-weight:700;color:var(--zacht);text-transform:uppercase}
.tr-k-cel{background:var(--kaart,#fff);border:1px solid var(--lijn);border-radius:8px;padding:.25rem;min-height:5.5rem;overflow:hidden}
.tr-k-cel.ander{opacity:.45}
.tr-k-cel .tr-k-blok{font-size:.72rem;padding:.15rem .3rem;margin:.15rem 0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tr-k-nr{font-weight:700}
.tr-k-ploegen{margin:-.4rem 0 .4rem 4rem}
.tr-zw-knoppen{gap:.4rem;margin:.4rem 0 .6rem}
.tr-zw-ploeg{font-size:1rem;margin:.9rem 0 .3rem}
.tr-zw-lijst{display:grid;grid-template-columns:repeat(auto-fill,minmax(16rem,1fr));gap:.35rem}
.tr-zw{display:flex;align-items:center;gap:.6rem;min-height:2.8rem;padding:.35rem .6rem;border:1px solid var(--lijn);border-radius:10px;cursor:pointer;margin:0;font-weight:500}
.tr-zw input{width:1.35rem;height:1.35rem;margin:0;flex:none;accent-color:var(--ok)}
.tr-zw:has(input:checked){background:color-mix(in srgb,var(--ok) 12%,transparent);border-color:var(--ok)}
.tr-zw:has(input:not(:checked)) span{color:var(--zacht);text-decoration:line-through}
.tr-zw-bewaar{position:sticky;bottom:0;padding:.6rem 0 .2rem;background:var(--kaart,#fff);margin-top:.6rem}
.tr-zw-bewaar button{width:100%}
.tr-zw-telling{font-size:.78rem;white-space:nowrap}
/* Aanwezigheid van zwemmers: vijf soorten, eigen kleuren (gevalideerd op licht en donker; ziek bewust neutraal grijs). */
:root{--zw-aanwezig:#157a46;--zw-laat:#b07d00;--zw-vroeger:#2f6fd6;--zw-ziek:#98a6ad;--zw-afwezig:#b42318}
@media (prefers-color-scheme:dark){:root:not([data-theme="light"]){--zw-aanwezig:#2a9460;--zw-laat:#b8860f;--zw-vroeger:#5b8cf0;--zw-ziek:#6b7c84;--zw-afwezig:#dd5547}}
:root[data-theme="dark"]{--zw-aanwezig:#2a9460;--zw-laat:#b8860f;--zw-vroeger:#5b8cf0;--zw-ziek:#6b7c84;--zw-afwezig:#dd5547}
.st-aanwezig{--zw:var(--zw-aanwezig)}.st-laat{--zw:var(--zw-laat)}.st-vroeger{--zw:var(--zw-vroeger)}.st-ziek{--zw:var(--zw-ziek)}.st-afwezig{--zw:var(--zw-afwezig)}
.tr-zw-balk{display:flex;gap:2px;height:.7rem;margin:.5rem 0 .3rem;min-width:6rem}
.tr-zw-balk>span{background:var(--zw);min-width:3px}
.tr-zw-balk>span:first-child{border-radius:4px 0 0 4px}.tr-zw-balk>span:last-child{border-radius:0 4px 4px 0}.tr-zw-balk>span:only-child{border-radius:4px}
.tr-zw-balk.leeg{background:var(--lijn);border-radius:4px}
.tr-zw-legende{display:flex;flex-wrap:wrap;gap:.3rem .9rem;margin:.2rem 0 0}
.tr-zw-legende i,.tr-zw-tijdlijn i{display:inline-block;width:.75rem;height:.75rem;border-radius:3px;background:var(--zw);vertical-align:-.1rem;margin-right:.3rem}
.tr-zw-tabel td,.tr-zw-tabel th{vertical-align:middle}
.tr-zw-tabel .tr-zw-balkkol{min-width:7rem;width:30%}
.tr-zw-num{text-align:right;font-variant-numeric:tabular-nums}
.tr-zw-pct,.tr-zw-tabel td{white-space:nowrap}.tr-zw-tabel td:first-child{white-space:normal;min-width:7rem}
.tr-zw-pct.hoog{color:var(--ok)}.tr-zw-pct.midden{color:var(--wacht)}.tr-zw-pct.laag{color:var(--fout)}
.tr-zw-verdeling{display:grid;grid-template-columns:8.5rem 1fr 2rem;gap:.6rem;align-items:center;padding:.25rem 0}
.tr-zw-staaf{background:var(--bg);border-radius:4px;height:.8rem}.tr-zw-staaf i{display:block;height:100%;border-radius:4px;background:var(--accent,#0e6b73)}
.tr-zw-verdeling strong{text-align:right;font-variant-numeric:tabular-nums}
.tr-zw-tijdlijn{display:flex;flex-wrap:wrap;gap:3px}.tr-zw-tijdlijn i{margin:0;width:.9rem;height:.9rem}
.tr-zw-label{font-size:.8rem;font-weight:700;padding:.15rem .5rem;border-radius:999px;border:2px solid var(--zw)}
.tr-zw-rij{display:flex;gap:.3rem;align-items:stretch}.tr-zw-rij .tr-zw{flex:1;min-width:0}
.tr-zw-rij select{width:auto;max-width:7.5rem;margin:0;padding:.2rem .3rem;font-size:.8rem;border-radius:10px}
.tr-zw-rij select.gekozen{border:2px solid var(--zw);font-weight:700}
@media (max-width:760px){
  .tr-k-week{grid-template-columns:1fr}
  .tr-k-kol{min-height:0}.tr-k-kol.leeg{display:none}
  .tr-k-kop{font-size:1rem;font-weight:700}
  .tr-k-cel{min-height:3.4rem;padding:.2rem}
  .tr-k-cel .tr-k-blok{font-size:0;padding:0;height:.4rem;margin:.12rem 0;border-left-width:0;border-radius:3px;background:var(--accent,#0e6b73);opacity:.35}
  .tr-k-cel .tr-k-blok.mijn{opacity:1}.tr-k-cel .tr-k-blok.afgelast{background:var(--zacht);opacity:.3}
  .tr-k-cel .tr-k-blok.gezocht,.tr-k-cel .tr-k-blok.st-open{background:var(--fout);opacity:1;outline:0;box-shadow:none}
  .tr-k-filter select{max-width:100%;flex:1}
}
</style>
