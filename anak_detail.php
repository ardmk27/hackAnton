<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/trends.php';
require_once __DIR__ . '/includes/charts.php';

$currentPosyandu = $p = require_login();
$pdo = db();
$pid = (int)$p['id'];

$id = (int)($_GET['id'] ?? 0);
$roster = load_roster($pdo, $pid);          // hanya anak milik Posyandu yang login
$anak = $roster[$id] ?? abort_404();

$periodParam = (string)($_GET['periode'] ?? '');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodParam)) {
    $periodParam = '';
}
$sig = weight_signal($anak['points'], $periodParam !== '' ? $periodParam : null);
$last = $anak['points'] ? end($anak['points']) : null;
$ageNow = $last ? age_months($anak['dob'], $last['period']) : null;
$draft = ($sig && $sig['level']) ? parent_message_draft((string)$p['name'], $anak['name'], $sig) : '';

$pageTitle = $anak['name'];
require __DIR__ . '/includes/header.php';
?>
<p class="mb-2"><a class="fw-bold text-decoration-none" href="<?= $periodParam !== '' ? 'attention.php?periode=' . e($periodParam) : 'anak.php' ?>"><?= icon('back', 18) ?> <?= $periodParam !== '' ? 'Kembali ke daftar perhatian' : 'Daftar anak' ?></a></p>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
  <div>
    <h1 class="page-title h4 mb-1"><?= e($anak['name']) ?></h1>
    <div class="muted"><?= $ageNow !== null ? (int)$ageNow . ' bulan' : 'umur belum diketahui' ?><?= $anak['sex'] ? ', ' . ($anak['sex'] === 'L' ? 'laki-laki' : 'perempuan') : '' ?><?= $anak['ref'] ? ', no. daftar ' . e($anak['ref']) : '' ?><?= $anak['dob'] ? ', lahir ' . e(format_date($anak['dob'])) : '' ?></div>
  </div>
  <?php if ($sig && $sig['level']): ?><?= $sig['level'] === 'prioritas' ? pill('Prioritas', 'strong', 'alert') : pill('Cek', 'warn', 'info') ?><?php endif; ?>
</div>

<?php if ($sig && $sig['level']): ?>
  <div class="kcf-banner <?= $sig['level'] === 'prioritas' ? 'is-bad' : 'is-draft' ?>">
    <?= icon('alert', 20) ?>
    <div><strong><?= e($sig['reason']) ?>.</strong> Dihitung dari catatan terkonfirmasi. <?= e(ATTENTION_RULE_TEXT) ?></div>
  </div>
<?php elseif ($sig): ?>
  <div class="kcf-banner is-ok"><?= icon('check', 20) ?><div><strong><?= e($sig['reason']) ?>.</strong> Tidak ada penanda.</div></div>
<?php else: ?>
  <div class="kcf-banner is-info"><?= icon('info', 20) ?><div>Belum cukup catatan untuk melihat tren. Dibutuhkan minimal dua catatan berat badan terkonfirmasi.</div></div>
<?php endif; ?>

<?php if ($anak['points']): ?>
<section class="kcf-card mb-3" aria-labelledby="h-chart">
  <div class="kcf-card-head"><h2 id="h-chart" class="h6 mb-0">Berat badan</h2><span class="muted small fw-normal">Persegi merah = tidak naik dibanding catatan sebelumnya</span></div>
  <div class="kcf-card-body"><?= weight_chart_svg($anak['points']) ?></div>
</section>

<section class="kcf-card mb-3" aria-labelledby="h-hist">
  <div class="kcf-card-head"><h2 id="h-hist" class="h6 mb-0">Riwayat catatan terkonfirmasi</h2></div>
  <div class="kcf-scroll"><table class="kcf-ledger">
    <thead><tr><th>Periode</th><th class="num">BB (kg)</th><th class="num">Selisih BB</th><th class="num">TB/PB (cm)</th><th class="num">L. kepala</th><th class="num">LiLA</th><th>Sesi</th></tr></thead>
    <tbody>
    <?php $prevW = null; foreach ($anak['points'] as $per => $pt):
        $d = ($pt['weight_kg'] !== null && $prevW !== null) ? $pt['weight_kg'] - $prevW : null;
        if ($pt['weight_kg'] !== null) { $prevW = $pt['weight_kg']; } ?>
      <tr>
        <td><strong><?= e(format_period((string)$per)) ?></strong></td>
        <td class="num"><?= e(show_num($pt['weight_kg'])) ?></td>
        <td class="num"><?php if ($d === null) { echo '—'; } elseif ($d <= 0) { echo '<span style="color:var(--kcf-red-ink);font-weight:700">' . e(($d < 0 ? '' : '') . fmt_kg(abs($d) < 0.0001 ? 0.0 : $d)) . '</span>'; } else { echo '+' . e(fmt_num($d)); } ?></td>
        <td class="num"><?= e(show_num($pt['height_cm'])) ?></td>
        <td class="num"><?= e(show_num($pt['head_circumference_cm'])) ?></td>
        <td class="num"><?= e(show_num($pt['lila_cm'])) ?></td>
        <td><a class="row-link" href="session_detail.php?id=<?= (int)$pt['sesi_foto_id'] ?>">#<?= (int)$pt['sesi_foto_id'] ?></a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>
<?php endif; ?>

<section class="kcf-card mb-3" aria-labelledby="h-gizi">
  <div class="kcf-card-body">
    <h2 id="h-gizi" class="h6 mb-1">Status gizi</h2>
    <p class="muted small mb-0">Belum dihitung. Penilaian status gizi (BB/U, TB/U, BB/TB) membutuhkan tabel standar antropometri resmi yang diverifikasi tenaga kesehatan. Penanda di halaman ini hanya membandingkan berat badan dengan catatan sebelumnya.</p>
  </div>
</section>

<?php if ($draft !== ''): ?>
<section class="kcf-card mb-3" id="pesan" aria-labelledby="h-pesan">
  <div class="kcf-card-head"><h2 id="h-pesan" class="h6 mb-0">Draf pesan untuk orang tua</h2></div>
  <div class="kcf-card-body">
    <label class="form-label" for="isi-pesan">Isi pesan</label>
    <textarea class="form-control" id="isi-pesan" rows="9"><?= e($draft) ?></textarea>
    <div class="form-text mb-3">Isi bagian dalam kurung siku sebelum dikirim. Petugas yang mengirim lewat WhatsApp atau SMS; aplikasi tidak mengirim otomatis. Hindari istilah medis dan diagnosis.</div>
    <button type="button" class="btn btn-brand" id="btn-copy"><?= icon('copy', 18) ?>Salin pesan</button>
    <span id="copy-status" class="ms-2 fw-bold" role="status" aria-live="polite" style="color:var(--kcf-green-ink)"></span>
  </div>
</section>
<script>
document.getElementById('btn-copy').addEventListener('click', function () {
  var ta = document.getElementById('isi-pesan'), st = document.getElementById('copy-status');
  function done() { st.textContent = 'Pesan disalin. Tempel di WhatsApp atau SMS.'; }
  if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(ta.value).then(done, fallback); } else { fallback(); }
  function fallback() { ta.select(); try { document.execCommand('copy'); done(); } catch (e) { st.textContent = 'Salin manual dengan menekan lama pada teks.'; } }
});
</script>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
