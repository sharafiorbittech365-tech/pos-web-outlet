<?php
/** Print styles of invoice and Mushak-6.3 documents (inline so in-place printing gets them too). */
?>
<link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;600;700&display=swap" rel="stylesheet">
<style>
.inv-page{position:relative;overflow:hidden}
.a4.inv-page > .inv-wm{position:absolute;inset:0;z-index:0;display:flex;align-items:center;justify-content:center;font:800 120px/1 Inter,sans-serif;color:rgba(220,38,38,.08);transform:rotate(-24deg);pointer-events:none;letter-spacing:.1em}
.inv-himg{display:block;width:100%;max-height:160px;object-fit:contain;margin:0 0 14px}
.inv-fimg{display:block;width:100%;max-height:120px;object-fit:contain;margin:12px 0 0}
.inv-sign{display:flex;justify-content:flex-end;margin-top:28px}
.inv-sign-box{display:flex;flex-direction:column;align-items:center;min-width:200px;gap:2px;font-size:12.5px}
.inv-sign-box img{max-height:60px;max-width:200px;object-fit:contain}
.inv-sign-line{display:block;width:100%;border-top:1px solid #334155;margin:4px 0 2px}
.mk{font:12px/1.45 'Hind Siliguri',Inter,system-ui,sans-serif;color:#111;background:#fff}
.mk.a4{padding:28px 32px;max-width:820px;margin:0 auto}
.mk-top{display:grid;grid-template-columns:70px 1fr 110px;align-items:start;gap:8px}
.mk-gov{text-align:center}
.mk-gov div{font-size:13px}
.mk-gov b{font-size:15px}
.mk-form{border:1.5px solid #111;padding:4px 8px;font-weight:700;font-size:14px;text-align:center;align-self:start}
.mk-title{text-align:center;margin:8px 0 2px;font-size:15px;font-weight:700}
.mk-rule{text-align:center;font-weight:600;margin-bottom:10px}
.mk-sup{display:grid;grid-template-columns:max-content 1fr;gap:2px 8px;width:max-content;max-width:80%;margin:0 auto 12px}
.mk-two{display:grid;grid-template-columns:1fr 1fr;gap:4px 24px;margin-bottom:10px}
.mk-kv{display:grid;grid-template-columns:max-content 1fr;gap:2px 8px;align-content:start}
.mk-kv span:nth-child(odd),.mk-sup span:nth-child(odd){color:#333}
.mk small.en{display:block;font:10px/1.2 Inter,sans-serif;color:#64748b}
.mk-tbl{width:100%;border-collapse:collapse;margin:6px 0}
.mk-tbl th,.mk-tbl td{border:1px solid #333;padding:4px 5px;vertical-align:top}
.mk-tbl th{font-weight:600;font-size:11px;text-align:center}
.mk-tbl td.n{text-align:right;white-space:nowrap}
.mk-tbl tfoot td{font-weight:700}
.mk-extra{display:grid;grid-template-columns:1fr 1fr;gap:4px 24px;margin:8px 0;padding:6px 8px;border:1px dashed #94a3b8;font-size:11.5px}
.mk-sign{margin-top:16px;display:grid;grid-template-columns:max-content 1fr;gap:6px 8px;width:60%}
.mk-sign .ln{border-bottom:1px dotted #333;min-height:18px}
.mk-note{margin-top:14px;font-size:11.5px}
.mk-foot{margin-top:10px;border-top:1px solid #999;padding-top:4px;font-size:10.5px;color:#333}
.mk.receipt .mk-c{text-align:center}
.mk.receipt .mk-line{margin:4px 0}
@media (max-width:700px){.mk-two,.mk-extra{grid-template-columns:1fr}.mk.a4{padding:14px}.mk-sup{max-width:100%}}
</style>
