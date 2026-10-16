# Rakit

Aplikasi pendamping pencatatan Posyandu. Kader memfoto tabel di buku register, AI membaca foto menjadi **draf**, kader memeriksa baris yang ragu, lalu data yang dikonfirmasi menjadi tren, daftar perhatian, rekap, **PDF**, dan **XLSX**.

> Status: MVP hackathon (versi 3). Pakai **data sintetis** untuk demo. Hasil AI adalah draf yang harus diperiksa manusia. Aplikasi ini bukan pengganti buku fisik dan bukan alat diagnosis.

## Yang membedakannya dari "foto jadi teks"
- **Penanda per sel dan selisih dari catatan sebelumnya.** Angka yang salah baca tapi tampak wajar (BB 17,4 setelah 11,2) ditandai.
- **Saran nama dan penanganan nama kembar.** Dua anak bernama sama tidak digabung. Wajib dibedakan dengan no. daftar atau tanggal lahir sebelum dikonfirmasi.
- **Tren berat badan** dari data terkonfirmasi: halaman Perlu perhatian, detail anak dengan grafik, draf pesan ke orang tua tanpa diagnosis.
- **Tiga jenis formulir** (buku balita, register ibu hamil, register lansia). Menambah jenis baru cukup satu entri di `includes/forms.php`.
- **Bukti terukur**: skrip evaluasi yang menghitung akurasi per kolom dan kesalahan diam-diam (`eval/`).

## Stack
PHP 8.1+ (native, PDO), MySQL/MariaDB, HTML/CSS/JS dengan Bootstrap 5 (CDN), Gemini API (dipanggil dari backend). Tanpa Composer.
Ekstensi PHP: `pdo_mysql`, `curl`, `fileinfo`, `mbstring`, `zip` (untuk XLSX; jika tidak aktif, tombol berganti ke CSV).

## Menjalankan (Windows + Laragon)
1. Salin folder ke `C:\laragon\www\kader-cukup-foto`, jalankan Apache dan MySQL.
2. Instalasi baru: impor `database/schema.sql` (HeidiSQL: File, Run SQL file). **Database lama:** `php database/migrate.php` (aman diulang, data tidak dihapus).
3. Salin `.env.example` menjadi `.env`, sesuaikan `DB_*`.
4. Buka `http://localhost/kader-cukup-foto/`, daftarkan Posyandu, catat ID dan kode akses (tampil sekali).
5. Untuk mencoba semua fitur: `DEMO_SEED_ENABLED=true` di `.env`, lalu tekan **Muat data demo** di Beranda, atau `php database/seed_demo.php`.

Tanpa Laragon: `php -S localhost:8000` dari folder proyek.

## Kamera
Halaman Foto punya tiga jalur: **Buka kamera** (kamera langsung di halaman: webcam atau kamera HP, ganti kamera, ulangi, saran cahaya dan fokus), **Pilih dari galeri**, dan **kamera bawaan HP** (cadangan di perangkat sentuh).
Kamera langsung butuh **izin kamera** dan alamat **HTTPS atau localhost**. Di HP lewat alamat jaringan lokal berbasis HTTP, browser memblokirnya; aplikasi menawarkan kamera bawaan HP sebagai gantinya.

## Mengaktifkan Gemini
```
OCR_MODE=gemini
GEMINI_API_KEY=isi_api_key_anda
GEMINI_MODEL=gemini-2.5-flash
OCR_TWO_PASS=false   # true = baca dua kali; kolom yang berbeda ditandai ragu (biaya API 2x)
```
Cek nama model di Google AI Studio. Tanpa API key aplikasi memakai **mode demo** (data contoh sintetis, berlabel jelas). Memakai Gemini mewajibkan kader mencentang persetujuan pengiriman foto.

## Alur
Daftar/masuk, **Foto** (pilih jenis formulir, kamera atau galeri), **Periksa & koreksi** (kartu per baris, filter "Perlu dicek"), **Konfirmasi**, lalu **Perhatian**, **Anak**, **Rekap**, dan **Unduh PDF / XLSX**.
Sesi terkonfirmasi bisa **dibuka kembali** (alasan wajib; baris kembali jadi draf dan keluar dari rekap dan tren sampai dikonfirmasi ulang). Semuanya tercatat di **Jejak aktivitas**.

## Nama kembar
Identitas ditentukan oleh nomor pendaftaran, atau nama + tanggal lahir. Nama saja tidak pernah menyatukan riwayat.
- Dua anak atau lebih bernama sama tanpa pembeda: layar periksa menampilkan pilihan ("Siti Aminah, no. 001, lahir 14/02/2025") dan **konfirmasi diblokir** sampai dibedakan.
- Satu anak bernama sama: disarankan, tidak dipakai otomatis.
- Dua baris dengan nama dan periode sama pada satu halaman: ditandai bila tidak ada no. daftar atau tanggal lahir yang membedakan. Anak kembar sungguhan lolos selama no. atau tanggal lahirnya berbeda.
- Daftar anak, Perhatian, dan Beranda menampilkan pembeda untuk nama kembar.

## Jenis formulir
| Formulir | Kolom | Tren | Status kolom |
|---|---|---|---|
| Buku penimbangan balita | BB, TB/PB, lingkar kepala, LiLA | Ya | Sudah dipakai tim |
| Register ibu hamil | Usia kehamilan, BB, TB, LiLA, tekanan darah | Tidak | **Contoh**, cocokkan dengan buku asli |
| Register Posyandu lansia | BB, TB, tekanan darah, gula darah | Tidak | **Contoh**, cocokkan dengan buku asli |

Formulir selain balita hanya dibaca, divalidasi (batas keras salah ketik, tanpa klaim medis), dan diekspor. Rekap dan ekspornya terpisah per formulir.

## Ekspor
| Format | Isi |
|---|---|
| **PDF** (`export_pdf.php`) | A4 lanskap: kop, ringkasan, tabel dengan judul berulang, label Prioritas/Cek, catatan, kolom tanda tangan, nomor halaman. Dibuat di server tanpa pustaka PDF. |
| **XLSX** (`export_xlsx.php`) | Sheet *Laporan* (berformat, siap cetak A4: pita judul, header, baris selang-seling, tanggal dan angka asli, filter, baris beku), *Ringkasan*, dan *Data* (kolom mesin). Teks berawalan `=` `+` `-` `@` tidak dijalankan sebagai rumus. |
| CSV | Hanya cadangan bila ekstensi `zip` belum aktif |

Skema kolom adalah skema internal, **bukan** format impor ASIK atau e-PPGBM.

## Prinsip yang diterapkan
- Hasil OCR selalu **draf**; tidak masuk rekap, tren, atau ekspor sebelum dikonfirmasi.
- Nilai tidak terbaca = `NULL` (bukan 0) dan ditandai. Nilai di luar batas keras dikosongkan lalu ditandai; di luar kisaran umum atau selisih besar hanya diberi peringatan.
- Hasil baca asli OCR (`ocr_snapshot`) disimpan terpisah dari data yang dikoreksi.
- Rekap memakai **periode pengukuran** (`YYYY-MM`), bukan tanggal unggah.
- **Kehadiran tidak disimpulkan** dari ada atau tidaknya nama.
- Penanda tren adalah bantuan memilih kunjungan, bukan diagnosis. Aturan (BB sama atau lebih rendah dari catatan sebelumnya; 1 kali = Cek, 2 kali berturut-turut = Prioritas) dan ambang selisih (`DELTA_RULES`) adalah **heuristik** yang harus divalidasi bersama bidan.
- Status gizi (z-score) **belum dihitung**: butuh tabel standar resmi yang diverifikasi tenaga kesehatan.

## Evaluasi (bukti untuk deck)
```
php eval/run.php --mode=mock                  # uji mandiri skrip (BUKAN hasil baca foto)
php eval/run.php --mode=gemini [--two-pass]   # butuh GEMINI_API_KEY
```
Siapkan pasangan `NAMA.jpg` + `NAMA.csv` di `eval/samples/`; lihat `eval/README.md`.

## Pengujian
```
php tests/run.php                                   # tes unit (134), tanpa database
node tests/js/imagequality.test.js                  # tes kualitas foto
php -S 127.0.0.1:8080 -t . &                        # lalu, dengan database kosong dari schema.sql:
python3 tests/smoke.py http://127.0.0.1:8080        # uji HTTP end-to-end (95), butuh: pip install pypdf openpyxl
python3 tests/e2e_camera.py http://127.0.0.1:8080 PSY-XXXXXXXX KODE   # uji kamera di Chromium (34), butuh: pip install playwright
```
Uji kamera memakai kamera palsu bawaan Chromium (aliran video sintetis). Jika CDN Bootstrap tidak terjangkau di mesin uji, set `KCF_OFFLINE_ASSETS`.

## Keamanan
- `posyandu_id` acak dan kode akses acak kriptografis (`password_hash`) terpisah; kode tampil sekali; bisa diganti di Lainnya, Akun.
- Rate limiting login, sesi kedaluwarsa 2 jam, `session_regenerate_id`, cookie HttpOnly + SameSite.
- PDO prepared statements; semua query (anak, ekspor, PDF) dibatasi `posyandu_id` dari sesi. Diuji: Posyandu lain mendapat 404.
- CSRF di setiap form yang mengubah data; validasi file di server; nama file acak; foto lewat `photo.php` (wajib login).
- Kode akses **dipakai bersama**: sistem mencatat apa dan kapan, bukan siapa. Untuk data nyata gunakan akun individual, HTTPS, `UPLOAD_DIR` di luar web root, dan izin dari Puskesmas.

## Batasan
- Akurasi pembacaan pada tulisan tangan asli **belum terukur**. Jalankan `eval/` pada foto sungguhan sebelum mengklaim angka.
- Pembacaan Gemini, mode dua kali baca, dan `eval --mode=gemini` belum diuji dengan layanan asli.
- Kamera diuji dengan kamera palsu Chromium dan emulasi layar HP, **bukan** di HP fisik. Uji di perangkat Anda.
- XLSX diperiksa dengan openpyxl dan dirender di LibreOffice; belum dibuka di Microsoft Excel.
- PDF memakai huruf standar Helvetica (aksara Latin). Karakter di luar Windows-1252 tampil sebagai `?`.
- Kolom ibu hamil dan lansia adalah contoh yang belum dicocokkan dengan formulir asli.
- Ambang peringatan kualitas foto (`assets/js/imagequality.js`) adalah nilai awal dari gambar sintetis.
- Belum ada: crop foto per sel, z-score, integrasi ke ASIK atau e-PPGBM, login per orang.

## Struktur
```
config/ · includes/ (auth, forms, validation, suggest, trends, charts, export, xlsx, pdf, two_pass, eval_metrics, demo_seed, row_form, ...)
services/ (Gemini, mock) · assets/ (css/theme.css, js) · database/ (schema.sql, migrate.php, seed_demo.php)
eval/ · tests/ · docs/
Halaman: dashboard, upload, review, confirm, attention, anak, anak_detail, recap, export_pdf, export_xlsx, export_csv,
         history, session_detail, account, login, register
```
