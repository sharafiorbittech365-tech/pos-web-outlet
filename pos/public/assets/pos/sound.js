/*!
 * POS click sound — a short soft tick each time a product is added to the cart (pos.js calls OmniSound.click()). Generated with the Web Audio API (no sound files).
 * The speaker button in the till toolbar turns it on/off; the choice is kept on this device.
 */
(function () {
  'use strict';
  var KEY = 'omnipos.sound';
  var ctx = null;

  function on() { try { return localStorage.getItem(KEY) !== 'off'; } catch (e) { return true; } }
  function audio() {
    if (!ctx) { var C = window.AudioContext || window.webkitAudioContext; if (!C) return null; try { ctx = new C(); } catch (e) { return null; } }
    if (ctx.state === 'suspended') ctx.resume();
    return ctx;
  }
  function tone(freq, dur, vol, type, at) {
    var a = audio(); if (!a) return;
    var t0 = a.currentTime + (at || 0), o = a.createOscillator(), g = a.createGain();
    o.type = type || 'sine'; o.frequency.setValueAtTime(freq, t0);
    g.gain.setValueAtTime(0.0001, t0); g.gain.exponentialRampToValueAtTime(vol, t0 + 0.004); g.gain.exponentialRampToValueAtTime(0.0001, t0 + dur);
    o.connect(g); g.connect(a.destination); o.start(t0); o.stop(t0 + dur + 0.02);
  }
  function click() { if (on()) { tone(1500, 0.045, 0.07, 'square'); tone(900, 0.06, 0.05, 'sine'); } }
  function done() { if (on()) { tone(880, 0.12, 0.09, 'sine'); tone(1318, 0.22, 0.09, 'sine', 0.1); } }
  window.OmniSound = { click: click, done: done, on: on };

  // toolbar switch
  function paint(b) { var off = !on(); b.classList.toggle('is-off', off); b.setAttribute('aria-pressed', off ? 'false' : 'true'); b.title = off ? 'Click sound: off' : 'Click sound: on'; b.style.opacity = off ? '.45' : ''; }
  document.addEventListener('click', function (e) {
    var b = e.target && e.target.closest ? e.target.closest('[data-sound-toggle]') : null;
    if (!b) return;
    try { localStorage.setItem(KEY, on() ? 'off' : 'on'); } catch (er) { /* storage blocked */ }
    document.querySelectorAll('[data-sound-toggle]').forEach(paint);
    if (on()) click();
  });
  document.addEventListener('DOMContentLoaded', function () { document.querySelectorAll('[data-sound-toggle]').forEach(paint); });
})();
