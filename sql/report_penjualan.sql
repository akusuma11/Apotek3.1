-- =====================================================
-- REPORT PENJUALAN - View & Query Terintegrasi
-- Menggabungkan data PENJUALAN (kasir/apoteker) dan
-- PEMESANAN ONLINE (pelanggan) yang sudah DIBAYAR.
-- Jalankan setelah db_apotek.sql ter-import.
-- =====================================================
USE db_apotek;

-- ---------- Drop view lama jika ada ----------
DROP VIEW IF EXISTS v_report_penjualan;
DROP VIEW IF EXISTS v_report_penjualan_detail;
DROP VIEW IF EXISTS v_report_harian;
DROP VIEW IF EXISTS v_report_obat_terlaris;
DROP VIEW IF EXISTS v_report_kategori;

-- =====================================================
-- 1) VIEW UTAMA: Semua transaksi penjualan terintegrasi
--    sumber: 'kasir'  -> tabel penjualan
--            'online' -> tabel pemesanan (status_bayar = 'dibayar')
-- =====================================================
CREATE OR REPLACE VIEW v_report_penjualan AS
SELECT
  CONCAT('K-', p.id)        AS ref_id,
  'kasir'                   AS sumber,
  p.id                      AS trx_id,
  p.no_nota                 AS no_transaksi,
  p.tgl_penjualan           AS tgl_transaksi,
  DATE(p.tgl_penjualan)     AS tgl,
  u.nama_lengkap            AS petugas,
  pel.nama_lengkap          AS pelanggan,
  'lunas'                   AS status_bayar,
  'selesai'                 AS status_pesanan,
  NULL                      AS metode_bayar,
  p.total                   AS total,
  (SELECT COUNT(*) FROM penjualan_detail d WHERE d.penjualan_id = p.id) AS jml_item
FROM penjualan p
LEFT JOIN users u  ON u.id = p.apoteker_id
LEFT JOIN users pel ON pel.id = p.pelanggan_id
UNION ALL
SELECT
  CONCAT('O-', o.id)        AS ref_id,
  'online'                  AS sumber,
  o.id                      AS trx_id,
  o.no_pesanan              AS no_transaksi,
  o.tgl_pesanan             AS tgl_transaksi,
  DATE(o.tgl_pesanan)       AS tgl,
  'Sistem Online'           AS petugas,
  COALESCE(o.nama_penerima, pl.nama_lengkap) AS pelanggan,
  o.status_bayar            AS status_bayar,
  o.status_pesanan          AS status_pesanan,
  o.metode_bayar            AS metode_bayar,
  o.total                   AS total,
  (SELECT COUNT(*) FROM pemesanan_detail d WHERE d.pemesanan_id = o.id) AS jml_item
FROM pemesanan o
LEFT JOIN users pl ON pl.id = o.pelanggan_id
WHERE o.status_bayar = 'dibayar'
  AND o.status_pesanan <> 'dibatalkan';

-- =====================================================
-- 2) DETAIL ITEM TERJUAL (gabungan)
-- =====================================================
CREATE OR REPLACE VIEW v_report_penjualan_detail AS
SELECT
  'kasir'                  AS sumber,
  p.id                     AS trx_id,
  p.no_nota                AS no_transaksi,
  DATE(p.tgl_penjualan)    AS tgl,
  d.obat_id,
  ob.kode_obat,
  ob.nama_obat,
  ob.kategori,
  d.jumlah,
  d.harga,
  d.subtotal
FROM penjualan p
JOIN penjualan_detail d ON d.penjualan_id = p.id
JOIN obat ob ON ob.id = d.obat_id
UNION ALL
SELECT
  'online'                 AS sumber,
  o.id                     AS trx_id,
  o.no_pesanan             AS no_transaksi,
  DATE(o.tgl_pesanan)      AS tgl,
  d.obat_id,
  ob.kode_obat,
  ob.nama_obat,
  ob.kategori,
  d.jumlah,
  d.harga,
  d.subtotal
FROM pemesanan o
JOIN pemesanan_detail d ON d.pemesanan_id = o.id
JOIN obat ob ON ob.id = d.obat_id
WHERE o.status_bayar = 'dibayar'
  AND o.status_pesanan <> 'dibatalkan';

-- =====================================================
-- 3) REKAP HARIAN
-- =====================================================
CREATE OR REPLACE VIEW v_report_harian AS
SELECT
  tgl,
  COUNT(*)                                       AS jml_transaksi,
  SUM(CASE WHEN sumber='kasir'  THEN total ELSE 0 END) AS total_kasir,
  SUM(CASE WHEN sumber='online' THEN total ELSE 0 END) AS total_online,
  SUM(total)                                     AS total_omzet
FROM v_report_penjualan
GROUP BY tgl
ORDER BY tgl DESC;

-- =====================================================
-- 4) OBAT TERLARIS
-- =====================================================
CREATE OR REPLACE VIEW v_report_obat_terlaris AS
SELECT
  obat_id,
  kode_obat,
  nama_obat,
  kategori,
  SUM(jumlah)    AS total_terjual,
  SUM(subtotal)  AS total_pendapatan
FROM v_report_penjualan_detail
GROUP BY obat_id, kode_obat, nama_obat, kategori
ORDER BY total_terjual DESC;

-- =====================================================
-- 5) REKAP PER KATEGORI
-- =====================================================
CREATE OR REPLACE VIEW v_report_kategori AS
SELECT
  COALESCE(kategori,'(Tanpa Kategori)') AS kategori,
  SUM(jumlah)   AS total_terjual,
  SUM(subtotal) AS total_pendapatan
FROM v_report_penjualan_detail
GROUP BY kategori
ORDER BY total_pendapatan DESC;

-- =====================================================
-- CONTOH QUERY REPORT
-- =====================================================
-- Total omzet bulan ini:
-- SELECT SUM(total) FROM v_report_penjualan
--   WHERE tgl BETWEEN DATE_FORMAT(CURDATE(),'%Y-%m-01') AND CURDATE();
--
-- 10 obat terlaris:
-- SELECT * FROM v_report_obat_terlaris LIMIT 10;
--
-- Omzet harian 30 hari terakhir:
-- SELECT * FROM v_report_harian WHERE tgl >= CURDATE() - INTERVAL 30 DAY;
