(function () {
  const form = document.getElementById('upload-form');
  const input = document.getElementById('photo');
  if (!form || !input) return;

  const nativeInput = document.getElementById('photo-native');
  const img = document.getElementById('preview');
  const empty = document.getElementById('preview-empty');
  const mode = document.getElementById('mode');
  const consentWrap = document.getElementById('consent-wrap');
  const consent = document.getElementById('consent_ai');
  const loading = document.getElementById('loading');
  const quality = document.getElementById('quality');
  const sizeNote = document.getElementById('size-note');
  const statusEl = document.getElementById('photo-status');
  const btnCamera = document.getElementById('btn-camera');
  const btnGallery = document.getElementById('btn-gallery');
  const btnNative = document.getElementById('btn-native');

  const MAX_SIDE = 2000;      // sisi terpanjang setelah dikecilkan
  const JPEG_QUALITY = 0.85;

  // ------------------------------------------------------------------ umum
  function syncConsent() {
    const needs = mode && mode.value === 'gemini';
    if (consentWrap) consentWrap.classList.toggle('d-none', !needs);
    if (consent) consent.required = !!needs;
  }
  if (mode) { mode.addEventListener('change', syncConsent); syncConsent(); }

  function mb(bytes) { return (bytes / 1048576).toFixed(1).replace('.', ',') + ' MB'; }
  function say(msg, tone) {
    if (!statusEl) return;
    statusEl.textContent = msg || '';
    statusEl.style.color = tone === 'bad' ? 'var(--kcf-red-ink)' : (tone === 'ok' ? 'var(--kcf-green-ink)' : 'var(--kcf-muted)');
    statusEl.style.fontWeight = tone === 'bad' ? '700' : '400';
  }

  function loadImage(file) {
    return new Promise(function (resolve, reject) {
      const url = URL.createObjectURL(file);
      const im = new Image();
      im.onload = function () { resolve({ im: im, url: url }); };
      im.onerror = function () { URL.revokeObjectURL(url); reject(new Error('gagal memuat gambar')); };
      im.src = url;
    });
  }

  function drawScaled(source, w, h, maxSide) {
    const scale = Math.min(1, maxSide / Math.max(w, h));
    const c = document.createElement('canvas');
    c.width = Math.max(1, Math.round(w * scale));
    c.height = Math.max(1, Math.round(h * scale));
    c.getContext('2d').drawImage(source, 0, 0, c.width, c.height);
    return c;
  }

  function qualityOf(canvas) {
    if (!window.KcfImageQuality) return null;
    const t = document.createElement('canvas');
    const s = Math.min(1, 640 / Math.max(canvas.width, canvas.height));
    t.width = Math.max(1, Math.round(canvas.width * s));
    t.height = Math.max(1, Math.round(canvas.height * s));
    const ctx = t.getContext('2d');
    ctx.drawImage(canvas, 0, 0, t.width, t.height);
    const data = ctx.getImageData(0, 0, t.width, t.height).data;
    return window.KcfImageQuality.verdict(window.KcfImageQuality.analyze(data, t.width, t.height));
  }

  function showQuality(canvas) {
    if (!quality) return;
    const v = qualityOf(canvas);
    if (!v) return;
    if (v.ok) {
      quality.className = 'kcf-banner is-ok kcf-quality show';
      quality.innerHTML = '<div><strong>Foto tampak jelas.</strong> Pastikan seluruh tabel masuk bingkai.</div>';
    } else {
      quality.className = 'kcf-banner is-draft kcf-quality show';
      quality.innerHTML = '<div><strong>Saran sebelum mengirim:</strong><ul class="mb-0 ps-3">' +
        v.issues.map(function (i) { return '<li>' + i.text + '</li>'; }).join('') +
        '</ul><span class="small">Ini hanya saran; foto ini tetap bisa dipakai.</span></div>';
    }
  }

  function setFile(file) {
    if (!window.DataTransfer) return false;
    const dt = new DataTransfer();
    dt.items.add(file);
    input.files = dt.files;
    return true;
  }

  // Satu jalur untuk semua sumber foto (kamera, galeri, kamera bawaan): kecilkan, periksa, pratinjau.
  async function handleFile() {
    const f = input.files[0];
    if (quality) { quality.className = 'kcf-quality'; quality.innerHTML = ''; }
    if (sizeNote) sizeNote.textContent = '';
    if (!f) { img.classList.add('d-none'); empty.classList.remove('d-none'); say(''); return; }

    const max = parseInt(input.dataset.max || '0', 10);
    if (!/^image\/(jpeg|png)$/.test(f.type)) {
      say('Pilih file JPG atau PNG.', 'bad'); input.value = ''; return;
    }

    let finalFile = f;
    try {
      const loaded = await loadImage(f);
      const canvas = drawScaled(loaded.im, loaded.im.naturalWidth, loaded.im.naturalHeight, MAX_SIDE);
      img.src = loaded.url;
      showQuality(canvas);
      const needsShrink = f.size > 1.5 * 1048576 || Math.max(loaded.im.naturalWidth, loaded.im.naturalHeight) > MAX_SIDE;
      if (needsShrink && canvas.toBlob && window.DataTransfer) {
        const blob = await new Promise(function (res) { canvas.toBlob(res, 'image/jpeg', JPEG_QUALITY); });
        if (blob && blob.size < f.size) {
          const name = f.name.replace(/\.[^.]+$/, '') + '.jpg';
          if (setFile(new File([blob], name, { type: 'image/jpeg' }))) {
            finalFile = input.files[0];
            if (sizeNote) sizeNote.textContent = 'Foto dikecilkan dari ' + mb(f.size) + ' menjadi ' + mb(blob.size) + ' agar cepat terkirim.';
          }
        }
      }
    } catch (e) {
      img.src = URL.createObjectURL(f);   // pratinjau saja; server tetap memvalidasi
    }

    if (max && finalFile.size > max) {
      say('Ukuran foto terlalu besar (maks. ' + Math.round(max / 1048576) + ' MB).', 'bad');
      input.value = ''; img.classList.add('d-none'); empty.classList.remove('d-none'); return;
    }
    img.classList.remove('d-none');
    empty.classList.add('d-none');
    say('Foto siap: ' + finalFile.name + ' (' + mb(finalFile.size) + ')', 'ok');
    if (btnGallery) btnGallery.lastChild.textContent = 'Ganti dari galeri';
    if (btnCamera) btnCamera.lastChild.textContent = 'Foto ulang dengan kamera';
  }
  input.addEventListener('change', handleFile);

  // ------------------------------------------------------------------ galeri dan kamera bawaan
  if (btnGallery) btnGallery.addEventListener('click', function () { input.click(); });
  if (nativeInput) {
    nativeInput.addEventListener('change', function () {
      if (nativeInput.files[0] && setFile(nativeInput.files[0])) { input.dispatchEvent(new Event('change')); }
      nativeInput.value = '';
    });
  }
  if (btnNative && nativeInput) btnNative.addEventListener('click', function () { nativeInput.click(); });

  // ------------------------------------------------------------------ kamera langsung
  const dlg = document.getElementById('cam-dialog');
  const video = document.getElementById('cam-video');
  const shot = document.getElementById('cam-shot');
  const chips = document.getElementById('cam-chips');
  const msg = document.getElementById('cam-msg');
  const barLive = document.getElementById('cam-bar-live');
  const barReview = document.getElementById('cam-bar-review');
  const btnShutter = document.getElementById('cam-shutter');
  const btnSwitch = document.getElementById('cam-switch');
  const btnClose = document.getElementById('cam-close');
  const btnRetake = document.getElementById('cam-retake');
  const btnUse = document.getElementById('cam-use');
  const guide = document.getElementById('cam-guide');

  const canLive = !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia) && window.isSecureContext !== false;
  const coarse = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;
  // Kamera bawaan HP ditawarkan di perangkat sentuh, atau jika kamera langsung tidak tersedia (mis. HTTP biasa di jaringan lokal).
  if (btnNative && (coarse || !canLive)) btnNative.hidden = false;

  let stream = null;
  let facing = 'environment';
  let timer = null;
  let shotBlob = null;

  function stopStream() {
    if (timer) { clearInterval(timer); timer = null; }
    if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; }
    if (video) video.srcObject = null;
  }

  function showMsg(text) {
    msg.textContent = text;
    msg.hidden = !text;
    guide.hidden = !!text;
  }

  function explain(err) {
    const n = err && err.name;
    if (n === 'NotAllowedError' || n === 'SecurityError') return 'Izin kamera ditolak. Izinkan kamera untuk situs ini di pengaturan browser, atau pilih foto dari galeri.';
    if (n === 'NotSupportedError') return 'Kamera tidak bisa dibuka: izin belum diberikan atau tidak didukung di sini. Cek izin kamera di browser, atau pilih foto dari galeri.';
    if (n === 'NotFoundError' || n === 'OverconstrainedError') return 'Kamera tidak ditemukan di perangkat ini. Pilih foto dari galeri.';
    if (n === 'NotReadableError' || n === 'AbortError') return 'Kamera sedang dipakai aplikasi lain. Tutup aplikasi itu lalu coba lagi, atau pilih dari galeri.';
    return 'Kamera tidak bisa dibuka. Cek izin kamera di browser, atau pilih foto dari galeri atau pakai kamera bawaan HP.';
  }

  async function startStream() {
    stopStream();
    showMsg('');
    chips.innerHTML = '';
    try {
      stream = await navigator.mediaDevices.getUserMedia({
        audio: false,
        video: { facingMode: { ideal: facing }, width: { ideal: 1920 }, height: { ideal: 1080 } }
      });
    } catch (e) {
      showMsg(explain(e));
      btnShutter.disabled = true;
      return;
    }
    btnShutter.disabled = false;
    video.srcObject = stream;
    try { await video.play(); } catch (e) { /* autoplay sudah diatur */ }
    try {
      const devs = await navigator.mediaDevices.enumerateDevices();
      const cams = devs.filter(function (d) { return d.kind === 'videoinput'; });
      btnSwitch.hidden = cams.length < 2;
      document.getElementById('cam-spacer').hidden = cams.length >= 2;
    } catch (e) { btnSwitch.hidden = true; }
    timer = setInterval(liveHint, 800);
  }

  // Saran cahaya dan ketajaman secara langsung (hanya saran).
  function liveHint() {
    if (!video.videoWidth || !window.KcfImageQuality) return;
    const c = drawScaled(video, video.videoWidth, video.videoHeight, 320);
    const v = qualityOf(c);
    if (!v) return;
    chips.innerHTML = v.ok
      ? '<span class="cam-chip is-ok">Cahaya dan fokus tampak baik</span>'
      : v.issues.map(function (i) { return '<span class="cam-chip is-warn">' + i.text + '</span>'; }).join('');
  }

  function enterReview(on) {
    barLive.hidden = on;
    barReview.hidden = !on;
    shot.hidden = !on;
    video.style.visibility = on ? 'hidden' : 'visible';
    guide.hidden = on;
    if (on) { chips.innerHTML = ''; (btnUse).focus(); } else { btnShutter.focus(); }
  }

  async function openCamera() {
    if (!canLive || !dlg || !dlg.showModal) {
      say('Kamera langsung tidak tersedia di sini (butuh HTTPS atau localhost). Pakai aplikasi kamera bawaan HP atau pilih dari galeri.', 'bad');
      if (btnNative) btnNative.hidden = false;
      return;
    }
    say('');
    enterReview(false);
    shotBlob = null;
    dlg.showModal();
    await startStream();
  }

  function capture() {
    if (!video.videoWidth) { showMsg('Kamera belum siap. Tunggu sebentar lalu coba lagi.'); return; }
    const c = drawScaled(video, video.videoWidth, video.videoHeight, MAX_SIDE);
    c.toBlob(function (blob) {
      if (!blob) { showMsg('Gagal mengambil foto. Coba lagi.'); return; }
      shotBlob = blob;
      if (shot.dataset.url) URL.revokeObjectURL(shot.dataset.url);
      shot.dataset.url = URL.createObjectURL(blob);
      shot.src = shot.dataset.url;
      video.pause();
      enterReview(true);
    }, 'image/jpeg', 0.92);
  }

  function closeCamera() {
    stopStream();
    if (dlg.open) dlg.close();
  }

  if (btnCamera) btnCamera.addEventListener('click', openCamera);
  if (btnShutter) btnShutter.addEventListener('click', capture);
  if (btnClose) btnClose.addEventListener('click', closeCamera);
  if (btnRetake) btnRetake.addEventListener('click', function () { enterReview(false); shotBlob = null; video.play(); if (!timer) timer = setInterval(liveHint, 800); });
  if (btnSwitch) btnSwitch.addEventListener('click', function () { facing = facing === 'environment' ? 'user' : 'environment'; startStream(); });
  if (btnUse) btnUse.addEventListener('click', function () {
    if (!shotBlob) return;
    const stamp = new Date().toISOString().replace(/[-:T]/g, '').slice(0, 14);
    if (setFile(new File([shotBlob], 'foto-register-' + stamp + '.jpg', { type: 'image/jpeg' }))) {
      input.dispatchEvent(new Event('change'));
    }
    closeCamera();
  });
  if (dlg) dlg.addEventListener('close', stopStream);

  // ------------------------------------------------------------------ kirim
  form.addEventListener('submit', function (ev) {
    if (!input.files.length) {
      ev.preventDefault();
      say('Belum ada foto. Buka kamera atau pilih dari galeri dulu.', 'bad');
      if (btnCamera) btnCamera.focus();
      return;
    }
    document.getElementById('submit-btn').disabled = true;
    loading.classList.add('show');
    const steps = loading.querySelectorAll('.kcf-steps li');
    let i = 0;
    if (steps.length) {
      steps[0].classList.add('is-now');
      setInterval(function () {
        if (i < steps.length - 1) { steps[i].classList.remove('is-now'); i++; steps[i].classList.add('is-now'); }
      }, 6000);
    }
  });
})();
