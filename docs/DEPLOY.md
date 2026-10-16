# Menjalankan agar bisa dibuka juri

Pilih salah satu. Yang paling aman untuk lomba: **video demo + repo + README langkah menjalankan**, dan link hosting hanya sebagai bonus.

## A. Lokal (Laragon)
Lihat README. Rekam video dari sini.

## B. Docker (belum diuji oleh penulis dokumen ini)
`Dockerfile` dan `docker-compose.yml` disertakan sebagai titik awal. Jalankan `docker compose up --build`, lalu buka `http://localhost:8080`. Periksa sendiri: ekstensi PHP, izin folder `storage/uploads`, dan `.env`.

## C. Hosting PHP + MySQL
Butuh PHP 8.1+, ekstensi `pdo_mysql curl fileinfo mbstring zip`, HTTPS (kamera browser butuh HTTPS), dan upload minimal 8 MB. Impor `database/schema.sql`, atur `.env`, lalu pindahkan `UPLOAD_DIR` ke luar web root. Isi `DEMO_SEED_ENABLED=true` hanya untuk instans demo.

## Sebelum dibuka ke orang lain
Gunakan hanya data sintetis. Jangan unggah foto atau data anak sungguhan ke instans demo.
