<?php
declare(strict_types=1);

/** Panel pengantar di halaman masuk/daftar: janji produk, tiga langkah, dan ilustrasi register + hasil baca (data contoh). */
function render_landing_pitch(): void
{
    ?>
    <section class="kcf-pitch" aria-label="Tentang Rakit">
      <h2 class="kcf-pitch-title">Foto register, lalu tahu siapa yang perlu dikunjungi.</h2>
      <p class="kcf-pitch-lead">Kader tetap menulis di buku seperti biasa. Aplikasi membaca fotonya, kader memeriksa baris yang ragu, dan hasilnya jadi tren, rekap, serta laporan yang siap diserahkan.</p>
      <ol class="kcf-pitch-steps">
        <li><span>1</span><div><strong>Foto</strong>halaman register setelah penimbangan</div></li>
        <li><span>2</span><div><strong>Periksa</strong>hanya sel yang ditandai ragu</div></li>
        <li><span>3</span><div><strong>Tindak lanjuti</strong>anak yang berat badannya tidak naik</div></li>
      </ol>
      <div class="kcf-illus" aria-hidden="true">
        <div class="paper">
          <div class="paper-head"><span>Nama</span><span>BB</span><span>TB</span></div>
          <div class="paper-row"><span>Siti Aminah</span><span>9,2</span><span>73</span></div>
          <div class="paper-row"><span>M. Rizky</span><span>9,0</span><span class="blur">7?</span></div>
          <div class="paper-row"><span>Dewi Lestari</span><span>7,8</span><span>68</span></div>
        </div>
        <div class="result">
          <div class="result-head"><?= icon('alert', 16) ?>Perlu perhatian</div>
          <div class="result-row"><strong>Muhammad Rizki</strong><span class="kcf-pill is-strong">Prioritas</span></div>
          <div class="result-note">Berat badan tidak naik 2 bulan berturut-turut</div>
          <div class="result-series"><span>Agu <b>9,2</b></span><span>Sep <b>9,0</b></span><span>Okt <b>9,0</b></span></div>
        </div>
      </div>
      <p class="kcf-pitch-foot">Ilustrasi memakai data contoh. Hasil baca AI adalah draf yang diperiksa manusia dan bukan diagnosis.</p>
    </section>
    <?php
}
