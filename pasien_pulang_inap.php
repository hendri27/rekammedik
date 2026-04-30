<?php

/**
 * Laporan Pasien Pulang RITL (Rawat Inap)
 * Database : PostgreSQL
 * PHP      : 5.6+ (kompatibel XAMPP lama)
 *
 * KEAMANAN & PERFORMA:
 * - Query pindah menggunakan subquery (bukan IN dengan ribuan param)
 * - Export Excel menggunakan streaming (flush per baris) → tidak habiskan RAM
 * - Tampilan web menggunakan paginasi (default 100 baris/halaman)
 * - Semua input di-sanitasi via PDO prepared statement
 * - Timeout query diset eksplisit
 * - memory_limit dinaikkan sementara hanya saat export
 */


// ─── Autentikasi ──────────────────────────────────────────────────────────────
// Aktifkan sesuai sistem auth yang digunakan di project ini.
// Contoh: cek session login. Uncomment dan sesuaikan dengan kebutuhan.
// session_start();
// if (empty($_SESSION['user_id'])) {
//     header('Location: login.php');
//     exit;
// }

// ─── Konfigurasi ──────────────────────────────────────────────────────────────
$db_host = '172.16.0.1';
$db_port = '5432';
$db_name = 'sirs';
$db_user = 'postgres';
$db_pass = '123456';

// Jumlah baris per halaman untuk tampilan web
define('ROWS_PER_PAGE', 50);

// ─── Koneksi Database ─────────────────────────────────────────────────────────
try {
  $pdo = new PDO(
    "pgsql:host=$db_host;port=$db_port;dbname=$db_name",
    $db_user,
    $db_pass,
    array(
      PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      // Timeout statement 60 detik — cegah query berat jalan tanpa batas
      PDO::ATTR_TIMEOUT            => 60,
    )
  );
  // Set statement timeout di sisi PostgreSQL (ms)
  $pdo->exec("SET statement_timeout = '60000'");
} catch (PDOException $e) {
  die("Koneksi database gagal: " . htmlspecialchars($e->getMessage()));
}

// ─── Sanitasi Input ───────────────────────────────────────────────────────────
// Validasi format tanggal YYYY-MM-DD — tolak input aneh
function sanitizeDate($input, $default)
{
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $input)) {
    // Pastikan tanggal valid (misal bukan 2026-02-31)
    $parts = explode('-', $input);
    if (checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0])) {
      return $input;
    }
  }
  return $default;
}

$tgl_awal  = sanitizeDate(
  isset($_GET['tgl_awal'])  ? $_GET['tgl_awal']  : '',
  date('Y-m-01')
);
$tgl_akhir = sanitizeDate(
  isset($_GET['tgl_akhir']) ? $_GET['tgl_akhir'] : '',
  date('Y-m-d')
);

// Halaman untuk paginasi (hanya dipakai mode tampilan, bukan export)
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * ROWS_PER_PAGE;

// ─── SQL Utama (dipakai baik tampilan maupun export) ─────────────────────────
//
// FIX UTAMA: fetchPindahMap sebelumnya menggunakan IN (:nr0,:nr1,...,:nr1165)
// yang tidak scalable. Diganti dengan subquery langsung di SQL sehingga
// semua data masuk dalam 1 query tanpa array parameter besar.
//
// Struktur hasil:
//   1 baris per pasien untuk data identitas/masuk/pulang
//   + kolom pindah_json berisi JSON array semua perpindahan pasien tsb
//
// JSON di-parse di PHP → tidak perlu query kedua sama sekali.
//
$sql_base = "
WITH pasien_pulang AS (
    SELECT no_reg, no_mr, nama_pas 
      FROM tx_reg 
      WHERE inap = 'Y' 
      AND tgl_pulang >= :tgl_awal ::date
      AND tgl_pulang < :tgl_akhir ::date + INTERVAL '1 day'
      AND closed_bed = 'Y' 
      AND kd_pastipe IN ('0213','0219','0216','0217','0201')
      ORDER BY no_reg
),
sep_bpjs AS (
    SELECT DISTINCT ON (b.no_reg) b.no_reg, b.no_sep
    FROM tx_reg_bpjs b
    INNER JOIN pasien_pulang pp ON pp.no_reg = b.no_reg
    ORDER BY b.no_reg
),
sep_noreg AS (
    SELECT DISTINCT ON (n.no_reg) n.no_reg, n.no_sep
    FROM tx_noreg_nosep n
    INNER JOIN pasien_pulang pp ON pp.no_reg = n.no_reg
    ORDER BY n.no_reg
),
kamar_masuk_s0 AS (
    SELECT DISTINCT ON (tk.no_reg)
           tk.no_reg,
           DATE(tk.tgl_masuk)               AS tgl_masuk,
           TO_CHAR(tk.tgl_masuk, 'HH24:MI') AS jam_masuk,
           td.ds_dep  AS nama_kamar_masuk,
           tk.kd_bed  AS no_kamar_masuk,
           tk.kd_klas AS kelas_masuk
    FROM tx_kamar tk
    INNER JOIN pasien_pulang pp ON pp.no_reg = tk.no_reg
    INNER JOIN kd_dep td ON td.kd_dep = tk.kd_dep
    WHERE tk.status = '0'
    ORDER BY tk.no_reg, tk.tgl_masuk ASC
),
kamar_masuk_s2 AS (
    SELECT DISTINCT ON (tk.no_reg)
           tk.no_reg,
           DATE(tk.tgl_masuk)               AS tgl_masuk,
           TO_CHAR(tk.tgl_masuk, 'HH24:MI') AS jam_masuk,
           td.ds_dep  AS nama_kamar_masuk,
           tk.kd_bed  AS no_kamar_masuk,
           tk.kd_klas AS kelas_masuk
    FROM tx_kamar tk
    INNER JOIN pasien_pulang pp ON pp.no_reg = tk.no_reg
    INNER JOIN kd_dep td ON td.kd_dep = tk.kd_dep
    WHERE tk.status = '2'
      AND tk.tgl_keluar >= :tgl_awal2 ::date
      AND tk.tgl_keluar <  :tgl_akhir2 ::date + INTERVAL '1 day'
    ORDER BY tk.no_reg, tk.tgl_masuk ASC
),
kamar_pulang AS (
    SELECT DISTINCT ON (tk.no_reg)
           tk.no_reg,
           DATE(tk.tgl_keluar)               AS tgl_pulang,
           TO_CHAR(tk.tgl_keluar, 'HH24:MI') AS jam_pulang,
           td.ds_dep  AS nama_kamar_pulang,
           tk.kd_bed  AS no_kamar_pulang,
           tk.kd_klas AS kelas_pulang
    FROM tx_kamar tk
    INNER JOIN pasien_pulang pp ON pp.no_reg = tk.no_reg
    INNER JOIN kd_dep td ON td.kd_dep = tk.kd_dep
    WHERE tk.status = '2'
      AND tk.tgl_keluar >= :tgl_awal3 ::date
      AND tk.tgl_keluar <  :tgl_akhir3 ::date + INTERVAL '1 day'
    ORDER BY tk.no_reg, tk.tgl_keluar DESC
),
-- FIX: Perpindahan diambil via JSON_AGG di PostgreSQL.
-- Tidak perlu query terpisah + IN (ribuan param).
-- Hasilnya 1 kolom JSON per pasien yang berisi semua perpindahan.
kamar_pindah_agg AS (
    SELECT
        tk.no_reg,
        JSON_AGG(
            JSON_BUILD_OBJECT(
                'tgl_pindah', DATE(tk.tgl_masuk),
                'jam_pindah', TO_CHAR(tk.tgl_masuk, 'HH24:MI'),
                'nama_kamar_pindah', td.ds_dep,
                'no_kamar_pindah',   tk.kd_bed,
                'kelas_pindah',      tk.kd_klas
            )
            ORDER BY tk.tgl_masuk ASC
        ) AS pindah_json
    FROM tx_kamar tk
    INNER JOIN pasien_pulang pp ON pp.no_reg = tk.no_reg
    INNER JOIN kd_dep td ON td.kd_dep = tk.kd_dep
    WHERE tk.status = '1'
    GROUP BY tk.no_reg
)
SELECT
    pp.no_reg,
    pp.no_mr,
    pp.nama_pas,
    COALESCE(sb.no_sep, sn.no_sep, 'Tidak ada data SEP') AS no_sep,
    COALESCE(km0.tgl_masuk,        km2.tgl_masuk)        AS tgl_masuk,
    COALESCE(km0.jam_masuk,        km2.jam_masuk)        AS jam_masuk,
    COALESCE(km0.nama_kamar_masuk, km2.nama_kamar_masuk) AS nama_kamar_masuk,
    COALESCE(km0.no_kamar_masuk,   km2.no_kamar_masuk)   AS no_kamar_masuk,
    COALESCE(km0.kelas_masuk,      km2.kelas_masuk)      AS kelas_masuk,
    kpu.tgl_pulang,
    kpu.jam_pulang,
    kpu.nama_kamar_pulang,
    kpu.no_kamar_pulang,
    kpu.kelas_pulang,
    -- Perpindahan dalam satu kolom JSON (NULL jika tidak ada)
    kpa.pindah_json
FROM pasien_pulang pp
LEFT JOIN sep_bpjs        sb   ON sb.no_reg   = pp.no_reg
LEFT JOIN sep_noreg       sn   ON sn.no_reg   = pp.no_reg
LEFT JOIN kamar_masuk_s0  km0  ON km0.no_reg  = pp.no_reg
LEFT JOIN kamar_masuk_s2  km2  ON km2.no_reg  = pp.no_reg
LEFT JOIN kamar_pulang    kpu  ON kpu.no_reg  = pp.no_reg
LEFT JOIN kamar_pindah_agg kpa ON kpa.no_reg  = pp.no_reg
";

$bind_base = array(
  ':tgl_awal'   => $tgl_awal,
  ':tgl_akhir'  => $tgl_akhir,
  ':tgl_awal2'  => $tgl_awal,
  ':tgl_akhir2' => $tgl_akhir,
  ':tgl_awal3'  => $tgl_awal,
  ':tgl_akhir3' => $tgl_akhir,
);

// ─── Helper Functions ─────────────────────────────────────────────────────────
function fmtDate($d)
{
  if (!$d) return '-';
  return date('d/m/Y', strtotime($d));
}
function val($v)
{
  return ($v === null || $v === '') ? '-' : htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}
function kelasBadge($k)
{
  if (!$k) return 'kelas';
  $n = preg_replace('/\D/', '', $k);
  return 'kelas kelas-' . ($n ? $n : '');
}
// Parse kolom pindah_json dari PostgreSQL ke array PHP
function parsePindah($json)
{
  if (!$json) return array();
  $arr = json_decode($json, true);
  return is_array($arr) ? $arr : array();
}

// ─── MODE EXPORT EXCEL (Streaming) ───────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'excel') {

  // Naikkan memory & waktu eksekusi hanya untuk export
  @ini_set('memory_limit', '256M');
  @set_time_limit(300);

  $filename = 'laporan_ritl_' . $tgl_awal . '_sd_' . $tgl_akhir . '.xls';
  header('Content-Type: application/vnd.ms-excel; charset=utf-8');
  header('Content-Disposition: attachment; filename="' . $filename . '"');
  header('Cache-Control: max-age=0');
  header('Pragma: no-cache');

  // Matikan output buffering → data langsung dikirim ke browser (streaming)
  // Ini mencegah PHP harus menampung seluruh output di RAM dulu
  if (ob_get_level()) ob_end_clean();

  echo "\xEF\xBB\xBF"; // BOM UTF-8
?>
  <html xmlns:o="urn:schemas-microsoft-com:office:office"
    xmlns:x="urn:schemas-microsoft-com:office:excel"
    xmlns="http://www.w3.org/TR/REC-html40">

  <head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <style>
      table {
        border-collapse: collapse;
      }

      th,
      td {
        border: 1px solid #999;
        padding: 3px 5px;
        font-size: 10pt;
        font-family: Arial;
        vertical-align: middle;
      }

      .hg {
        background: #2E74B5;
        color: #fff;
        font-weight: bold;
        text-align: center;
      }

      .hs {
        background: #DEEAF1;
        font-weight: bold;
        text-align: center;
        color: #1F3864;
      }

      .ns {
        color: #CC0000;
        font-style: italic;
      }

      .rp {
        background: #FFFACD;
      }

      .c {
        text-align: center;
      }

      .l {
        text-align: left;
      }

      .ti {
        font-size: 13pt;
        font-weight: bold;
        text-align: center;
      }
    </style>
  </head>

  <body>
    <table>
      <tr>
        <td colspan="19" class="ti">LAPORAN PASIEN PULANG RAWAT INAP (RITL)</td>
      </tr>
      <tr>
        <td colspan="19" class="c">Periode: <?php echo date('d/m/Y', strtotime($tgl_awal)); ?> s/d <?php echo date('d/m/Y', strtotime($tgl_akhir)); ?></td>
      </tr>
      <tr>
        <td colspan="19"></td>
      </tr>
      <tr>
        <th class="hg" rowspan="2">NO</th>
        <th class="hg" rowspan="2">NO SEP</th>
        <th class="hg" rowspan="2">NO RM</th>
        <th class="hg" rowspan="2">Nama Pasien</th>
        <th class="hg" colspan="5">Pasien Masuk RITL</th>
        <th class="hg" colspan="5">Diisi jika ada perpindahan</th>
        <th class="hg" colspan="5">Pasien Pulang RITL</th>
      </tr>
      <tr>
        <th class="hs">Tanggal</th>
        <th class="hs">Pukul</th>
        <th class="hs">Nama Ruang</th>
        <th class="hs">Nomor</th>
        <th class="hs">Kelas</th>
        <th class="hs">Tanggal</th>
        <th class="hs">Pukul</th>
        <th class="hs">Nama Ruang</th>
        <th class="hs">Nomor</th>
        <th class="hs">Kelas</th>
        <th class="hs">Tanggal</th>
        <th class="hs">Pukul</th>
        <th class="hs">Nama</th>
        <th class="hs">Nomor</th>
        <th class="hs">Kelas</th>
      </tr>
      <?php
      // Gunakan cursor / fetch satu per satu → RAM konstan tidak peduli jumlah baris
      $sql_export = $sql_base . " ORDER BY kpu.tgl_pulang ASC, pp.no_reg ASC";
      $stmt = $pdo->prepare($sql_export);
      $stmt->execute($bind_base);

      // Definisikan closure DI LUAR loop agar tidak dibuat ulang setiap iterasi
      $fT = function ($d) {
        return $d ? date('d/m/Y', strtotime($d)) : '-';
      };
      $v  = function ($x) {
        return ($x === null || $x === '') ? '-' : htmlspecialchars($x, ENT_QUOTES, 'UTF-8');
      };

      $total   = 0;
      $no_urut  = 0;
      while ($r = $stmt->fetch()) {
        $total++;
        $no_urut++;
        $pindah     = parsePindah($r['pindah_json']);
        $jml_pindah = count($pindah);
        $rowspan    = $jml_pindah > 1 ? $jml_pindah : 1;
        $isSepNull  = ($r['no_sep'] === 'Tidak ada data SEP');
        $sepClass   = $isSepNull ? 'ns l' : 'l';

        echo '<tr>';
        echo '<td class="c"' . ($rowspan > 1 ? ' rowspan="' . $rowspan . '"' : '') . '>' . $no_urut . '</td>';
        echo '<td class="' . $sepClass . '"' . ($rowspan > 1 ? ' rowspan="' . $rowspan . '"' : '') . '>' . $v($r['no_sep']) . '</td>';
        echo '<td class="c"' . ($rowspan > 1 ? ' rowspan="' . $rowspan . '"' : '') . '>' . $v($r['no_mr']) . '</td>';
        echo '<td class="l"' . ($rowspan > 1 ? ' rowspan="' . $rowspan . '"' : '') . '>' . $v($r['nama_pas']) . '</td>';
        // Masuk
        echo '<td class="c"' . ($rowspan > 1 ? ' rowspan="' . $rowspan . '"' : '') . '>' . $fT($r['tgl_masuk']) . '</td>';
        echo '<td class="c"' . ($rowspan > 1 ? ' rowspan="' . $rowspan . '"' : '') . '>' . $v($r['jam_masuk']) . '</td>';
        echo '<td class="c"' . ($rowspan > 1 ? ' rowspan="' . $rowspan . '"' : '') . '>' . $v($r['nama_kamar_masuk']) . '</td>';
        echo '<td class="c"' . ($rowspan > 1 ? ' rowspan="' . $rowspan . '"' : '') . '>' . $v($r['no_kamar_masuk']) . '</td>';
        echo '<td class="c"' . ($rowspan > 1 ? ' rowspan="' . $rowspan . '"' : '') . '>' . $v($r['kelas_masuk']) . '</td>';
        // Pindah baris pertama
        if (!empty($pindah)) {
          $p = $pindah[0];
          echo '<td class="c">' . $fT($p['tgl_pindah']) . '</td>';
          echo '<td class="c">' . $v($p['jam_pindah']) . '</td>';
          echo '<td class="c">' . $v($p['nama_kamar_pindah']) . '</td>';
          echo '<td class="c">' . $v($p['no_kamar_pindah']) . '</td>';
          echo '<td class="c">' . $v($p['kelas_pindah']) . '</td>';
        } else {
          echo '<td class="c">-</td><td class="c">-</td><td class="c">-</td><td class="c">-</td><td class="c">-</td>';
        }
        // Pulang
        echo '<td class="c"' . ($rowspan > 1 ? ' rowspan="' . $rowspan . '"' : '') . '>' . $fT($r['tgl_pulang']) . '</td>';
        echo '<td class="c"' . ($rowspan > 1 ? ' rowspan="' . $rowspan . '"' : '') . '>' . $v($r['jam_pulang']) . '</td>';
        echo '<td class="c"' . ($rowspan > 1 ? ' rowspan="' . $rowspan . '"' : '') . '>' . $v($r['nama_kamar_pulang']) . '</td>';
        echo '<td class="c"' . ($rowspan > 1 ? ' rowspan="' . $rowspan . '"' : '') . '>' . $v($r['no_kamar_pulang']) . '</td>';
        echo '<td class="c"' . ($rowspan > 1 ? ' rowspan="' . $rowspan . '"' : '') . '>' . $v($r['kelas_pulang']) . '</td>';
        echo '</tr>' . "\n";

        // Baris perpindahan ke-2, dst
        for ($i = 1; $i < $jml_pindah; $i++) {
          $p = $pindah[$i];
          echo '<tr class="rp">';
          echo '<td class="c">' . $fT($p['tgl_pindah']) . '</td>';
          echo '<td class="c">' . $v($p['jam_pindah']) . '</td>';
          echo '<td class="c">' . $v($p['nama_kamar_pindah']) . '</td>';
          echo '<td class="c">' . $v($p['no_kamar_pindah']) . '</td>';
          echo '<td class="c">' . $v($p['kelas_pindah']) . '</td>';
          echo '</tr>' . "\n";
        }

        // Flush setiap 50 baris → kirim ke browser, bebaskan buffer
        if ($total % 50 === 0) flush();
      }
      $stmt->closeCursor();
      ?>
      <tr>
        <td colspan="19" style="text-align:right;font-size:9pt;color:#555;padding-top:6px;">
          Total: <?php echo $total; ?> pasien &mdash; Diekspor: <?php echo date('d/m/Y H:i'); ?>
        </td>
      </tr>
    </table>
  </body>

  </html>
<?php
  exit;
}

// ─── MODE TAMPILAN: Hitung Total Dulu (untuk paginasi) ───────────────────────
$sql_count = "
  SELECT COUNT(no_reg) AS total
  FROM tx_reg 
      WHERE inap = 'Y' 
      AND tgl_pulang >= :tgl_awal ::date
      AND tgl_pulang < :tgl_akhir ::date + INTERVAL '1 day'
      AND closed_bed = 'Y' 
      AND kd_pastipe IN ('0213','0219','0216','0217','0201')
";
$stmt_count = $pdo->prepare($sql_count);
$stmt_count->execute(array(':tgl_awal' => $tgl_awal, ':tgl_akhir' => $tgl_akhir));
$total_rows = (int)$stmt_count->fetchColumn();
$total_pages = max(1, ceil($total_rows / ROWS_PER_PAGE));
$page = min($page, $total_pages);
$offset = ($page - 1) * ROWS_PER_PAGE;

// ─── MODE TAMPILAN: Ambil Data Halaman Ini Saja ───────────────────────────────
$sql_page = $sql_base .
  " ORDER BY kpu.tgl_pulang ASC, pp.no_reg ASC" .
  " LIMIT " . ROWS_PER_PAGE . " OFFSET " . $offset;

$stmt = $pdo->prepare($sql_page);
$stmt->execute($bind_base);
$rows = $stmt->fetchAll();

// Build URL helper untuk paginasi
function pageUrl($p)
{
  $params = $_GET;
  $params['page'] = $p;
  unset($params['export']);
  return '?' . http_build_query($params);
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Laporan Pasien Pulang RITL</title>
  <style>
    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    body {
      font-family: 'Courier New', Courier, monospace;
      font-size: 11px;
      background: #f4f4f0;
      color: #111;
      padding: 16px;
    }

    .filter-bar {
      background: #fff;
      border: 1px solid #ccc;
      padding: 10px 14px;
      margin-bottom: 10px;
      display: flex;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap;
    }

    .filter-bar label {
      font-weight: bold;
    }

    .filter-bar input[type="date"] {
      border: 1px solid #999;
      padding: 4px 6px;
      font-family: inherit;
      font-size: 11px;
    }

    .filter-bar button,
    .filter-bar a.btn {
      display: inline-block;
      text-decoration: none;
      background: #003580;
      color: #fff;
      border: none;
      padding: 5px 14px;
      cursor: pointer;
      font-family: inherit;
      font-size: 11px;
      letter-spacing: .5px;
    }

    .filter-bar button:hover,
    .filter-bar a.btn:hover {
      background: #002060;
    }

    .filter-bar a.btn-excel {
      background: #1a7340;
    }

    .filter-bar a.btn-excel:hover {
      background: #145a32;
    }

    /* Info bar: total + paginasi */
    .info-bar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      background: #fff;
      border: 1px solid #ccc;
      padding: 6px 12px;
      margin-bottom: 8px;
      flex-wrap: wrap;
      gap: 6px;
    }

    .info-bar .total {
      font-weight: bold;
    }

    .pagination {
      display: flex;
      gap: 4px;
      align-items: center;
    }

    .pagination a,
    .pagination span {
      display: inline-block;
      padding: 3px 8px;
      border: 1px solid #aaa;
      text-decoration: none;
      font-size: 11px;
      color: #003580;
      background: #fff;
    }

    .pagination a:hover {
      background: #e8ecf4;
    }

    .pagination .current {
      background: #003580;
      color: #fff;
      border-color: #003580;
      font-weight: bold;
    }

    .pagination .disabled {
      color: #aaa;
      border-color: #ddd;
      pointer-events: none;
    }

    .report-header {
      text-align: center;
      margin-bottom: 8px;
    }

    .report-header h2 {
      font-size: 13px;
      font-weight: bold;
      letter-spacing: 1px;
      text-transform: uppercase;
    }

    .report-header p {
      font-size: 11px;
      margin-top: 2px;
    }

    .table-wrap {
      overflow-x: auto;
    }

    table {
      border-collapse: collapse;
      width: 100%;
      min-width: 1200px;
      background: #fff;
    }

    th,
    td {
      border: 1px solid #aaa;
      padding: 3px 5px;
      text-align: center;
      white-space: nowrap;
      vertical-align: middle;
    }

    thead tr.row-group th {
      background: #d0d8e8;
      font-weight: bold;
      font-size: 11px;
    }

    thead tr.row-sub th {
      background: #e8ecf4;
      font-size: 10px;
      font-weight: bold;
    }

    td.td-nosep {
      text-align: left;
      min-width: 130px;
    }

    td.td-no {
      min-width: 40px;
    }

    td.td-nama {
      text-align: left;
      min-width: 90px;
    }

    tbody tr.row-first td {
      border-top: 2px solid #888;
    }

    tbody tr.row-pindah td {
      background: #fffbe6;
      border-top: 1px dashed #ccc;
    }

    .no-sep {
      color: #c00;
      font-style: italic;
    }

    .no-data {
      text-align: center;
      color: #666;
      padding: 12px;
    }

    .kelas {
      display: inline-block;
      padding: 1px 5px;
      border: 1px solid #999;
      font-size: 10px;
      border-radius: 2px;
    }

    .kelas-1 {
      border-color: #003580;
      color: #003580;
    }

    .kelas-2 {
      border-color: #006600;
      color: #006600;
    }

    .kelas-3 {
      border-color: #880000;
      color: #880000;
    }

    .badge-pindah {
      display: inline-block;
      background: #f0a500;
      color: #fff;
      font-size: 9px;
      padding: 1px 4px;
      border-radius: 3px;
      margin-left: 3px;
    }

    .report-footer {
      margin-top: 8px;
      font-size: 10px;
      color: #555;
      text-align: right;
    }

    @media print {
      body {
        padding: 4px;
        background: #fff;
      }

      .filter-bar,
      .info-bar .pagination {
        display: none;
      }

      table {
        min-width: unset;
        font-size: 9px;
      }

      tbody tr.row-pindah td {
        background: #fffbe6 !important;
      }
    }
  </style>
</head>

<body>

  <!-- Filter -->
  <div class="filter-bar">
    <form method="GET" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
      <label>Periode:</label>
      <input type="date" name="tgl_awal" value="<?php echo htmlspecialchars($tgl_awal); ?>">
      <span>s/d</span>
      <input type="date" name="tgl_akhir" value="<?php echo htmlspecialchars($tgl_akhir); ?>">
      <button type="submit">Tampilkan</button>
    </form>
    <a class="btn btn-excel"
      href="?tgl_awal=<?php echo urlencode($tgl_awal); ?>&tgl_akhir=<?php echo urlencode($tgl_akhir); ?>&export=excel">
      &#128196; Export Excel
    </a>
    <button type="button" onclick="window.print()">&#128438; Cetak</button>
  </div>

  <!-- Header -->
  <div class="report-header">
    <h2>Laporan Pasien Pulang Rawat Inap (RITL)</h2>
    <p>Periode: <?php echo fmtDate($tgl_awal); ?> &ndash; <?php echo fmtDate($tgl_akhir); ?></p>
  </div>

  <!-- Info bar: total & paginasi -->
  <div class="info-bar">
    <span class="total">
      Total: <?php echo number_format($total_rows); ?> pasien
      &nbsp;&mdash;&nbsp;
      Halaman <?php echo $page; ?> dari <?php echo $total_pages; ?>
      (menampilkan <?php echo ROWS_PER_PAGE; ?> per halaman)
    </span>
    <?php if ($total_pages > 1): ?>
      <div class="pagination">
        <?php if ($page > 1): ?>
          <a href="<?php echo pageUrl(1); ?>">&laquo;</a>
          <a href="<?php echo pageUrl($page - 1); ?>">&lsaquo;</a>
        <?php else: ?>
          <span class="disabled">&laquo;</span>
          <span class="disabled">&lsaquo;</span>
        <?php endif; ?>

        <?php
        // Tampilkan maks 7 nomor halaman di sekitar halaman aktif
        $start = max(1, $page - 3);
        $end   = min($total_pages, $page + 3);
        if ($start > 1) echo '<span>...</span>';
        for ($p = $start; $p <= $end; $p++):
        ?>
          <?php if ($p == $page): ?>
            <span class="current"><?php echo $p; ?></span>
          <?php else: ?>
            <a href="<?php echo pageUrl($p); ?>"><?php echo $p; ?></a>
          <?php endif; ?>
        <?php endfor; ?>
        <?php if ($end < $total_pages) echo '<span>...</span>'; ?>

        <?php if ($page < $total_pages): ?>
          <a href="<?php echo pageUrl($page + 1); ?>">&rsaquo;</a>
          <a href="<?php echo pageUrl($total_pages); ?>">&raquo;</a>
        <?php else: ?>
          <span class="disabled">&rsaquo;</span>
          <span class="disabled">&raquo;</span>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Tabel -->
  <div class="table-wrap">
    <table>
      <thead>
        <tr class="row-group">
          <th rowspan="2">NO</th>
          <th rowspan="2">NO<br>SEP</th>
          <th rowspan="2">NO<br>RM</th>
          <th rowspan="2">Nama<br>Pasien</th>
          <th colspan="5">Pasien Masuk RITL</th>
          <th colspan="5">Diisi jika ada perpindahan</th>
          <th colspan="5">Pasien Pulang RITL</th>
        </tr>
        <tr class="row-sub">
          <th>Tanggal</th>
          <th>Pukul</th>
          <th>Nama Ruang</th>
          <th>Nomor</th>
          <th>Kelas Ruang</th>
          <th>Tanggal</th>
          <th>Pukul</th>
          <th>Nama Ruang</th>
          <th>Nomor</th>
          <th>Kelas Ruang</th>
          <th>Tanggal</th>
          <th>Pukul</th>
          <th>Nama</th>
          <th>Nomor</th>
          <th>Kelas</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($rows)): ?>
          <tr>
            <td colspan="19" class="no-data">Tidak ada data pasien pulang pada periode ini.</td>
          </tr>
        <?php else: ?>
          <?php $no_urut_html = $offset;
          foreach ($rows as $r):
            $no_urut_html++;
            $pindah     = parsePindah($r['pindah_json']);
            $jml_pindah = count($pindah);
            $rowspan    = $jml_pindah > 1 ? $jml_pindah : 1;
            $isSepNull  = ($r['no_sep'] === 'Tidak ada data SEP');
          ?>
            <tr class="row-first">
              <td class="td-no" <?php echo $rowspan > 1 ? 'rowspan="' . $rowspan . '"' : ''; ?>>
                <?php echo $no_urut_html; ?>
              </td>
              <td class="td-nosep <?php echo $isSepNull ? 'no-sep' : ''; ?>"
                <?php echo $rowspan > 1 ? 'rowspan="' . $rowspan . '"' : ''; ?>>
                <?php echo val($r['no_sep']); ?>
              </td>
              <td class="td-no" <?php echo $rowspan > 1 ? 'rowspan="' . $rowspan . '"' : ''; ?>>
                <?php echo val($r['no_mr']); ?>
              </td>
              <td class="td-nama" <?php echo $rowspan > 1 ? 'rowspan="' . $rowspan . '"' : ''; ?>>
                <?php echo val($r['nama_pas']); ?>
                <?php if ($jml_pindah > 1): ?>
                  <span class="badge-pindah"><?php echo $jml_pindah; ?>x pindah</span>
                <?php endif; ?>
              </td>

              <td <?php echo $rowspan > 1 ? 'rowspan="' . $rowspan . '"' : ''; ?>><?php echo fmtDate($r['tgl_masuk']); ?></td>
              <td <?php echo $rowspan > 1 ? 'rowspan="' . $rowspan . '"' : ''; ?>><?php echo val($r['jam_masuk']); ?></td>
              <td <?php echo $rowspan > 1 ? 'rowspan="' . $rowspan . '"' : ''; ?>><?php echo val($r['nama_kamar_masuk']); ?></td>
              <td <?php echo $rowspan > 1 ? 'rowspan="' . $rowspan . '"' : ''; ?>><?php echo val($r['no_kamar_masuk']); ?></td>
              <td <?php echo $rowspan > 1 ? 'rowspan="' . $rowspan . '"' : ''; ?>>
                <span class="<?php echo kelasBadge($r['kelas_masuk']); ?>"><?php echo val($r['kelas_masuk']); ?></span>
              </td>

              <?php if (!empty($pindah)): $p = $pindah[0]; ?>
                <td><?php echo fmtDate($p['tgl_pindah']); ?></td>
                <td><?php echo val($p['jam_pindah']); ?></td>
                <td><?php echo val($p['nama_kamar_pindah']); ?></td>
                <td><?php echo val($p['no_kamar_pindah']); ?></td>
                <td><span class="<?php echo kelasBadge($p['kelas_pindah']); ?>"><?php echo val($p['kelas_pindah']); ?></span></td>
              <?php else: ?>
                <td>-</td>
                <td>-</td>
                <td>-</td>
                <td>-</td>
                <td>-</td>
              <?php endif; ?>

              <td <?php echo $rowspan > 1 ? 'rowspan="' . $rowspan . '"' : ''; ?>><?php echo fmtDate($r['tgl_pulang']); ?></td>
              <td <?php echo $rowspan > 1 ? 'rowspan="' . $rowspan . '"' : ''; ?>><?php echo val($r['jam_pulang']); ?></td>
              <td <?php echo $rowspan > 1 ? 'rowspan="' . $rowspan . '"' : ''; ?>><?php echo val($r['nama_kamar_pulang']); ?></td>
              <td <?php echo $rowspan > 1 ? 'rowspan="' . $rowspan . '"' : ''; ?>><?php echo val($r['no_kamar_pulang']); ?></td>
              <td <?php echo $rowspan > 1 ? 'rowspan="' . $rowspan . '"' : ''; ?>>
                <span class="<?php echo kelasBadge($r['kelas_pulang']); ?>"><?php echo val($r['kelas_pulang']); ?></span>
              </td>
            </tr>

            <?php for ($i = 1; $i < $jml_pindah; $i++): $p = $pindah[$i]; ?>
              <tr class="row-pindah">
                <td><?php echo fmtDate($p['tgl_pindah']); ?></td>
                <td><?php echo val($p['jam_pindah']); ?></td>
                <td><?php echo val($p['nama_kamar_pindah']); ?></td>
                <td><?php echo val($p['no_kamar_pindah']); ?></td>
                <td><span class="<?php echo kelasBadge($p['kelas_pindah']); ?>"><?php echo val($p['kelas_pindah']); ?></span></td>
              </tr>
            <?php endfor; ?>

          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Paginasi bawah -->
  <?php if ($total_pages > 1): ?>
    <div class="info-bar" style="margin-top:8px;">
      <span class="total">Halaman <?php echo $page; ?> dari <?php echo $total_pages; ?></span>
      <div class="pagination">
        <?php if ($page > 1): ?>
          <a href="<?php echo pageUrl(1); ?>">&laquo; Pertama</a>
          <a href="<?php echo pageUrl($page - 1); ?>">&lsaquo; Sebelumnya</a>
        <?php endif; ?>
        <?php if ($page < $total_pages): ?>
          <a href="<?php echo pageUrl($page + 1); ?>">Berikutnya &rsaquo;</a>
          <a href="<?php echo pageUrl($total_pages); ?>">Terakhir &raquo;</a>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <div class="report-footer">
    Halaman <?php echo $page; ?>/<?php echo $total_pages; ?>
    &mdash; Dicetak: <?php echo date('d/m/Y H:i'); ?>
  </div>

</body>

</html>