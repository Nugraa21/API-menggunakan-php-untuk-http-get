<?php
// Menyertakan file proteksi.php untuk membatasi akses halaman.
include "proteksi.php";

// encryption.php - Kelas untuk enkripsi dan dekripsi data menggunakan AES-256-CBC
class Encryption {

    // Menyimpan kunci rahasia sepanjang 32 karakter untuk algoritma AES-256.
    private static $key = "SkadutaPresensi2025SecureKey1234"; // 32 karakter

    // Fungsi untuk mengenkripsi data plaintext menjadi ciphertext.
    public static function encrypt($data) {

        // Membuat Initialization Vector (IV) acak sepanjang 16 byte.
        $iv = openssl_random_pseudo_bytes(16);

        // Melakukan proses enkripsi data menggunakan algoritma AES-256-CBC.
        $encrypted = openssl_encrypt($data, 'AES-256-CBC', self::$key, OPENSSL_RAW_DATA, $iv);

        // Menggabungkan IV dan hasil enkripsi lalu mengubahnya ke format Base64.
        return base64_encode($iv . $encrypted);
    }

    // Fungsi untuk mendekripsi data ciphertext menjadi plaintext.
    public static function decrypt($data) {

        // Mengubah data dari format Base64 kembali ke bentuk binary.
        $data = base64_decode($data);

        // Menghentikan proses jika data tidak valid atau gagal didekode.
        if ($data === false) return false;

        // Mengambil 16 byte pertama dari data sebagai Initialization Vector (IV).
        $iv = substr($data, 0, 16);

        // Mengambil sisa data setelah IV sebagai ciphertext.
        $encrypted = substr($data, 16);

        // Melakukan proses dekripsi menggunakan key dan IV yang sama seperti enkripsi.
        return openssl_decrypt($encrypted, 'AES-256-CBC', self::$key, OPENSSL_RAW_DATA, $iv);
    }
}
?>