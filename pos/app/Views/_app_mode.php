<?php
/*
 * Inline in <head>: html.app-mode on phones before the first paint (app.css styles the phone
 * layout under that class) — portrait up to 700px, or a phone turned sideways (short screen).
 * Tablets (748px portrait and up) keep the laptop layout. Kept in sync on resize / rotation;
 * fires "omni:appmode" on document so scripts can re-render (assets/pos/pos.js).
 */
?>
<script>(function(){var m=window.matchMedia('(max-width: 700px), (max-width: 950px) and (max-height: 500px)'),r=document.documentElement,f=function(){var on=r.classList.contains('app-mode');r.classList.toggle('app-mode',m.matches);if(on!==m.matches)document.dispatchEvent(new Event('omni:appmode'));};f();if(m.addEventListener)m.addEventListener('change',f);else m.addListener(f);})();</script>
