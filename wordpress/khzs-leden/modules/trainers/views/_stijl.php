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
.tr-cijfers{display:grid;grid-template-columns:repeat(3,1fr);gap:.5rem;margin-top:.4rem}
.tr-cijfers .klein{display:block;line-height:1.2}
.tr-getal{font-size:1.6rem;font-weight:800;color:var(--accent,#0e6b73);display:block}
</style>
