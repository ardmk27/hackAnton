// Uji fungsi kualitas foto (Node, tanpa dependensi):  node tests/js/imagequality.test.js
const Q = require('../../assets/js/imagequality.js');
let ok = 0, fail = 0;
const check = (label, cond, detail) => { if (cond) { ok++; console.log('  ok   ' + label); } else { fail++; console.log('  GAGAL ' + label + (detail ? '  -> ' + detail : '')); } };

// Pembuat gambar sintetis: latar kertas terang + goresan "tulisan" gelap
function makeImage(w, h, bg, strokes) {
  const px = new Uint8ClampedArray(w * h * 4);
  for (let i = 0; i < w * h; i++) { px[i * 4] = px[i * 4 + 1] = px[i * 4 + 2] = bg; px[i * 4 + 3] = 255; }
  let seed = 12345; const rnd = () => (seed = (seed * 1103515245 + 12345) & 0x7fffffff) / 0x7fffffff;
  for (let s = 0; s < strokes; s++) {
    const x0 = Math.floor(rnd() * (w - 30)), y0 = Math.floor(rnd() * (h - 4));
    for (let k = 0; k < 24; k++) for (let t = 0; t < 2; t++) {
      const i = (y0 + t) * w + x0 + k; px[i * 4] = px[i * 4 + 1] = px[i * 4 + 2] = 20;
    }
  }
  return px;
}
function boxBlur(src, w, h, r) {
  const out = new Uint8ClampedArray(src.length);
  for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
    let sum = 0, c = 0;
    for (let dy = -r; dy <= r; dy++) for (let dx = -r; dx <= r; dx++) {
      const xx = Math.min(w - 1, Math.max(0, x + dx)), yy = Math.min(h - 1, Math.max(0, y + dy));
      sum += src[(yy * w + xx) * 4]; c++;
    }
    const v = sum / c; const i = (y * w + x) * 4; out[i] = out[i + 1] = out[i + 2] = v; out[i + 3] = 255;
  }
  return out;
}

const W = 160, H = 160;
const sharp = makeImage(W, H, 235, 60);
const blurred = boxBlur(sharp, W, H, 3);
const dark = makeImage(W, H, 40, 60);
const glare = makeImage(W, H, 252, 5);

const a = Q.analyze(sharp, W, H), b = Q.analyze(blurred, W, H);
check('foto tajam punya ketajaman jauh lebih tinggi dari foto buram', a.sharpness > b.sharpness * 5, a.sharpness.toFixed(1) + ' vs ' + b.sharpness.toFixed(1));
check('foto tajam dan terang dinyatakan baik', Q.verdict(a).ok, JSON.stringify(Q.verdict(a)));
check('foto buram ditandai buram', Q.verdict(b).issues.some(i => i.key === 'blur'));
check('foto gelap ditandai kurang terang', Q.verdict(Q.analyze(dark, W, H)).issues.some(i => i.key === 'dark'));
check('foto terlalu terang ditandai silau', Q.verdict(Q.analyze(glare, W, H)).issues.some(i => i.key === 'glare'));
check('kecerahan rata-rata masuk akal', a.brightness > 200 && a.brightness < 240);

console.log('\nLulus: ' + ok + '   Gagal: ' + fail);
process.exit(fail ? 1 : 0);
