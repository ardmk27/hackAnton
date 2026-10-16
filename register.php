<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

if (is_logged_in()) {
    redirect('dashboard.php');
}

$pdo = db();
$name = '';

// Tampilan sekali setelah registrasi: kode akses hanya ditampilkan di sini.
if (isset($_SESSION['new_credentials'])) {
    $cred = $_SESSION['new_credentials'];
    unset($_SESSION['new_credentials']);
    $pageTitle = 'Posyandu terdaftar';
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="kcf-auth">
      <div class="brand-row"><?= brand_mark(40) ?><div><h1>Posyandu berhasil didaftarkan</h1><div class="muted small"><?= e($cred['name']) ?></div></div></div>
      <div class="kcf-banner is-draft"><?= icon('alert', 20) ?><div><strong>Catat dan simpan kedua kode ini sekarang.</strong> Kode akses <u>hanya ditampilkan satu kali</u> dan tidak bisa dilihat lagi (hanya bisa diganti dengan kode baru setelah masuk).</div></div>
      <div class="kcf-card"><div class="kcf-card-body">
        <div class="form-label">ID Posyandu</div>
        <div class="mb-3"><?= code_box($cred['code'], 'ID Posyandu') ?></div>
        <div class="form-label">Kode akses (rahasia)</div>
        <div class="mb-3"><?= code_box($cred['access'], 'kode akses') ?></div>
        <p class="small muted">ID Posyandu adalah identitas, bukan rahasia. Kode akses adalah kunci masuk, jangan dibagikan ke pihak yang tidak berwenang. Kode dipakai bersama oleh pengguna Posyandu, sehingga sistem tidak dapat membedakan siapa yang melakukan perubahan.</p>
        <a href="login.php" class="btn btn-brand w-100">Lanjut ke halaman masuk</a>
      </div></div>
    </div>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $name = clean_text($_POST['name'] ?? '', 100) ?? '';
    if (mb_strlen($name) < 3) {
        flash('danger', 'Nama Posyandu minimal 3 karakter.');
    } else {
        // Batasi pendaftaran berulang dari satu IP (anti-spam sederhana)
        $st = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND posyandu_code = 'REGISTER' AND attempted_at > (NOW() - INTERVAL 1 HOUR)");
        $st->execute([client_ip()]);
        if ((int)$st->fetchColumn() >= 10) {
            flash('danger', 'Terlalu banyak pendaftaran dari alamat ini. Coba lagi nanti.');
        } else {
            $access = generate_access_code();
            $code = generate_posyandu_code($pdo);
            $pdo->prepare('INSERT INTO posyandu (posyandu_code, name, credential_hash) VALUES (?, ?, ?)')
                ->execute([$code, $name, hash_access_code($access)]);
            $pdo->prepare("INSERT INTO login_attempts (ip, posyandu_code) VALUES (?, 'REGISTER')")
                ->execute([client_ip()]);
            $_SESSION['new_credentials'] = ['name' => $name, 'code' => $code, 'access' => $access];
            redirect('register.php');
        }
    }
}

$pageTitle = 'Daftarkan Posyandu';
require __DIR__ . '/includes/landing.php';
require __DIR__ . '/includes/header.php';
?>
<div class="kcf-landing">
  <div class="kcf-authcol">
    <div class="kcf-auth">
      <div class="brand-row d-lg-none"><?= brand_mark(40) ?><div><h1>Daftarkan Posyandu</h1><div class="muted small">Sistem membuat ID dan kode akses acak.</div></div></div>
      <h1 class="d-none d-lg-block h3 mb-1">Daftarkan Posyandu</h1>
      <p class="d-none d-lg-block muted">Sistem membuat ID Posyandu dan kode akses acak untuk Anda.</p>
      <div class="kcf-card"><div class="kcf-card-body">
        <form method="post" autocomplete="off">
          <?= csrf_field() ?>
          <div class="mb-3">
            <label class="form-label" for="name">Nama Posyandu</label>
            <input class="form-control" id="name" name="name" maxlength="100" required value="<?= e($name) ?>" placeholder="mis. Posyandu Melati 3">
          </div>
          <button class="btn btn-brand btn-lg w-100">Daftarkan</button>
        </form>
        <hr>
        <p class="mb-0 small">Sudah terdaftar? <a class="fw-bold" href="login.php">Masuk</a></p>
      </div></div>
    </div>
  </div>
  <?php render_landing_pitch(); ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
