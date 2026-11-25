# InstanFoto

InstanFoto mengirim foto acara ke HP tamu segera setelah sesi foto selesai. Fotografer menekan Mulai sesi, lalu Selesai dan tampilkan QR. QR muncul di layar yang menghadap tamu. Tamu memindai QR dan galeri foto langsung terbuka tanpa login dan tanpa pembayaran.

Tahap saat ini: M5 selesai dengan backend PHP. Berikutnya: deploy ke hosting PHP sesuai Bagian 12 di `docs/PRD.md`.

## Dokumen proyek

| File             | Isi                                                              |
| ---------------- | ---------------------------------------------------------------- |
| `docs/PRD.md`    | Kebutuhan produk, ID `FR`/`NFR`/`SEC`, milestone, dan pengujian. |
| `docs/DESIGN.md` | Arah desain: warna, tipografi, spacing, radius, dan komponen.     |
| `docs/RULES.md`  | Aturan anti-AI slop, Hard Gate, Purpose-Gate, dan Delivery Gate. |
| `docs/DEMO.md`   | Panduan demo lokal memakai kamera HP.                            |

Folder `docs/` diabaikan git oleh pola `docs*` di `.gitignore`.

## Arsitektur

```text
[Kamera] --USB/FTP--> [Folder laptop]
                           |
                    [Uploader Node.js] -- HTTPS + X-Api-Key --> [API PHP 8]
                           |                                          |
                    [Panel lokal :4000]                    [SQLite + folder uploads]
                           |                                          |
                     QR tampil di layar                         [Galeri Vue statis]
                           |                                          ^
                     tamu scan QR (HP)                          tamu buka /a/{id}
```

- Galeri dan panel: Vue 3, Vite, Pinia, Tailwind CSS. Hasil build statis di `dist/`.
- API: PHP 8.2 ke atas di `api/`. Tanpa framework dan tanpa Composer.
- Database: SQLite lewat PDO. Skema dibuat otomatis di `api/data/app.sqlite`.
- Foto: GD membuat versi web 2000px dan thumbnail 400px, disimpan di `api/data/uploads/` dan disajikan lewat `/api/uploads/...`.
- Uploader: Node.js di laptop fotografer, memantau folder kamera dan mengirim foto ke API.

Uploader, panel, dan halaman demo tetap berjalan di laptop. Hosting hanya menyajikan galeri statis dan API PHP.

## Prasyarat

- PHP 8.2 ke atas dengan ekstensi `gd` (JPEG), `pdo_sqlite`, `exif`, dan `mbstring`.
- Node.js sesuai `engines` di `package.json`, yaitu 22.18 ke atas atau 24.12 ke atas, untuk galeri, uploader, dan panel.
- npm.
- Untuk deploy: hosting PHP dengan Apache dan `mod_rewrite`, HTTPS, serta cron harian.

Periksa PHP dengan `php -m`. Di Windows, `php` harus ada di `PATH` agar `npm run api` bekerja.

## Menyiapkan konfigurasi

1. Salin `.env.example` menjadi `.env`. Isi `API_KEY` dengan `openssl rand -hex 32` dan `CRON_SECRET` dengan nilai acak panjang.
2. Salin `uploader/.env.example` menjadi `uploader/.env`. Pakai `API_KEY` yang sama dan `API_BASE_URL=http://127.0.0.1:8000/api` untuk lokal.
3. Kedua file asli diabaikan git. Jangan pernah meng-commit API key atau konfigurasi asli.
4. Di lokal, `api/data/app.sqlite` dan `api/data/uploads/` dibuat otomatis dan tidak perlu diatur.

## Menjalankan di lokal

Dari akar repo, buka dua terminal:

```sh
npm ci
npm run api
```

```sh
npm run dev
```

`npm run api` menjalankan server PHP bawaan di `http://127.0.0.1:8000`. Vite mem-proxy `/api` ke port itu, jadi galeri dan panel memakai path yang sama seperti di produksi.

Build dan pratinjau:

```sh
npm run build
npm run preview
```

`npm run preview` juga mem-proxy `/api` ke port 8000. Jalankan `npm run api` lebih dulu.

Periksa konfigurasi dengan:

```sh
npm run check-env
```

## Menjalankan uploader

Isi `uploader/.env`, lalu dari akar repo:

```sh
node uploader/index.js
```

Uploader mendengarkan panel hanya di `127.0.0.1:4000` dan memantau `WATCH_DIR`. Jalankan `npm run build` sekali, lalu buka `http://127.0.0.1:4000/panel`. Dua tombolnya adalah Mulai sesi dan Selesai dan tampilkan QR. Tombol Selesai menunggu 3 detik, menunggu antrean kosong, memanggil API, membuat QR lebar 500px dengan margin 2, dan menampilkannya penuh layar. Tidak ada printer, login, atau pembayaran.

Endpoint `/api/start`, `/api/finish`, dan `/api/status` memeriksa Host, Origin, dan header `X-Panel: 1` (SEC-56). `PANEL_HOST` selain `127.0.0.1` atau `localhost` ditolak saat startup (SEC-55). QR sesi terakhir disimpan di `uploader/last-qr.json` agar tombol Tampilkan kembali QR tetap berfungsi setelah halaman dimuat ulang. Lokasi `queue.json`, `uploader.log`, dan `last-qr.json` bisa dipindah lewat `QUEUE_FILE`, `LOG_FILE`, dan `LAST_QR_FILE` untuk pengujian. Panel memakai view Vue di `src/views/PanelView.vue`, bukan HTML terpisah.

## Demo lokal pakai kamera HP

Panduan lengkap ada di `docs/DEMO.md`. HP dan laptop harus berada di Wi-Fi yang sama. QR lokal memakai alamat IP LAN laptop, bukan `localhost`.

1. Pastikan build sudah ada: `npm run build`.
2. Jalankan:

   ```powershell
   powershell -ExecutionPolicy Bypass -File demo/start-local.ps1
   ```

   Skrip menyalakan API PHP, Vite di semua antarmuka, uploader, dan halaman kamera HP. Jika IP salah, tentukan manual dengan `-Ip 192.168.1.10`.

3. Buka panel di laptop: `http://127.0.0.1:4000/panel`.
4. Ambil foto dari HP, salin ke `.demo/foto-hp`, atau buka `http://IP-LAPTOP:4001/phone`.
5. Klik Mulai sesi, tunggu status Terkirim bertambah, lalu klik Selesai dan tampilkan QR.
6. Pindai QR, buka foto, lalu Unduh foto.
7. Hentikan dengan `powershell -ExecutionPolicy Bypass -File demo/stop-local.ps1`.

## Endpoint API

Basis path selalu `/api`, baik di lokal maupun di hosting.

| Method dan path                  | Auth    | Hasil                                  |
| -------------------------------- | ------- | -------------------------------------- |
| `POST /api/sessions`             | API key | 201 `{id}`                             |
| `POST /api/sessions/{id}/photos` | API key | 201 `{ok:true, file}`                  |
| `POST /api/sessions/{id}/finish` | API key | 200 `{url}`                            |
| `GET /api/albums/{id}`           | Publik  | 200 `{photos:[{id,web,thumb}]}`        |
| `DELETE /api/sessions/{id}`      | API key | 204                                    |
| `GET /api/health`                | Publik  | 200 `{ok:true}`                        |
| `GET /api/cron/cleanup`          | Bearer  | 200 `{deleted, retentionDays}`         |

Kunci API dikirim lewat header `X-Api-Key`. Tidak ada cookie, login, atau pembayaran. Body JSON dibatasi 4 KB. Upload dibatasi 4 MB; uploader sudah mengecilkan foto ke sisi 2000px sebelum dikirim.

## Deploy ke hosting PHP

1. Arahkan DocumentRoot domain ke `api/public`. Folder `api/data/` berada di luar DocumentRoot sehingga tidak bisa diakses langsung.
2. Aktifkan `mod_rewrite` Apache. Konfigurasi rewrite dan header keamanan sudah ada di `api/public/.htaccess`; batas upload ada di `api/public/.user.ini`.
3. Salin hasil `npm run build` (`dist/`) ke `api/public/`. `index.php` menyajikan `index.html` untuk rute SPA seperti `/a/{id}`.
4. Isi `.env` di server, atau Environment Variables PHP-FPM, dengan `API_KEY`, `BASE_URL`, dan `CRON_SECRET`. Jangan menaruh `.env` di dalam `api/public/`.
5. Pastikan `api/data/` dan `api/data/uploads/` dapat ditulis proses PHP, tetapi tidak dapat diakses lewat URL.
6. Tambahkan cron harian:

   ```sh
   curl -fsS -H "Authorization: Bearer $CRON_SECRET" https://domain-anda/api/cron/cleanup
   ```

   Di lokal, `npm run cleanup` menjalankan logika yang sama.
7. Buka `/api/health` untuk memastikan API hidup, lalu jalankan `npm run check-env` di server.
8. Di laptop fotografer, ubah `uploader/.env`: `API_BASE_URL=https://domain-anda/api` dan `API_KEY` sama dengan server. Jalankan ulang `node uploader/index.js`.

Vercel Functions tidak menjalankan PHP, jadi `vercel.json` dan backend Node di `server/` sudah dihapus. Galeri statis tetap bisa dipasang di hosting apa pun asalkan `/api` diteruskan ke PHP.

## Hardening dan retensi

- Header keamanan dikirim oleh `api/src/Security.php` untuk semua respons API. `api/public/.htaccess` menambahkan HSTS dan header yang sama untuk aset statis.
- Rate limit berjalan di SQLite: 60 permintaan album per menit dengan burst 30, 120 permintaan tulis per menit dengan burst 40. Setelah 10 kegagalan autentikasi dalam 10 menit, permintaan tulis dari IP itu ditolak 429. IP tidak disimpan mentah, hanya sidik jadi `sha256(IP)`.
- Log ditulis ke log PHP sebagai satu baris per kejadian, tanpa body, API key, atau isi foto.
- Retensi dijalankan lewat cron ke `GET /api/cron/cleanup` dengan `Authorization: Bearer CRON_SECRET`. Di lokal, `npm run cleanup` menjalankan logika yang sama.
- `GET /api/health` hanya mengembalikan `{"ok":true}`.
- Hapus album lewat `DELETE /api/sessions/{id}` dengan `X-Api-Key`, mengembalikan 204 tanpa body dan ikut menghapus file di disk.
- Foto di-decode lalu di-encode ulang oleh GD. EXIF, GPS, dan payload yang ditempel setelah JPEG hilang; nama file selalu dibuat server.

## Menguji

```sh
npx vitest run
npm run test:e2e
npm run format
npm run check-env
npm run test:api
node uploader/tests/smoke.mjs
node uploader/tests/panel-smoke.mjs
```

`test:api` menyalakan dan mematikan server PHP sendiri, memakai port 8123 dan direktori sementara. Uji uploader memakai port panel 4010 dan file antrean sementara, jadi tidak mengganggu uploader yang sedang berjalan di port 4000.

## Hasil uji

Backend PHP diuji pada 25 November 2026 dengan PHP 8.4.27 dan Node.js 26.13.1.

| Uji                        | Hasil | Bukti singkat                                                 |
| -------------------------- | ----- | ------------------------------------------------------------- |
| `npm run test:api`         | PASS  | 37 pemeriksaan, termasuk T-01 sampai T-11, FR-03, FR-04       |
| `uploader/tests/smoke.mjs` | PASS  | antrean, retry saat API mati, restart tanpa duplikat          |
| `uploader/tests/panel-smoke.mjs` | PASS | panel, QR, proteksi Host/Origin/X-Panel                    |
| `npm run check-env`        | PASS  | PHP, GD, EXIF, PDO SQLite, dan direktori data                 |

## Catatan migrasi dari JavaScript ke PHP

- `server/` dan `api/[...route].js` dihapus. Backend sekarang ada di `api/src/`, dengan entry `api/public/index.php`.
- Express, `multer`, `sharp`, `@libsql/client`, dan `@vercel/blob` tidak lagi dipakai backend. `sharp`, `express`, dan `qrcode` tetap dipakai uploader di laptop.
- Skema database tetap sama, tetapi lokasi data pindah dari `server/data/` ke `api/data/`.
- Konfigurasi `.env` tetap kompatibel. `DATA_DIR` kini default ke `api/data`.
- Cron Vercel diganti cron hosting ke `/api/cron/cleanup`.
- Tes API dipindah dari `server/tests/` ke `api/tests/`. Helper uji uploader ada di `uploader/tests/helpers.mjs`.

## Catatan

- v1 tidak memakai printer, akun tamu, login, maupun pembayaran.
- QR ditampilkan penuh di layar dan langsung membuka album sesi yang baru selesai.
- Semua teks antarmuka memakai bahasa Indonesia. Nama variabel, fungsi, dan komentar kode memakai bahasa Inggris.