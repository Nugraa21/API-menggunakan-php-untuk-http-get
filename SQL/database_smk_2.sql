-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Waktu pembuatan: 12 Jan 2026 pada 04.28
-- Versi server: 10.4.32-MariaDB
-- Versi PHP: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `database_smk_3`
--

-- --------------------------------------------------------

--
-- Struktur dari tabel `absensi`
--

CREATE TABLE `absensi` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `jenis` enum('Masuk','Pulang','Izin','Pulang Cepat','Penugasan_Masuk','Penugasan_Pulang','Penugasan_Full') DEFAULT NULL,
  `keterangan` text DEFAULT NULL,
  `informasi` text DEFAULT NULL,
  `dokumen` varchar(255) DEFAULT NULL,
  `selfie` varchar(255) DEFAULT NULL,
  `latitude` varchar(100) DEFAULT NULL,
  `longitude` varchar(100) DEFAULT NULL,
  `status` enum('Waiting','Disetujui','Ditolak') DEFAULT 'Waiting',
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data untuk tabel `absensi`
--


-- Buat test lihat keluaran data / backup data absensi

-- INSERT INTO `absensi` (`id`, `user_id`, `jenis`, `keterangan`, `informasi`, `dokumen`, `selfie`, `latitude`, `longitude`, `status`, `created_at`) VALUES
-- (1, 2, 'Masuk', '', '', '', 'selfie_2_1765774701.jpg', '-7.776319', '110.3672222', 'Disetujui', '2025-12-15 11:58:21'),
-- (5, 5, 'Masuk', '', '', '', 'selfie_5_1765843094.jpg', '-7.7763239', '110.3672214', 'Disetujui', '2025-12-16 06:58:14'),
-- (6, 2, 'Masuk', '', '', '', 'selfie_2_1765843175.jpg', '-7.7762454', '110.3672028', 'Disetujui', '2025-12-16 06:59:35'),
-- (9, 2, 'Pulang', '', '', '', 'selfie_2_1765852854.jpg', '-7.7763168', '110.3672243', 'Disetujui', '2025-12-16 09:40:54'),
-- (11, 8, 'Izin', 'sakit', '', 'dokumen_8_1765882196.pdf', '', '0', '0', 'Disetujui', '2025-12-16 17:49:56'),
-- (12, 9, 'Penugasan_Full', '', 'ada acara di UNY', 'dokumen_9_1765886067.pdf', '', '0', '0', 'Ditolak', '2025-12-16 18:54:27'),
-- (15, 12, 'Penugasan_Full', '', 'aa', 'dokumen_12_1765889584.pdf', '', '0', '0', 'Disetujui', '2025-12-16 19:53:04'),
-- (16, 11, 'Penugasan_Full', '', 'rdd', 'dokumen_11_1765891385.pdf', '', '0', '0', 'Disetujui', '2025-12-16 20:23:05'),
-- (17, 12, 'Penugasan_Full', '', 'weffvd', 'dokumen_12_1765891410.pdf', '', '0', '0', 'Disetujui', '2025-12-16 20:23:30'),
-- (18, 13, 'Izin', 'acf', '', 'dokumen_13_1765892494.pdf', '', '0', '0', 'Disetujui', '2025-12-16 20:41:34'),
-- (19, 2, 'Masuk', '', '', '', 'selfie_2_1765932710.jpg', '-7.7763024', '110.3672208', 'Disetujui', '2025-12-17 07:51:50'),
-- (20, 5, 'Masuk', '', '', '', 'selfie_5_1765932767.jpg', '-7.7763293', '110.3672261', 'Disetujui', '2025-12-17 07:52:47'),
-- (21, 11, 'Penugasan_Masuk', '', 'penugasan test aja', 'dokumen_11_1765933015.pdf', '', '0', '0', 'Disetujui', '2025-12-17 07:56:55'),
-- (22, 12, 'Penugasan_Masuk', '', 'test aja', 'dokumen_12_1765933050.pdf', '', '0', '0', 'Disetujui', '2025-12-17 07:57:30'),
-- (23, 9, 'Penugasan_Full', '', 'paniti acara pesona', 'dokumen_9_1765933090.pdf', '', '0', '0', 'Disetujui', '2025-12-17 07:58:10'),
-- (24, 13, 'Izin', 'sakit njeer', '', 'dokumen_13_1765933191.pdf', '', '0', '0', 'Disetujui', '2025-12-17 07:59:51'),
-- (25, 8, 'Penugasan_Full', '', 'ada acara di mana', 'dokumen_8_1765933226.pdf', '', '0', '0', 'Disetujui', '2025-12-17 08:00:26'),
-- (26, 7, 'Penugasan_Full', '', 'fgn', 'dokumen_7_1765933451.pdf', '', '0', '0', 'Ditolak', '2025-12-17 08:04:11'),
-- (32, 2, 'Pulang', '', '', '', 'selfie_2_1766385785.jpg', '-7.7761933', '110.3672383', 'Disetujui', '2025-12-22 13:43:05'),
-- (35, 9, 'Pulang', '', '', '', 'selfie_9_1766387151.jpg', '-7.7763273', '110.3672275', 'Disetujui', '2025-12-22 14:05:51'),
-- (36, 5, 'Pulang', '', '', '', 'selfie_5_1766387162.jpg', '-7.7763277', '110.3672155', 'Disetujui', '2025-12-22 14:06:02'),
-- (37, 5, 'Masuk', '', '', '', 'selfie_5_1766449783.jpg', '-7.7763067', '110.3672132', 'Disetujui', '2025-12-23 07:29:43'),
-- (38, 9, 'Masuk', '', '', '', 'selfie_9_1766449828.jpg', '-7.7763116', '110.3672101', 'Disetujui', '2025-12-23 07:30:28'),
-- (39, 2, 'Masuk', '', '', '', 'selfie_2_1766450519.jpg', '-7.7763006', '110.3672052', 'Disetujui', '2025-12-23 07:41:59');

-- --------------------------------------------------------

--
-- Struktur dari tabel `login_tokens`
--

CREATE TABLE `login_tokens` (
  `user_id` int(11) NOT NULL,
  `token` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data untuk tabel `login_tokens`
--


-- Buat test lihat keluaran data / backup data login_tokens

-- INSERT INTO `login_tokens` (`user_id`, `token`, `expires_at`) VALUES
-- (1, 'aa43d5062a03e596c4166cf67f85fccbfb0cfc9f5dd9d00f9b1a57964e27424b', '2026-01-19 13:01:55'),
-- (2, '4af41e593a8a5bb9f707977a34d06414b3d3f091a3079d6e515f5ae65cc26037', '2026-01-19 15:01:50'),
-- (5, '66acfddbce334ff197ec9c2d05cafd0367638cb7a78e01077b98e5690c0d1d20', '2026-01-15 17:25:23'),
-- (6, 'bd1f843997592f2693b4a7909f313416c0f65de8dadfc5bcf241dc60a58c6310', '2026-01-19 15:02:57'),
-- (7, '85492a1050937f4e5e64a8b14eba001f904192dcd4a812f351fea659b4f105e4', '2026-01-16 02:04:02'),
-- (8, 'bb7c25adac318a237148aeee135f6cb0507d1ceb108a3d7ab15f6f66213c8f26', '2026-01-16 02:00:07'),
-- (9, 'b1937aed21095396708134ca2e3dca1bbb767fe031fd703b47993e39ddf07369', '2026-01-16 01:57:48'),
-- (10, '8299300a28db44cc0a5a5bfa10360fddba8b6472a3a4c0421d41a8995afc9c2e', '2026-01-15 13:33:09'),
-- (11, '24466d3bdf74b7fcd9b0f26956390af7e17c366a8b3a71038fafbda4e6ecd178', '2026-01-16 01:56:30'),
-- (12, '17ba275cf304911a87ad3a457ba3eaf1b81cf139d86b47ab5533ba1ddeb75102', '2026-01-16 01:57:13'),
-- (13, 'd7520f10957a6c3c415c428fe391740cd73286fc99e65b847ae2b4ae286068cf', '2026-01-16 01:59:32');

-- --------------------------------------------------------

--
-- Struktur dari tabel `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(255) NOT NULL,
  `nama_lengkap` varchar(255) NOT NULL,
  `nip_nisn` varchar(255) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('user','admin','superadmin') DEFAULT 'user',
  `status` varchar(50) DEFAULT 'Karyawan',
  `device_id` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data untuk tabel `users`
--



-- Insert data admin awal

INSERT INTO `users` (`id`, `username`, `nama_lengkap`, `nip_nisn`, `password`, `role`, `status`, `device_id`) VALUES
(1, 'nugra', 'nugra admin', '000000001', '$2y$10$j7J3rcgqir1XR0Xv7LhfHOmInPq2E7mxLwdFafH9eEpfJDhATGS82', 'superadmin', 'Staff Lain', NULL),
(6, 'admin', 'admin', '00000002', '$2y$10$fzCGE9og8o9b0Vm7NHYPwOK7CAb2NleP2HwJ7GbynxQ09b9Hy45QC', 'admin', 'Karyawan', NULL);

-- Buat test lihat keluaran data / backup data users

-- INSERT INTO `users` (`id`, `username`, `nama_lengkap`, `nip_nisn`, `password`, `role`, `status`, `device_id`) VALUES
-- (1, 'nugra', 'nugra admin', '000000001', '$2y$10$j7J3rcgqir1XR0Xv7LhfHOmInPq2E7mxLwdFafH9eEpfJDhATGS82', 'superadmin', 'Staff Lain', NULL),
-- (2, 'ludang', 'ludang prasetyo n', '225510017', '$2y$10$Lji.epDxlhL8yK5JvCvdxOBq76yg4GnE1kVMQh4Bciz6rViApXcAy', 'user', 'Karyawan', 'AP3A.240905.015.A2'),
-- (5, 'rohmat', 'Rohmat cahyo', '225510019', '$2y$10$kYBfc/w8l9TbwV8TJZXkFeiselhQ6jAPqpvF8mh7GEvyvoCHPT3Xu', 'user', 'Karyawan', 'UP1A.231005.007'),
-- (6, 'admin', 'admin', '00000002', '$2y$10$fzCGE9og8o9b0Vm7NHYPwOK7CAb2NleP2HwJ7GbynxQ09b9Hy45QC', 'admin', 'Karyawan', NULL),
-- (7, 'yoga', 'yoga saputra', '', '$2y$10$wJ3MjdtiifTHfVkuXhRIPu/.4BSE5FPDVpTvusz.wDpBy9f.gsXeu', 'user', 'Karyawan', NULL),
-- (8, 'fadrian', 'M fadrian', '', '$2y$10$S1q0984D20yxrcgS/aCRAenHc3YZ04O5sKBdNZEIBcMQmGSXBzhbK', 'user', 'Karyawan', NULL),
-- (9, 'rifki', 'M rifki', '225510012', '$2y$10$IJIpr2dMe/QlQCQpiw8VVunXxKrxYJvPcbwRUBBoC8qdEqnVWzA9y', 'user', 'Karyawan', 'AP3A.240617.008'),
-- (10, 'johan', 'johan maulana', '', '$2y$10$VOAvQrzCexAR/exrgNtXNe3dHLn4DplWVouAEFqvlB1SY7FyM.i5O', 'user', 'Karyawan', NULL),
-- (11, 'zio', 'asyrof hafiz', '225510005', '$2y$10$XonOTAFG9svOnikjjKNY6ufy7IFw6hjKEsSwgiD/o.4ci2orFkYaW', 'user', 'Guru', NULL),
-- (12, 'aldi', 'aldi saputra', '2255100018', '$2y$10$rQaQ24vdMXWtkYmu80QJY.fveEKbldXuCy1JuWqaWOcvj0uFduZ8.', 'user', 'Guru', NULL),
-- (13, 'hiban', 'Ibnu hibban', '', '$2y$10$FP5./ZlhplQ3NL04tWn6K.tLCCNbd/17d0.bev1bqzmG75vo29SX.', 'user', 'Karyawan', NULL);


--
-- Indexes for dumped tables
--

--
-- Indeks untuk tabel `absensi`
--
ALTER TABLE `absensi`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indeks untuk tabel `login_tokens`
--
ALTER TABLE `login_tokens`
  ADD PRIMARY KEY (`user_id`);

--
-- Indeks untuk tabel `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_username` (`username`),
  ADD UNIQUE KEY `unique_device` (`device_id`);

--
-- AUTO_INCREMENT untuk tabel yang dibuang
--

--
-- AUTO_INCREMENT untuk tabel `absensi`
--
ALTER TABLE `absensi`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=40;

--
-- AUTO_INCREMENT untuk tabel `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- Ketidakleluasaan untuk tabel pelimpahan (Dumped Tables)
--

--
-- Ketidakleluasaan untuk tabel `absensi`
--
ALTER TABLE `absensi`
  ADD CONSTRAINT `absensi_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Ketidakleluasaan untuk tabel `login_tokens`
--
ALTER TABLE `login_tokens`
  ADD CONSTRAINT `login_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
