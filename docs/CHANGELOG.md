# Perubahan versi 3

## Rebrand: Rakit
- Nama aplikasi menjadi Rakit; logo bento hijau, palet (67C090, DDF4E7, 124170, 26667F, FAF8F4) dan font Instrument Serif/Sans sesuai style guide.
- Tombol Salin di samping ID Posyandu dan kode akses (halaman daftar dan akun), dengan cadangan untuk HTTP non-HTTPS.

**Wajib untuk database lama:** `php database/migrate.php` (kolom baru `gestational_weeks`, `bp_systolic`, `bp_diastolic`, `blood_glucose`, `peserta.form_type`).

## Ditambahkan
- Kamera langsung di halaman Foto (webcam atau HP), ganti kamera, ulangi, saran cahaya dan fokus, pesan galat yang jelas, plus galeri dan kamera bawaan HP sebagai cadangan. Penyebab keluhan sebelumnya: atribut `capture` pada input file diabaikan di desktop.
- Unduh PDF sungguhan (`export_pdf.php`, tanpa pustaka) dan XLSX berformat lengkap (`export_xlsx.php`, tiga sheet). CSV tinggal cadangan.
- Tiga jenis formulir (buku balita, register ibu hamil, register lansia), dipilih lewat kartu di halaman Foto. Rekap dan ekspor terpisah per formulir.
- Penanganan nama kembar: pilihan anak di layar periksa, konfirmasi diblokir bila tidak bisa dibedakan, deteksi baris ganda dalam satu halaman, pembeda di daftar.
- Halaman masuk dan daftar baru dengan ilustrasi, tombol Foto menonjol di navigasi HP, sambutan untuk Posyandu baru.

## Diperbaiki
- Pencocokan nama + tanggal lahir kini memakai normalisasi yang sama di layar dan saat menyimpan (sebelumnya bisa menghasilkan anak ganda).
- Baris tanpa no. daftar tetapi nama + tanggal lahir cocok kini dikenali meski anak itu sudah punya no.
- Tanggal lahir sebelum 2000 ditolak untuk semua formulir; sekarang batasnya per formulir.

## Dihapus
- `report.php` (halaman cetak) digantikan PDF sungguhan.


**Wajib untuk database lama:** `php database/migrate.php`.

## Produk
- Tren BB, halaman Perlu perhatian, Daftar anak, Detail anak (grafik SVG, riwayat, draf pesan tanpa diagnosis).
- Review baru: kartu per baris, penanda per sel, filter "Perlu dicek", "Sudah saya periksa", saran nama, konteks catatan sebelumnya, peringatan selisih, peringatan unggahan ganda.
- Buka kembali sesi terkonfirmasi untuk revisi (alasan wajib) + Jejak aktivitas.
- Hitungan koreksi: berapa sel yang diubah kader dari hasil baca AI (di Beranda dan detail sesi).
- Ekspor XLSX dan laporan cetak A4 (PDF lewat browser); rekap per periode dengan ringkasan perubahan BB.
- Unggah: foto dikecilkan di browser, saran kualitas (gelap/silau/buram).
- Data demo sintetis (tombol di Beranda dan `database/seed_demo.php`).
- Mode dua kali baca (`OCR_TWO_PASS`), `uncertain_fields` dari model.

## Tampilan
- Tema "Tinta Biru", huruf Atkinson Hyperlegible, ikon SVG menggantikan emoji, navigasi bawah di HP, status memakai ikon + teks (tidak hanya warna), target sentuh 44px.

## Teknis
- Kolom baru: `pengukuran.flag_fields`, `ocr_snapshot`, `corrected_fields`, `human_checked`; `sesi_foto.reopened_at`; tabel `koreksi_log`.
- Skrip evaluasi `eval/run.php`; 88 tes unit, 62 uji HTTP, 6 tes JS.

## Yang tidak berubah
Alur keamanan (kode akses, CSRF, pembatasan per Posyandu), skema CSV, aturan "jangan menebak".
