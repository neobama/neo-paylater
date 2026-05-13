# Neo Paylater

Neo Paylater adalah web app lucu-lucuan untuk circle pertemanan yang sering patungan. App ini dibuat dengan Laravel + Filament, punya dashboard merah modern, dan fokus ke tracking hutang/piutang antar teman tanpa konsep group terpisah.

## Fitur utama

- Admin bisa membuat akun semua teman.
- Siapa pun bisa input bill manual dengan item per menu dan assignment per orang.
- Bill paket juga bisa dicatat sebagai 1 item lalu dibagi ke beberapa orang.
- Dashboard menampilkan total hutang, piutang, dan posisi net setelah cross-deduction.
- History tetap tersimpan per transaksi, jadi user bisa lihat asal hutang/piutang dari bill mana.
- Pelunasan tercatat sebagai entry terpisah sehingga histori tetap utuh.
- Import struk AI via Gemini membuat draft bill dari foto receipt untuk diedit lagi.
- Receipt/bill image disimpan di storage lokal server (`storage/app/public/receipts`), bukan cloud.

## Stack

- Laravel 13
- Filament 5
- SQLite untuk local MVP
- Tailwind / Vite
- Gemini API untuk parsing receipt

## Menjalankan project

```bash
composer install
npm install
php artisan key:generate
php artisan migrate:fresh --seed
php artisan storage:link
npm run build
composer dev
```

App akan tersedia di `http://127.0.0.1:8000` (login: `/login`).

Catatan local dev:
- Pastikan `APP_URL` juga memakai `http://127.0.0.1:8000` supaya upload receipt Filament/Livewire tidak bentrok host.
- Temporary upload Livewire diarahkan ke disk `public`, tetap lokal di server.

## Akun awal (seed)

Login memakai **username**, bukan email. Seeder hanya membuat satu admin:

- Username: `neo`
- Password: `L00kdown!~`

Teman lain dibuat lewat panel **Teman** setelah login sebagai admin.

## Deploy / upload ke server

Ringkasnya:

1. Upload source (tanpa `vendor`, tanpa `node_modules`; atau jalankan `composer install --no-dev --optimize-autoloader` di server).
2. Salin `.env.example` → `.env`, set `APP_KEY`, `APP_URL`, `APP_DEBUG=false`, database sesuai hosting.
3. `php artisan migrate --force` lalu `php artisan db:seed --force` jika ingin akun Neo dari seed.
4. `php artisan storage:link`
5. `npm ci && npm run build` (atau build di CI lalu upload folder `public/build`).
6. Pastikan web server mengarah ke folder `public/` dan PHP punya izin tulis ke `storage/` dan `bootstrap/cache/`.
7. **Upload receipt / AI**: Livewire menyimpan sementara di disk `public` (`storage/app/public/livewire-tmp`). Kalau di **lokal OK tapi production gagal** dengan pesan *failed to upload*, cek berurutan:
   - **`APP_URL`** harus **`https://`** domain yang sama dengan address bar (bukan `http://`). Kalau salah, signed URL upload Livewire bisa `http://` dan browser memblokir (**mixed content**) walau halaman HTTPS.
   - **Nginx** (atau NPM ke origin): `client_max_body_size 20m;` (default **1m** sering memutus body → file tidak sampai ke PHP).
   - **PHP-FPM**: `upload_max_filesize` dan `post_max_size` minimal **25M** (sedikit di atas batas Livewire di `config/livewire.php`), lalu reload **php-fpm**. Cek cepat: `php -i | grep -E 'upload_max|post_max'`.
   - **`php artisan storage:link`** dan izin tulis user **php-fpm** ke **`storage/`** (termasuk `storage/app/public/livewire-tmp`).

Login pengguna: `/login`.

## Konfigurasi Gemini

Isi `.env`:

```env
GEMINI_API_KEY=your_api_key_here
GEMINI_MODEL=gemini-2.5-flash
```

Kalau `GEMINI_API_KEY` belum diisi, flow manual tetap bisa dipakai dan import receipt AI akan gagal dengan pesan yang jelas.

## Core domain

- `bills`: header transaksi
- `bill_items`: item hasil input manual atau parsing AI
- `bill_splits`: assignment nominal item ke user
- `settlements`: pelunasan antar user
- `ledger_entries`: jejak normalized untuk summary netting dan history

Ringkasan dashboard dihitung dari `ledger_entries`, sedangkan history tetap menampilkan entry asli per bill atau pelunasan.

## Catatan storage bill

Upload receipt memakai disk `public` Laravel yang tetap tersimpan lokal di server/aplikasi ini. Jadi walaupun filenya bisa dibuka dari browser, file fisiknya tetap ada di local storage project, bukan di S3 atau layanan eksternal.
