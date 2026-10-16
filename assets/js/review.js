(function () {
  const body = document.getElementById('rows-body');
  const tpl = document.getElementById('row-template');
  const form = document.getElementById('review-form');
  if (!body || !tpl || !form) return;

  const nCek = document.getElementById('n-cek');
  const nAll = document.getElementById('n-all');
  const emptyCek = document.getElementById('empty-cek');
  const status = document.getElementById('save-status');
  const filterBtns = document.querySelectorAll('[data-filter]');
  let counter = Date.now();
  let filter = 'cek';

  const rows = () => Array.from(body.querySelectorAll('.review-row'));
  const isFlagged = (r) => r.classList.contains('is-flagged') && !r.classList.contains('is-checked');

  function renumber() {
    rows().forEach((r, i) => { r.querySelector('.row-no').textContent = i + 1; });
  }

  function markEmpty(r) {
    if (r.dataset.ocr !== '1' || r.classList.contains('is-checked')) return;
    r.querySelectorAll('.key-field').forEach((inp) => {
      if (inp.value.trim() === '') inp.classList.add('is-flag');
    });
  }

  function refresh() {
    const all = rows();
    const flagged = all.filter(isFlagged);
    nCek.textContent = flagged.length;
    nAll.textContent = all.length;
    all.forEach((r) => {
      const hide = filter === 'cek' && !isFlagged(r) && !r.classList.contains('is-error');
      r.classList.toggle('is-hidden', hide);
    });
    emptyCek.hidden = !(filter === 'cek' && flagged.length === 0 && all.length > 0);
    status.textContent = flagged.length > 0
      ? flagged.length + ' baris masih ditandai dan belum diperiksa'
      : 'Semua baris yang ditandai sudah diperiksa';
    status.style.color = flagged.length > 0 ? 'var(--kcf-amber-ink)' : 'var(--kcf-green-ink)';
  }

  function setFilter(f) {
    filter = f;
    filterBtns.forEach((b) => {
      const on = b.dataset.filter === f;
      b.classList.toggle('is-on', on);
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    refresh();
  }
  filterBtns.forEach((b) => b.addEventListener('click', () => setFilter(b.dataset.filter)));

  function initRow(r) {
    r.dataset.wasFlagged = r.classList.contains('is-flagged') ? '1' : '0';
    markEmpty(r);
  }

  document.getElementById('add-row').addEventListener('click', function () {
    const html = tpl.innerHTML.replace(/__KEY__/g, 'n' + (counter++));
    body.insertAdjacentHTML('beforeend', html);
    const r = body.lastElementChild;
    initRow(r);
    renumber();
    setFilter('all');
    const first = r.querySelector('input:not([type=hidden])');
    if (first) first.focus();
  });

  body.addEventListener('click', function (ev) {
    const row = ev.target.closest('.review-row');
    if (!row) return;

    if (ev.target.closest('.btn-del-row')) {
      if (rows().length <= 1) {
        row.querySelectorAll('input:not([type=hidden]):not([type=checkbox]), select').forEach((i) => { i.value = ''; });
        markEmpty(row);
        refresh();
        return;
      }
      if (confirm('Hapus baris ini dari draf?')) {
        row.remove();
        renumber();
        refresh();
      }
      return;
    }

    if (ev.target.closest('.btn-mark-ok')) {
      const btn = row.querySelector('.btn-mark-ok');
      const nowChecked = !row.classList.contains('is-checked');
      row.classList.toggle('is-checked', nowChecked);
      row.querySelector('.h-checked').value = nowChecked ? '1' : '0';
      btn.setAttribute('aria-pressed', nowChecked ? 'true' : 'false');
      btn.querySelector('span').textContent = nowChecked ? 'Sudah diperiksa (batalkan)' : 'Sudah saya periksa';
      if (nowChecked) {
        row.querySelectorAll('.is-flag, .is-flag-bad').forEach((i) => i.classList.remove('is-flag', 'is-flag-bad'));
        row.querySelector('.chk-needs').checked = false;
        row.querySelector('.h-flags').value = '';
      } else {
        row.classList.toggle('is-flagged', row.dataset.wasFlagged === '1');
        markEmpty(row);
      }
      refresh();
      return;
    }

    const fillBtn = ev.target.closest('.btn-fill');
    if (fillBtn) {
      let data = {};
      try { data = JSON.parse(fillBtn.dataset.fill || '{}'); } catch (e) { return; }
      Object.keys(data).forEach((k) => {
        const el = row.querySelector('[name$="[' + k + ']"]');
        if (el) { el.value = data[k] || ''; el.classList.remove('is-flag', 'is-flag-bad'); }
      });
      const sug = fillBtn.closest('.rc-suggest');
      if (sug) sug.innerHTML = '<span class="known">Nama dipakai dari daftar anak. Simpan draf untuk membandingkan dengan catatan sebelumnya.</span>';
      refresh();
    }
  });

  body.addEventListener('input', function (ev) {
    const t = ev.target;
    if (t.classList.contains('is-flag') || t.classList.contains('is-flag-bad')) {
      t.classList.remove('is-flag', 'is-flag-bad');
    }
    if (t.classList.contains('key-field') && t.value.trim() === '') {
      const row = t.closest('.review-row');
      if (row && row.dataset.ocr === '1' && !row.classList.contains('is-checked')) t.classList.add('is-flag');
    }
  });

  body.addEventListener('change', function (ev) {
    if (ev.target.classList.contains('chk-needs')) {
      const row = ev.target.closest('.review-row');
      row.classList.toggle('is-flagged', ev.target.checked);
      if (ev.target.checked) row.dataset.wasFlagged = '1';
      refresh();
    }
  });

  form.addEventListener('submit', function (ev) {
    const submitter = ev.submitter;
    if (submitter && submitter.value === 'confirm') {
      const left = rows().filter(isFlagged).length;
      const msg = left > 0
        ? left + ' baris masih ditandai dan belum Anda periksa. Tetap konfirmasi? Setelah dikonfirmasi, data masuk rekap dan tren.'
        : 'Konfirmasi data? Pastikan semua baris sudah dibandingkan dengan foto sumber. Setelah dikonfirmasi, data masuk rekap dan tren.';
      if (!confirm(msg)) ev.preventDefault();
    }
  });

  // ---- Foto sumber: zoom + layar penuh (HP) ----
  const imgs = document.querySelectorAll('.js-photo-img');
  let zoom = 100;
  function applyZoom() {
    imgs.forEach((im) => { im.style.width = zoom + '%'; });
    document.querySelectorAll('.js-zoom-label').forEach((l) => { l.textContent = zoom + '%'; });
  }
  document.querySelectorAll('.js-zoom').forEach((b) => b.addEventListener('click', () => {
    const d = parseInt(b.dataset.dir, 10);
    zoom = d === 0 ? 100 : Math.max(50, Math.min(400, zoom + d * 25));
    applyZoom();
  }));
  const dlg = document.getElementById('photo-dialog');
  const openBtn = document.getElementById('open-photo');
  if (dlg && openBtn) {
    openBtn.addEventListener('click', () => { if (dlg.showModal) dlg.showModal(); else window.open(imgs[0].src, '_blank'); });
    document.getElementById('close-photo').addEventListener('click', () => dlg.close());
  }

  rows().forEach(initRow);
  renumber();
  setFilter(rows().some(isFlagged) ? 'cek' : 'all');
})();
