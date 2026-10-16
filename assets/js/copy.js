/* Tombol salin: <button data-copy="teks">. Memakai Clipboard API, dengan cadangan
   untuk halaman tanpa HTTPS (mis. server lokal lewat http://IP). */
(function () {
  'use strict';
  var CHECK = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M5 12l4 4 10-10"/></svg>';

  function fallbackCopy(text) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.position = 'fixed';
    ta.style.top = '-1000px';
    document.body.appendChild(ta);
    ta.select();
    ta.setSelectionRange(0, text.length);
    var ok = false;
    try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
    document.body.removeChild(ta);
    return ok ? Promise.resolve() : Promise.reject(new Error('copy failed'));
  }

  function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(text).catch(function () { return fallbackCopy(text); });
    }
    return fallbackCopy(text);
  }

  function flash(btn, ok) {
    var ic = btn.querySelector('.ic');
    var lbl = btn.querySelector('.lbl');
    var live = btn.parentNode.querySelector('[role="status"]');
    if (btn._orig === undefined) { btn._orig = { ic: ic.innerHTML, lbl: lbl.textContent }; }
    clearTimeout(btn._t);
    if (ok) { ic.innerHTML = CHECK; lbl.textContent = 'Tersalin'; btn.classList.add('is-done'); }
    else { lbl.textContent = 'Gagal, salin manual'; }
    if (live) { live.textContent = ok ? 'Tersalin ke papan klip' : 'Gagal menyalin. Pilih teks lalu salin manual.'; }
    btn._t = setTimeout(function () {
      ic.innerHTML = btn._orig.ic; lbl.textContent = btn._orig.lbl;
      btn.classList.remove('is-done');
      if (live) { live.textContent = ''; }
    }, 2200);
  }

  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest ? ev.target.closest('[data-copy]') : null;
    if (!btn) { return; }
    copyText(btn.getAttribute('data-copy') || '').then(
      function () { flash(btn, true); },
      function () { flash(btn, false); }
    );
  });
})();
