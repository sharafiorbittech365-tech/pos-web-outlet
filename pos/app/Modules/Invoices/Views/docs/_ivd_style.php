<?php
/**
 * The printed invoice (A4 / A5) — one structure, three designs: .ivd-classic, .ivd-modern,
 * .ivd-compact. Black-and-white printer friendly: the accent colour (--ia) only tints rules and
 * titles. The invoice editor's live document uses the same classes, so it looks like the print.
 * Multi-page: the table header repeats, rows never split, and pages are numbered "Page x of y".
 */
?>
<style>
.ivd{--ia:#1E6FE8;--ink:#111827;--mute:#4b5563;--soft:#6b7280;--rule:#d1d5db;--tint:color-mix(in srgb,var(--ia) 7%,#fff);position:relative;color:var(--ink);background:#fff;font:11.5px/1.35 Inter,'Hind Siliguri',system-ui,sans-serif;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.a4.ivd{padding:22px 26px;max-width:860px}
.ivd > *{position:relative;z-index:1}
.ivd > .inv-wm{z-index:0}
.ivd-band{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;padding-bottom:8px;margin-bottom:8px;border-bottom:2px solid var(--ink)}
.ivd-brand{display:flex;gap:9px;align-items:flex-start;min-width:0;flex:1}
.ivd-logo{width:38px;height:38px;border-radius:8px;border:1px solid var(--rule);display:flex;align-items:center;justify-content:center;font-size:19px;color:var(--ia);flex:none}
.ivd-brand h1{margin:0;font-size:16px;line-height:1.2;letter-spacing:-.01em}
.ivd-brand .reg{font-size:10px;color:var(--soft)}
.ivd-brand p{margin:2px 0 0;color:var(--mute);font-size:10.5px;line-height:1.4}
.ivd-brand .bin{display:inline-block;margin-top:3px;padding:0 6px;border:1px solid var(--ink);border-radius:4px;font-weight:700;font-size:10px;letter-spacing:.02em}
.ivd-doc{text-align:right;min-width:190px}
.ivd-doc h2{margin:0 0 3px;font-size:19px;letter-spacing:.12em;text-transform:uppercase;color:var(--ia);line-height:1.1}
.ivd-doc table{margin-left:auto;border-collapse:collapse;font-size:10.5px}
.ivd-doc td{padding:.5px 0 .5px 12px;text-align:right;white-space:nowrap}
.ivd-doc td:first-child{color:var(--soft)}
.ivd-doc .no{font-weight:700;font-size:12px}
.ivd-parties{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:6px 16px;margin-bottom:8px}
.ivd-parties > div{display:flex;flex-direction:column;gap:1px;min-width:0;overflow-wrap:anywhere}
.ivd-lbl{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--soft);margin-bottom:1px}
.ivd-parties b{font-size:12.5px}
.ivd-parties span{color:var(--mute);font-size:10.5px}
.ivd-items{width:100%;border-collapse:collapse;margin:2px 0 8px}
.ivd-items thead{display:table-header-group}
.ivd-items tfoot{display:table-row-group}
.ivd-items th{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--ink);text-align:left;padding:4px 5px;border-top:1.5px solid var(--ink);border-bottom:1.5px solid var(--ink);vertical-align:bottom}
.ivd-items td{padding:3.5px 5px;border-bottom:1px solid var(--rule);vertical-align:top}
.ivd-items tr{break-inside:avoid;page-break-inside:avoid}
.ivd-items .num{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}
.ivd-items td small{display:block;color:var(--soft);font-size:9.5px;line-height:1.3}
.ivd-items tfoot td{font-weight:700;border-bottom:1.5px solid var(--ink);border-top:1px solid var(--ink)}
.ivd-sum{display:grid;grid-template-columns:1fr minmax(210px,260px);gap:14px;align-items:start;break-inside:avoid}
.ivd-left{display:flex;flex-direction:column;gap:6px;min-width:0}
.ivd-words b{display:block;font-size:11px}
.ivd-vat{border-collapse:collapse;font-size:10px;width:100%;max-width:360px}
.ivd-vat th,.ivd-vat td{padding:2px 5px;border-bottom:1px solid var(--rule);text-align:left}
.ivd-vat th{font-size:9.5px;text-transform:uppercase;letter-spacing:.05em;color:var(--soft)}
.ivd-vat .num{text-align:right}
.ivd-pays div{font-size:10px;color:var(--mute)}
.ivd-tot{width:100%;border-collapse:collapse;font-variant-numeric:tabular-nums}
.ivd-tot td{padding:2px 0}
.ivd-tot td:last-child{text-align:right;font-weight:600}
.ivd-tot td:first-child{color:var(--mute)}
.ivd-tot .gt td{border-top:2px solid var(--ink);border-bottom:2px solid var(--ink);padding:4px 0;font-size:13.5px;font-weight:800;color:var(--ink)}
.ivd-tot .ivd-due td{font-weight:800}
.ivd-stamp{display:inline-block;align-self:flex-start;border:2px solid currentColor;color:var(--ink);padding:2px 10px;border-radius:4px;font-weight:800;letter-spacing:.14em;font-size:11px;transform:rotate(-3deg)}
.ivd-text{margin:6px 0 0;font-size:10.5px;color:var(--mute);break-inside:avoid}
.ivd-text b{color:var(--ink)}
.ivd-nbr{margin-top:8px;padding:4px 8px;border:1px solid var(--rule);border-radius:4px;font-size:10.5px;color:var(--mute);break-inside:avoid}
.ivd-thanks{margin:8px 0 0;text-align:center;font-weight:600;color:var(--ia)}
.ivd-foot{margin-top:8px;padding-top:4px;border-top:1px solid var(--rule);display:flex;justify-content:space-between;gap:10px;font-size:9.5px;color:var(--soft)}
/* modern: tinted band, accent rule, zebra rows */
.ivd-modern .ivd-band{background:var(--tint);border-bottom:3px solid var(--ia);margin:-22px -26px 10px;padding:16px 26px 9px}
.ivd-modern .ivd-items th{border-top:0;border-bottom:2px solid var(--ia);background:var(--tint)}
.ivd-modern .ivd-items tbody tr:nth-child(even) td{background:#fafafa}
.ivd-modern .ivd-tot .gt td{border-color:var(--ia);color:var(--ia)}
.ivd-modern .ivd-parties > div{padding:5px 8px;border-left:3px solid var(--ia);background:#fcfcfd}
/* classic: formal, boxed */
.ivd-classic{font-family:Georgia,'Times New Roman','Hind Siliguri',serif}
.ivd-classic .ivd-doc h2{color:var(--ink);font-size:22px;letter-spacing:.2em;border-bottom:1px solid var(--ink);padding-bottom:4px}
.ivd-classic .ivd-items th,.ivd-classic .ivd-items td{border:1px solid var(--ink)}
.ivd-classic .ivd-items th{background:#f3f4f6}
.ivd-classic .ivd-parties > div{border:1px solid var(--ink);padding:5px 8px}
.ivd-classic .ivd-thanks{color:var(--ink);font-style:italic}
/* compact: dense */
.ivd-compact{font-size:10.5px}
.a4.ivd-compact{padding:16px 22px}
.ivd-compact .ivd-band{padding-bottom:5px;margin-bottom:5px}
.ivd-compact .ivd-logo{width:30px;height:30px;font-size:15px}
.ivd-compact .ivd-brand h1{font-size:14px}
.ivd-compact .ivd-doc h2{font-size:15px}
.ivd-compact .ivd-items th,.ivd-compact .ivd-items td{padding:2.5px 4px}
.ivd-compact .ivd-parties{margin-bottom:5px}
@page{@bottom-right{content:"Page " counter(page) " of " counter(pages);font:9px Inter,sans-serif;color:#6b7280}}
@media (max-width:640px){.a4.ivd{padding:18px 14px}.ivd-band,.ivd-sum{display:block}.ivd-doc{text-align:left;margin-top:10px}.ivd-doc table{margin-left:0}.ivd-doc td{text-align:left;padding:1px 14px 1px 0}.ivd-modern .ivd-band{margin:-18px -14px 12px;padding:16px 14px 12px}.ivd-items{display:block;overflow-x:auto}}
.doc-logo{display:block;max-width:160px;max-height:46px;object-fit:contain}
.ivd-logo:has(.doc-logo){width:auto;height:auto;border:0;border-radius:0;padding:0;font-size:0}
.ivd-compact .doc-logo{max-height:36px;max-width:130px}
@media print{.ivd-modern .ivd-band{margin-top:0}.a4.ivd{padding:0}.ivd-modern .ivd-band{margin-left:0;margin-right:0;padding-left:10px;padding-right:10px}}
/* the footer (signatures, thank-you line, business line) sits at the bottom of the page */
.a4.ivd{display:flex;flex-direction:column;min-height:272mm}
.ivd-bottom{margin-top:auto;padding-top:10px}
/* colourful designs (ocean, sunset, emerald, royal, midnight): gradient band, solid table head, tinted blocks, gradient footer bar */
.ivd.ivd-themed{--tint:color-mix(in srgb,var(--t1) 8%,#fff);--grad:linear-gradient(115deg,var(--t1),var(--t2))}
.ivd.ivd-themed .ivd-band{background:var(--grad);color:#fff;border:0;border-radius:10px;padding:14px 18px;margin:0 0 12px}
.a4.ivd.ivd-themed{padding-top:22px}
.ivd.ivd-themed .ivd-brand h1,.ivd.ivd-themed .ivd-doc h2,.ivd.ivd-themed .ivd-doc .no,.ivd.ivd-themed .ivd-doc td{color:#fff}
.ivd.ivd-themed .ivd-brand p,.ivd.ivd-themed .ivd-brand .reg,.ivd.ivd-themed .ivd-doc td:first-child{color:rgba(255,255,255,.82)}
.ivd.ivd-themed .ivd-doc h2{border:0;padding:0;letter-spacing:.18em}
.ivd.ivd-themed .ivd-logo{background:#fff;border:0;color:var(--t1)}
.ivd.ivd-themed .ivd-brand .bin{border-color:rgba(255,255,255,.8);color:#fff}
.ivd.ivd-themed .ivd-parties{display:grid;border:0;gap:10px;grid-template-columns:repeat(auto-fit,minmax(200px,1fr))}
.ivd.ivd-themed .ivd-parties > div{background:var(--tint);border:0;border-left:4px solid var(--t2);border-radius:8px;padding:8px 11px}
.ivd.ivd-themed .ivd-parties > div + div{border-left:4px solid var(--t2)}
.ivd.ivd-themed .ivd-lbl{color:var(--t1)}
.ivd.ivd-themed .ivd-items{border:0}
.ivd.ivd-themed .ivd-items th{background:var(--t1);color:#fff;border:0;padding:6px 7px}
.ivd.ivd-themed .ivd-items th:first-child{border-radius:7px 0 0 7px}.ivd.ivd-themed .ivd-items th:last-child{border-radius:0 7px 7px 0}
.ivd.ivd-themed .ivd-items td{border:0;border-bottom:1px solid var(--tint)}
.ivd.ivd-themed .ivd-items tbody tr:nth-child(even) td{background:var(--tint)}
.ivd.ivd-themed .ivd-tot .gt td{background:var(--t1);color:#fff;border:0;padding:7px 10px}
.ivd.ivd-themed .ivx-p-tot .gt{background:var(--grad);color:#fff;border:0;padding:7px 10px}
.ivd.ivd-themed .ivd-tot .gt td:first-child{border-radius:7px 0 0 7px}.ivd.ivd-themed .ivd-tot .gt td:last-child{border-radius:0 7px 7px 0}
.ivd.ivd-themed .ivx-p-tot{border:0;border-radius:8px;overflow:hidden;background:var(--tint)}
.ivd.ivd-themed .ivx-p-tot > div{border-color:#fff}
.ivd.ivd-themed .ivx-p-tot .gt dt,.ivd.ivd-themed .ivx-p-tot .gt dd{color:#fff}
.ivd.ivd-themed .ivd-stamp{color:var(--t1)}
.ivd.ivd-themed .ivd-thanks,.ivd.ivd-themed .ivd-thanks.dx{color:var(--t1);font-weight:700}
.ivd.ivd-themed .ivd-foot,.ivd.ivd-themed .ivx-p-bar{background:var(--grad);color:#fff;border:0;border-radius:8px;padding:6px 12px}
.ivd.ivd-themed .ivx-p-signs .ln,.ivd.ivd-themed .doc-sign-ln{border-top-color:var(--t1)}
.ivd.ivd-themed::after{display:none}
.ivd.ivd-themed .ivd-doc .dx,.ivd.ivd-themed .ivd-doc .dx-title,.ivd.ivd-themed .ivd-doc select.dx{color:#fff;border-color:rgba(255,255,255,.3)}
.ivd.ivd-themed .ivd-doc .dx::placeholder{color:rgba(255,255,255,.6)}
.ivd.ivd-themed .ivd-doc input[type=date]{color-scheme:dark}
.ivd.ivd-themed .ivd-doc select.dx option{color:#111}
.ivx-paper.ivd.ivd-themed .ivd-doc .dx-title{border:1.5px solid rgba(255,255,255,.75)}
.ivd.ivd-themed .ivd-band .ivx-settings-link{color:#fff}
.ivd .ivd-parties.ivd-parties-doc{display:grid;grid-template-columns:minmax(0,1fr) minmax(190px,auto);gap:6px 18px;align-items:start}
.ivd .ivd-parties-doc > div:not(.ivd-doc){grid-column:1}
.ivd .ivd-parties-doc > .ivd-doc{grid-column:2;grid-row:1 / span 3;background:none;border:0;padding:0;text-align:right}
.ivd.ivd-themed .ivd-parties-doc > .ivd-doc{background:none;border:0;padding:0}
.ivd.ivd-themed .ivd-parties-doc .ivd-doc h2{color:var(--t1)}
.ivd.ivd-themed .ivd-parties-doc .ivd-doc .no,.ivd.ivd-themed .ivd-parties-doc .ivd-doc td{color:var(--ink)}
.ivd.ivd-themed .ivd-parties-doc .ivd-doc td:first-child{color:var(--soft)}

/* header / footer images fill the A4 sheet edge to edge */
.a4.ivd.ivd-hi,.ivx-paper.ivd.ivd-hi{padding-top:0}
.a4.ivd.ivd-fi,.ivx-paper.ivd.ivd-fi{padding-bottom:0}
.ivd.ivd-hi .inv-himg{width:calc(100% + 52px);max-width:none;max-height:none;object-fit:fill;margin:0 -26px 12px}
.ivd.ivd-fi .inv-fimg{width:calc(100% + 52px);max-width:none;max-height:none;object-fit:fill;margin:10px -26px 0}
.ivd-compact.ivd-hi .inv-himg,.ivd-compact.ivd-fi .inv-fimg{width:calc(100% + 44px);margin-left:-22px;margin-right:-22px}
@media print{.ivd.ivd-hi .inv-himg,.ivd.ivd-fi .inv-fimg{width:100%;margin-left:0;margin-right:0}}
</style>
