CREATE DATABASE IF NOT EXISTS kelompok_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE kelompok_db;

CREATE TABLE IF NOT EXISTS anggota (
    route_id VARCHAR(10) PRIMARY KEY,
    nim VARCHAR(15) NOT NULL,
    nama VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO anggota (route_id, nim, nama) VALUES
('m0', '103012400147', 'Zhafir Muhammad Tectona Grandito'),
('m1', '103012400188', 'Shahrul Putra Wiguna'),
('m2', '103012400032', 'Akbar Fawwaz'),
('m3', '103012400224', 'Muhammad Adira Gumillar'),
('m4', '103012400247', 'Muhammad Fazil Ataurrahman')
ON DUPLICATE KEY UPDATE
    nim = VALUES(nim),
    nama = VALUES(nama);

CREATE TABLE IF NOT EXISTS shout (
    id INT AUTO_INCREMENT PRIMARY KEY,
    route_id VARCHAR(10) NOT NULL,
    nama VARCHAR(100) NOT NULL,
    pesan TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_shout_anggota
        FOREIGN KEY (route_id) REFERENCES anggota(route_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
