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

CREATE TABLE users (
  id INT(11) NOT NULL AUTO_INCREMENT,
  username VARCHAR(255) NOT NULL,
  nama_lengkap VARCHAR(255) NOT NULL,
  nip_nisn VARCHAR(255) DEFAULT NULL,
  password VARCHAR(255) NOT NULL,
  role ENUM('user','admin','superadmin') DEFAULT 'user',
  PRIMARY KEY (id)
);
