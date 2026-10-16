/* Pemeriksaan kualitas foto di perangkat (tanpa mengirim apa pun ke server).
   HEURISTIK untuk memberi saran sebelum unggah, bukan jaminan. Ambang dapat disesuaikan
   setelah diuji dengan foto register sungguhan. */
(function (root, factory) {
  if (typeof module === 'object' && module.exports) { module.exports = factory(); }
  else { root.KcfImageQuality = factory(); }
})(typeof self !== 'undefined' ? self : this, function () {
  const THRESHOLDS = {
    darkMean: 80,        // rata-rata kecerahan (0-255) di bawah ini = kurang terang
    glareShare: 0.30,    // bagian piksel hampir putih di atas ini = terlalu silau/terang
    blurRatio: 0.25      // variansi Laplacian / variansi kecerahan; di bawah ini = kurang tajam (foto 640 px). Nilai awal dari gambar sintetis: KALIBRASI dengan foto register asli.
  };

  /** rgba: Uint8ClampedArray/Array panjang w*h*4. Mengembalikan statistik. */
  function analyze(rgba, w, h) {
    const n = w * h;
    const gray = new Float32Array(n);
    let sum = 0, sumSq = 0, bright = 0;
    for (let i = 0; i < n; i++) {
      const g = 0.299 * rgba[i * 4] + 0.587 * rgba[i * 4 + 1] + 0.114 * rgba[i * 4 + 2];
      gray[i] = g;
      sum += g;
      sumSq += g * g;
      if (g > 245) bright++;
    }
    const grayMean = sum / n;
    const grayVar = sumSq / n - grayMean * grayMean;
    let lapSum = 0, lapSq = 0, cnt = 0;
    for (let y = 1; y < h - 1; y++) {
      for (let x = 1; x < w - 1; x++) {
        const i = y * w + x;
        const lap = 4 * gray[i] - gray[i - 1] - gray[i + 1] - gray[i - w] - gray[i + w];
        lapSum += lap; lapSq += lap * lap; cnt++;
      }
    }
    const mean = cnt ? lapSum / cnt : 0;
    const lapVar = cnt ? lapSq / cnt - mean * mean : 0;
    // Dinormalisasi dengan kontras gambar agar tidak bergantung pada terang/gelapnya foto.
    return { brightness: grayMean, glareShare: bright / n, sharpness: lapVar / (grayVar + 1), lapVariance: lapVar };
  }

  /** Ubah statistik menjadi saran. ok=true jika tidak ada masalah. */
  function verdict(m, t) {
    t = t || THRESHOLDS;
    const issues = [];
    if (m.brightness < t.darkMean) issues.push({ key: 'dark', text: 'Foto kurang terang. Cari cahaya dari depan atau samping.' });
    if (m.glareShare > t.glareShare) issues.push({ key: 'glare', text: 'Ada bagian terlalu silau. Ubah sudut agar pantulan lampu hilang.' });
    if (m.sharpness < t.blurRatio) issues.push({ key: 'blur', text: 'Tulisan kurang tajam. Tahan HP sebentar sebelum menekan tombol foto.' });
    return { ok: issues.length === 0, issues: issues };
  }

  return { analyze: analyze, verdict: verdict, THRESHOLDS: THRESHOLDS };
});
