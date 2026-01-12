# Skaduta Presensi - Backend API (PHP)

Backend API untuk aplikasi presensi digital sekolah **Skaduta Presensi** berbasis Flutter. Dibangun dengan PHP native + MySQL, dilengkapi enkripsi AES-256, autentikasi token, dan proteksi keamanan tingkat tinggi.

## Fitur Utama
- Registrasi & login user (siswa/guru/karyawan)
- Presensi masuk/pulang dengan validasi lokasi (radius sekolah)
- Izin, pulang cepat, dan penugasan khusus (dengan dokumen & keterangan)
- Persetujuan presensi oleh admin (Pending → Disetujui/Ditolak)
- Riwayat presensi per user & admin
- Rekap presensi bulanan
- Manajemen user (CRUD oleh superadmin)
- Enkripsi data sensitif (AES-256-CBC)
- Proteksi akses langsung ke file sensitif (config.php tidak bisa dibuka)

## Teknologi
- PHP 8+
- MySQL / MariaDB
- ngrok (untuk testing mobile)
- AES-256-CBC Encryption

## Struktur Folder
```
backendapk/
├── config.php              # Koneksi DB & keamanan utama
├── proteksi.php            # Proteksi akses langsung (404 untuk file sensitif)
├── encryption.php          # Enkripsi/dekripsi data
├── login.php               # Login + generate token
├── register.php            # Registrasi user
├── absen.php               # Submit presensi (dengan validasi lokasi)
├── presensi_approve.php    # Approve/Tolak presensi
├── absen_admin_list.php    # List semua presensi (admin)
├── get_users.php           # List user (admin/superadmin)
├── delete_user.php         # Hapus user
├── update_user.php         # Edit user & password
└── ... (file API lainnya)
```

## Instalasi & Setup
1. Upload semua file ke server atau folder web (misal: `/backendapk/`)
2. Buat database MySQL, import struktur tabel (lihat di bawah)
3. Edit `config.php`:
   ```php
   $host = "localhost";
   $user = "root";
   $pass = "password_kamu";
   $db   = "database_smk_4";
   ```
4. Jalankan ngrok:
   ```bash
   ngrok http 80
   ```
   Catat URL ngrok (contoh: `https://abc123.ngrok-free.app/backendapk/`)

## Struktur Database (SQL)
```sql
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE,
    nama_lengkap VARCHAR(100),
    nip_nisn VARCHAR(50),
    password VARCHAR(255),
    role ENUM('user', 'admin', 'superadmin') DEFAULT 'user'
);

CREATE TABLE absensi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    jenis VARCHAR(50),
    keterangan TEXT,
    informasi TEXT,
    dokumen VARCHAR(255),
    selfie VARCHAR(255),
    latitude DECIMAL(10,8),
    longitude DECIMAL(11,8),
    status ENUM('Pending', 'Disetujui', 'Ditolak') DEFAULT 'Pending',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE login_tokens (
    user_id INT PRIMARY KEY,
    token VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

## Keamanan
- API Key wajib (`X-App-Key`)
- Random delay anti brute-force
- Prepared statement anti SQL Injection
- `proteksi.php` blokir akses langsung ke file sensitif
- Error display dimatikan (tidak bocor info server)

## API Endpoints
- `POST /login.php` → Login + token
- `POST /register.php` → Daftar akun
- `POST /absen.php` → Submit presensi
- `POST /presensi_approve.php` → Approve/Tolak
- `GET /absen_admin_list.php` → List semua presensi
- `GET /get_users.php` → List user

## Lisensi
Project ini untuk keperluan internal sekolah. Tidak untuk komersial tanpa izin.


<!-- Catatan -->

# Catatan penting untuk Linux

> Digunakan untuk menyiapkan folder upload **dokumen** dan **selfie** di server Linux (Apache/Nginx)
> Pastikan dijalankan sebagai **root**

---

## 1️⃣ Masuk ke direktori web

```bash
cd /var/www
```

---

## 2️⃣ (Opsional) Hapus folder lama jika ada

```bash
rm -rf /var/www/dokumen
rm -rf /var/www/selfie
```

---

## 3️⃣ Buat ulang folder upload

```bash
mkdir -p /var/www/dokumen /var/www/selfie
```

---

## 4️⃣ Set owner ke web server

```bash
chown -R www-data:www-data /var/www/dokumen
chown -R www-data:www-data /var/www/selfie
```

---

## 5️⃣ Set permission aman (read & write)

```bash
chmod -R 775 /var/www/dokumen
chmod -R 775 /var/www/selfie
```

---

## 6️⃣ Set sticky group (file upload ikut www-data)

```bash
chmod g+s /var/www/dokumen
chmod g+s /var/www/selfie
```

---

## 7️⃣ Verifikasi permission

```bash
ls -ld /var/www/dokumen /var/www/selfie
```

**Output yang benar:**

```
drwxrwsr-x www-data www-data dokumen
drwxrwsr-x www-data www-data selfie
```

---

## 8️⃣ Test write access (wajib)

```bash
touch /var/www/dokumen/test.txt
touch /var/www/selfie/test.txt
```

Jika tidak error → folder **siap digunakan untuk upload** ✅

---

## ⚠️ Catatan Keamanan

* ❌ Jangan gunakan `chmod 777`
* ✅ Gunakan `775 + www-data`
* ✅ Nama folder harus sama dengan path di PHP (`selfie`, bukan `selfe`)

---
