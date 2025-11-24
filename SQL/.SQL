-- CREATE TABLE users (
--     id INT AUTO_INCREMENT PRIMARY KEY,
--     username VARCHAR(50) UNIQUE NOT NULL,
--     nama_lengkap VARCHAR(100) NOT NULL,
--     nip_nisn VARCHAR(50),
--     password VARCHAR(255) NOT NULL,
--     role ENUM('user','admin','superadmin') DEFAULT 'user',
--     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
-- );

-- -- Cek apakah admin sudah ada
-- SELECT * FROM users WHERE role='admin';

-- -- Cek apakah superadmin sudah ada
-- SELECT * FROM users WHERE role='superadmin';

-- tabel untuk user login

CREATE TABLE users (
  id INT(11) NOT NULL AUTO_INCREMENT,
  username VARCHAR(255) NOT NULL,
  nama_lengkap VARCHAR(255) NOT NULL,
  nip_nisn VARCHAR(255) DEFAULT NULL,
  password VARCHAR(255) NOT NULL,
  role ENUM('user','admin','superadmin') DEFAULT 'user',
  PRIMARY KEY (id)
);


-- -- tabel untuk presensi user login

-- CREATE TABLE presensi (
--   id INT AUTO_INCREMENT PRIMARY KEY,
--   user_id INT NOT NULL,
--   tanggal DATE NOT NULL,
--   jam TIME NOT NULL,
--   status ENUM('MASUK','PULANG','IZIN','PULANG_CEPAT') NOT NULL,
--   latitude DOUBLE NOT NULL,
--   longitude DOUBLE NOT NULL,
--   keterangan TEXT,
--   foto VARCHAR(255),
--   approve_status ENUM('PENDING','APPROVED','REJECTED') DEFAULT 'PENDING',
--   created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
-- );

-- tabel untuk absensi dengan fitur selfie dan lokasi

CREATE TABLE absensi (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  jenis ENUM('Masuk','Pulang','Izin','Pulang Cepat'),
  keterangan TEXT,
  selfie VARCHAR(255),
  latitude VARCHAR(100),
  longitude VARCHAR(100),
  status ENUM('Pending','Disetujui','Ditolak') DEFAULT 'Pending',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id)
);

