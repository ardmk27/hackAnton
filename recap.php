<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/export.php';
require_once __DIR__ . '/includes/xlsx.php';

/**
 * Rekap: HANYA data terkonfirmasi, dikelompokkan menurut periode pengukuran
 * (bulan yang tertulis di tabel), bukan tanggal unggah. Dipisah per jenis formulir.
 */
$currentPosyandu = $p = require_login();
$pdo = db();
$pid = (int)$p['id'];

$form = form_key((string)($_GET['form'] ?? ''));
$def = form_def($form);
$periode = (string)($_GET['periode'] ?? '');
if ($periode !== '' && !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periode)) {
    $periode = '';
}
$tahun = (string)($_GET['tahun'] ?? '');
if (!preg_match('/^\d{4}$/', $tahun)) {
    $tahun = '';
}
$xlsxOk = SimpleXlsx::available();
$fq = $form === FORM_BALITA ? '' : '&form=' . urlencode($form);   // pertahankan pilihan formulir pada tautan

$pageTitle = 'Rekap';
require __DIR__ . '/includes/header.php';

// Pilihan jenis formulir
$st = $pdo->prepare("SELECT s.form_type, COUNT(*) AS n FROM pengukuran m JOIN sesi_foto s ON s.id = m.sesi_foto_id WHERE m.posyandu_id = ? AND m.verification_status = 'confirmed' GROUP BY s.form_type");
$st->execute([$pid]);
$formCounts = array_column($st->fetchAll(), 'n', 'form_type');
$renderTabs = function () use ($form, $formCounts) {
    echo '<div class="kcf-seg mb-3" role="group" aria-label="Jenis formulir">';
    foreach (form_registry() as $k => $f) {
        $n = (int)($formCounts[$k] ?? 0);
        echo '<a class="' . ($k === $form ? 'is-on' : '') . '" href="recap.php' . ($k === FORM_BALITA ? '' : '?form=' . urlencode($k)) . '"' . ($k === $form ? ' aria-current="true"' : '') . '>' . e($f['short']) . ' (' . $n . ')</a>';
    }
    echo '</div>';
};

if ($periode !== ''):
    $d = fetch_export_data($pdo, $pid, 'periode', $periode, $form);
    $att = $d['attention'];
    $trend = !empty($def['trend']);
    // Di layar, kolom ukur yang kosong untuk semua baris disembunyikan agar tabel muat. PDF dan XLSX tetap berstruktur tetap.
    $showCols = array_values(array_filter($d['columns'], function ($c) use ($d) {
        if (!isset($c['measure'])) {
            return true;
        }
        foreach ($d['rows'] as $r) {
            if (($r[$c['k']] ?? null) !== null) {
                return true;
            }
        }
        return false;
    }));
?>
  <p class="mb-2"><a class="fw-bold text-decoration-none" href="recap.php<?= $fq !== '' ? '?' . ltrim($fq, '&') : '' ?>"><?= icon('back', 18) ?> Semua periode</a></p>
  <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-3">
    <div>
      <h1 class="page-title h4 mb-1">Rekap <?= e(format_period($periode)) ?> <?= pill($def['short'], 'info') ?></h1>
      <p class="muted mb-0">Hanya data terkonfirmasi. Draf tidak dihitung.</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
      <a class="btn btn-brand" href="export_pdf.php?periode=<?= e($periode) ?><?= e($fq) ?>"><?= icon('printer', 18) ?>Unduh PDF</a>
      <?php if ($xlsxOk): ?>
        <a class="btn btn-quiet" href="export_xlsx.php?periode=<?= e($periode) ?><?= e($fq) ?>"><?= icon('sheet', 18) ?>Unduh XLSX</a>
      <?php else: ?>
        <a class="btn btn-quiet" href="export_csv.php?periode=<?= e($periode) ?><?= e($fq) ?>"><?= icon('download', 18) ?>Unduh CSV</a>
      <?php endif; ?>
    </div>
  </div>
  <?php if (!$def['verified']): ?><div class="kcf-banner is-info"><?= icon('info', 20) ?><div>Kolom formulir ini adalah <strong>contoh awal</strong>. Cocokkan dengan buku di Posyandu Anda sebelum memakainya untuk data nyata.</div></div><?php endif; ?>

  <?php if ($d['rows']): ?>
  <div class="row g-3 mb-3">
    <div class="col-6 col-md-3"><div class="kcf-card kcf-stat h-100"><div class="label">Catatan</div><div class="value"><?= count($d['rows']) ?></div></div></div>
    <div class="col-6 col-md-3"><div class="kcf-card kcf-stat h-100"><div class="label"><?= e(ucfirst($def['subject'])) ?> (identitas pasti)</div><div class="value"><?= (int)$d['unique_children'] ?></div></div></div>
    <?php if ($trend): ?>
      <div class="col-6 col-md-3"><div class="kcf-card kcf-stat h-100"><div class="label">BB naik</div><div class="value"><?= (int)$att['rising'] ?></div></div></div>
      <div class="col-6 col-md-3"><div class="kcf-card kcf-stat h-100" style="background:var(--kcf-amber-soft)"><div class="label">BB tidak naik</div><div class="value" style="color:var(--kcf-amber-ink)"><?= (int)$att['not_rising'] ?></div><a class="hint fw-bold" href="attention.php?periode=<?= e($periode) ?>">Lihat daftar</a></div></div>
    <?php endif; ?>
  </div>
  <?php if ($trend): ?>
  <div class="kcf-card mb-3"><div class="kcf-card-body">
    <div class="fw-bold mb-2">Perubahan berat badan dari catatan sebelumnya</div>
    <div role="img" aria-label="<?= (int)$att['rising'] ?> naik, <?= (int)$att['not_rising'] ?> tidak naik, <?= (int)$att['no_prev'] ?> belum ada pembanding" style="display:flex;height:20px;border-radius:6px;overflow:hidden;gap:3px">
      <?php if ($att['rising']): ?><div style="flex:<?= (int)$att['rising'] ?> 1 0;background:var(--kcf-green)"></div><?php endif; ?>
      <?php if ($att['not_rising']): ?><div style="flex:<?= (int)$att['not_rising'] ?> 1 0;background:repeating-linear-gradient(45deg,var(--kcf-red) 0,var(--kcf-red) 4px,var(--kcf-red-tint) 4px,var(--kcf-red-tint) 8px)"></div><?php endif; ?>
      <?php if ($att['no_prev']): ?><div style="flex:<?= (int)$att['no_prev'] ?> 1 0;background:#AEB7C4"></div><?php endif; ?>
    </div>
    <div class="d-flex flex-wrap gap-3 mt-2 small">
      <span><strong><?= (int)$att['rising'] ?></strong> naik</span>
      <span><strong><?= (int)$att['not_rising'] ?></strong> tidak naik (<?= (int)$att['counts']['prioritas'] ?> prioritas, <?= (int)$att['counts']['cek'] ?> cek)</span>
      <span><strong><?= (int)$att['no_prev'] ?></strong> belum punya catatan pembanding</span>
    </div>
  </div></div>
  <?php endif; ?>
  <?php endif; ?>

  <div class="kcf-card">
  <?php if (!$d['rows']): ?>
    <div class="kcf-empty">Tidak ada data terkonfirmasi untuk periode ini.</div>
  <?php else: ?>
    <div class="kcf-scroll"><table class="kcf-ledger">
      <thead><tr>
        <?php foreach ($showCols as $c): ?><th class="<?= in_array($c['t'], ['num', 'int', 'delta'], true) ? 'num' : '' ?>"><?= e($c['l']) ?></th><?php endforeach; ?>
        <th>Sesi</th>
      </tr></thead>
      <tbody>
      <?php foreach ($d['rows'] as $r): ?>
        <tr>
          <?php foreach ($showCols as $c): $txt = export_cell_text($c, $r); ?>
            <?php if ($c['k'] === 'nama_peserta'): ?>
              <td class="text-nowrap"><?php if ($r['peserta_id'] && $trend): ?><a class="row-link" href="anak_detail.php?id=<?= (int)$r['peserta_id'] ?>&amp;periode=<?= e($periode) ?>"><?= e($txt !== '' ? $txt : '—') ?></a><?php else: ?><?= e($txt !== '' ? $txt : '—') ?><?php endif; ?></td>
            <?php elseif ($c['t'] === 'level'): ?>
              <td><?= $r['_level'] === 'prioritas' ? pill('Prioritas', 'strong', 'alert') : ($r['_level'] === 'cek' ? pill('Cek', 'warn', 'info') : '') ?></td>
            <?php elseif ($c['t'] === 'delta'): ?>
              <td class="num"><?php if ($r['_delta'] === null) { echo '—'; } elseif ($r['_delta'] <= 0) { echo '<strong style="color:var(--kcf-red-ink)">' . e($txt) . '</strong>'; } else { echo e($txt); } ?></td>
            <?php else: ?>
              <td class="<?= in_array($c['t'], ['num', 'int'], true) ? 'num' : '' ?>"><?= e($txt !== '' ? $txt : '—') ?></td>
            <?php endif; ?>
          <?php endforeach; ?>
          <td><a class="row-link" href="session_detail.php?id=<?= (int)$r['sesi_foto_id'] ?>">#<?= (int)$r['sesi_foto_id'] ?></a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
  </div>
  <p class="muted small mt-3">Skema kolom XLSX adalah skema internal, bukan format impor ASIK atau e-PPGBM.<?= $trend ? ' Penanda bukan diagnosis.' : '' ?></p>

<?php else:
    $sql = "SELECT m.measurement_period AS periode, COUNT(DISTINCT m.sesi_foto_id) AS jumlah_sesi, COUNT(*) AS jumlah_catatan,
                   COUNT(DISTINCT m.peserta_id) AS peserta_unik
            FROM pengukuran m JOIN sesi_foto s ON s.id = m.sesi_foto_id
            WHERE m.posyandu_id = ? AND s.form_type = ? AND m.verification_status = 'confirmed' AND m.measurement_period IS NOT NULL";
    $params = [$pid, $form];
    if ($tahun !== '') {
        $sql .= ' AND m.measurement_period LIKE ?';
        $params[] = $tahun . '-%';
    }
    $sql .= ' GROUP BY m.measurement_period ORDER BY m.measurement_period DESC';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $periods = $st->fetchAll();

    $dupSt = $pdo->prepare(
        "SELECT periode, COUNT(*) AS n FROM (
            SELECT m.measurement_period AS periode, m.peserta_id FROM pengukuran m JOIN sesi_foto s ON s.id = m.sesi_foto_id
            WHERE m.posyandu_id = ? AND s.form_type = ? AND m.verification_status = 'confirmed' AND m.peserta_id IS NOT NULL AND m.measurement_period IS NOT NULL
            GROUP BY m.measurement_period, m.peserta_id HAVING COUNT(*) > 1
         ) d GROUP BY periode"
    );
    $dupSt->execute([$pid, $form]);
    $dups = array_column($dupSt->fetchAll(), 'n', 'periode');

    $years = $pdo->prepare("SELECT DISTINCT LEFT(m.measurement_period, 4) AS y FROM pengukuran m JOIN sesi_foto s ON s.id = m.sesi_foto_id WHERE m.posyandu_id = ? AND s.form_type = ? AND m.verification_status = 'confirmed' AND m.measurement_period IS NOT NULL ORDER BY y DESC");
    $years->execute([$pid, $form]);
    $yearList = array_column($years->fetchAll(), 'y');
    $rosterAll = !empty($def['trend']) ? load_roster($pdo, $pid, $form) : [];
?>
  <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-3">
    <div>
      <h1 class="page-title h4 mb-1">Rekap per periode</h1>
      <p class="muted mb-0">Dikelompokkan menurut bulan yang tertulis di tabel, bukan tanggal unggah.</p>
    </div>
    <form method="get" class="d-flex gap-2 align-items-end">
      <input type="hidden" name="form" value="<?= e($form) ?>">
      <div><label class="form-label mb-1" for="tahun">Tahun</label>
      <select class="form-select" id="tahun" name="tahun" onchange="this.form.submit()">
        <option value="">Semua</option>
        <?php foreach ($yearList as $y): ?><option value="<?= e($y) ?>" <?= $tahun === $y ? 'selected' : '' ?>><?= e($y) ?></option><?php endforeach; ?>
      </select></div>
    </form>
  </div>
  <?php $renderTabs(); ?>
  <div class="kcf-banner is-ok"><?= icon('check', 20) ?><div>Rekap hanya menghitung data <strong>terkonfirmasi</strong>. <?= e(ucfirst($def['subject'])) ?> yang tidak ada pada suatu periode <u>tidak</u> berarti tidak hadir.</div></div>

  <div class="kcf-card">
  <?php if (!$periods): ?>
    <div class="kcf-empty">Belum ada data terkonfirmasi untuk <?= e(mb_strtolower($def['label'])) ?>. Konfirmasi sebuah sesi agar muncul di sini.</div>
  <?php else: ?>
    <div class="kcf-scroll"><table class="kcf-ledger">
      <thead><tr><th>Periode</th><th class="num">Sesi</th><th class="num">Catatan</th><th class="num"><?= e(ucfirst($def['subject'])) ?> unik</th><?php if (!empty($def['trend'])): ?><th>Penanda BB tidak naik</th><?php endif; ?><th></th></tr></thead>
      <tbody>
      <?php foreach ($periods as $r): $a = !empty($def['trend']) ? attention_for_period($rosterAll, $r['periode']) : null; ?>
        <tr>
          <td><strong><?= e(format_period($r['periode'])) ?></strong>
            <?php if (!empty($dups[$r['periode']])): ?><br><?= pill((int)$dups[$r['periode']] . ' kemungkinan duplikat', 'warn', 'alert') ?><?php endif; ?></td>
          <td class="num"><?= (int)$r['jumlah_sesi'] ?></td>
          <td class="num"><?= (int)$r['jumlah_catatan'] ?></td>
          <td class="num"><?= (int)$r['peserta_unik'] ?></td>
          <?php if ($a !== null): ?>
          <td><?php if ($a['not_rising'] > 0): ?><?= $a['counts']['prioritas'] > 0 ? pill($a['counts']['prioritas'] . ' prioritas', 'strong', 'alert') . ' ' : '' ?><?= $a['counts']['cek'] > 0 ? pill($a['counts']['cek'] . ' cek', 'warn', 'info') : '' ?><?php else: ?><span class="muted small">tidak ada</span><?php endif; ?></td>
          <?php endif; ?>
          <td class="text-end text-nowrap"><a class="btn btn-quiet btn-sm" href="recap.php?periode=<?= e($r['periode']) ?><?= e($fq) ?>">Buka</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
  </div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
