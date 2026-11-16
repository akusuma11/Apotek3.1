<?php
$page_title='Report Penjualan';
require_once __DIR__.'/../includes/header.php';
require_role('admin');

$from   = $_GET['from']   ?? date('Y-m-01');
$to     = $_GET['to']     ?? date('Y-m-d');
$sumber = $_GET['sumber'] ?? 'all'; // all|kasir|online
$export = $_GET['export'] ?? '';

// Pastikan view ada (auto-create kalau belum diimport sql/report_penjualan.sql)
try {
  $conn->query("SELECT 1 FROM v_report_penjualan LIMIT 1");
} catch (PDOException $ex) {
  $sqlFile = __DIR__.'/../sql/report_penjualan.sql';
  if (is_file($sqlFile)) {
    $sql = file_get_contents($sqlFile);
    // buang baris USE db_apotek;
    $sql = preg_replace('/^\s*USE\s+\w+\s*;.*$/mi','',$sql);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
      if ($stmt==='' || stripos($stmt,'--')===0) continue;
      try { $conn->exec($stmt); } catch(Throwable $e) {}
    }
  }
}

$where = "tgl BETWEEN ? AND ?";
$params = [$from,$to];
if ($sumber==='kasir' || $sumber==='online') { $where .= " AND sumber=?"; $params[]=$sumber; }

// Data transaksi
$st = $conn->prepare("SELECT * FROM v_report_penjualan WHERE $where ORDER BY tgl_transaksi DESC");
$st->execute($params);
$rows = $st->fetchAll();

// Rekap
$total       = array_sum(array_column($rows,'total'));
$totalKasir  = array_sum(array_map(fn($r)=>$r['sumber']==='kasir'?$r['total']:0,$rows));
$totalOnline = array_sum(array_map(fn($r)=>$r['sumber']==='online'?$r['total']:0,$rows));
$jmlItem     = array_sum(array_column($rows,'jml_item'));

// Top obat
$st2 = $conn->prepare("SELECT kode_obat,nama_obat,kategori,SUM(jumlah) tot,SUM(subtotal) pend
  FROM v_report_penjualan_detail WHERE tgl BETWEEN ? AND ?
  ".($sumber==='all'?'':"AND sumber=?")."
  GROUP BY obat_id,kode_obat,nama_obat,kategori ORDER BY tot DESC LIMIT 10");
$st2->execute($sumber==='all'?[$from,$to]:[$from,$to,$sumber]);
$topObat = $st2->fetchAll();

// Per kategori
$st3 = $conn->prepare("SELECT COALESCE(kategori,'(Tanpa Kategori)') kat, SUM(jumlah) tot, SUM(subtotal) pend
  FROM v_report_penjualan_detail WHERE tgl BETWEEN ? AND ?
  ".($sumber==='all'?'':"AND sumber=?")."
  GROUP BY kat ORDER BY pend DESC");
$st3->execute($sumber==='all'?[$from,$to]:[$from,$to,$sumber]);
$kategori = $st3->fetchAll();

// Harian (chart)
$st4 = $conn->prepare("SELECT tgl,
  SUM(CASE WHEN sumber='kasir' THEN total ELSE 0 END) AS kasir,
  SUM(CASE WHEN sumber='online' THEN total ELSE 0 END) AS online
  FROM v_report_penjualan WHERE tgl BETWEEN ? AND ?
  ".($sumber==='all'?'':"AND sumber=?")."
  GROUP BY tgl ORDER BY tgl");
$st4->execute($sumber==='all'?[$from,$to]:[$from,$to,$sumber]);
$harian = $st4->fetchAll();

// ====== EXPORT CSV ======
if ($export==='csv') {
  while (ob_get_level()) ob_end_clean();
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename=report_penjualan_'.$from.'_'.$to.'.csv');
  $out = fopen('php://output','w');
  fputcsv($out, ['No Transaksi','Tanggal','Sumber','Petugas','Pelanggan','Metode Bayar','Status Bayar','Jml Item','Total']);
  foreach($rows as $r){
    fputcsv($out,[$r['no_transaksi'],$r['tgl_transaksi'],$r['sumber'],$r['petugas'],$r['pelanggan'],
      $r['metode_bayar'],$r['status_bayar'],$r['jml_item'],$r['total']]);
  }
  fputcsv($out,[]);
  fputcsv($out,['TOTAL','','','','','','','',$total]);
  fclose($out); exit;
}
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h2 class="m-0"><i class="bi bi-graph-up"></i> Report Penjualan</h2>
  <div>
    <a class="btn btn-outline-success btn-sm" href="?from=<?= e($from) ?>&to=<?= e($to) ?>&sumber=<?= e($sumber) ?>&export=csv"><i class="bi bi-file-earmark-spreadsheet"></i> Export CSV</a>
    <button onclick="window.print()" class="btn btn-outline-secondary btn-sm"><i class="bi bi-printer"></i> Cetak</button>
  </div>
</div>

<form class="card p-3 mb-3">
  <div class="row g-2 align-items-end">
    <div class="col-md-3"><label class="form-label">Dari</label><input type="date" name="from" value="<?= e($from) ?>" class="form-control"></div>
    <div class="col-md-3"><label class="form-label">Sampai</label><input type="date" name="to" value="<?= e($to) ?>" class="form-control"></div>
    <div class="col-md-3"><label class="form-label">Sumber</label>
      <select name="sumber" class="form-select">
        <option value="all"    <?= $sumber==='all'?'selected':'' ?>>Semua (Kasir + Online)</option>
        <option value="kasir"  <?= $sumber==='kasir'?'selected':'' ?>>Kasir (Apoteker)</option>
        <option value="online" <?= $sumber==='online'?'selected':'' ?>>Online (Pelanggan)</option>
      </select>
    </div>
    <div class="col-md-3"><button class="btn btn-primary w-100"><i class="bi bi-funnel"></i> Filter</button></div>
  </div>
</form>

<div class="row g-3 mb-3">
  <div class="col-md-3"><div class="card p-3"><div class="text-muted small">Total Omzet</div><div class="h4 mb-0 text-success">Rp <?= number_format($total,0,',','.') ?></div></div></div>
  <div class="col-md-3"><div class="card p-3"><div class="text-muted small">Penjualan Kasir</div><div class="h4 mb-0">Rp <?= number_format($totalKasir,0,',','.') ?></div></div></div>
  <div class="col-md-3"><div class="card p-3"><div class="text-muted small">Penjualan Online</div><div class="h4 mb-0">Rp <?= number_format($totalOnline,0,',','.') ?></div></div></div>
  <div class="col-md-3"><div class="card p-3"><div class="text-muted small">Transaksi / Item</div><div class="h4 mb-0"><?= count($rows) ?> / <?= (int)$jmlItem ?></div></div></div>
</div>

<div class="row g-3 mb-3">
  <div class="col-md-8">
    <div class="card p-3">
      <h6>Grafik Omzet Harian</h6>
      <canvas id="chartHarian" height="100"></canvas>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card p-3">
      <h6>Rekap per Kategori</h6>
      <table class="table table-sm mb-0">
        <thead><tr><th>Kategori</th><th class="text-end">Qty</th><th class="text-end">Pendapatan</th></tr></thead>
        <tbody>
        <?php foreach($kategori as $k): ?>
          <tr><td><?= e($k['kat']) ?></td><td class="text-end"><?= (int)$k['tot'] ?></td><td class="text-end">Rp <?= number_format($k['pend'],0,',','.') ?></td></tr>
        <?php endforeach; if(!$kategori): ?><tr><td colspan="3" class="text-center text-muted">-</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="card p-3 mb-3">
  <h6>10 Obat Terlaris</h6>
  <div class="table-responsive">
    <table class="table table-sm">
      <thead><tr><th>#</th><th>Kode</th><th>Nama Obat</th><th>Kategori</th><th class="text-end">Qty Terjual</th><th class="text-end">Pendapatan</th></tr></thead>
      <tbody>
      <?php foreach($topObat as $i=>$t): ?>
        <tr><td><?= $i+1 ?></td><td><?= e($t['kode_obat']) ?></td><td><?= e($t['nama_obat']) ?></td>
            <td><?= e($t['kategori']) ?></td><td class="text-end"><?= (int)$t['tot'] ?></td>
            <td class="text-end">Rp <?= number_format($t['pend'],0,',','.') ?></td></tr>
      <?php endforeach; if(!$topObat): ?><tr><td colspan="6" class="text-center text-muted">Tidak ada data.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card p-3">
  <h6>Daftar Transaksi</h6>
  <div class="table-responsive">
    <table class="table table-striped">
      <thead><tr>
        <th>No Transaksi</th><th>Tanggal</th><th>Sumber</th><th>Petugas</th>
        <th>Pelanggan</th><th>Bayar</th><th>Status</th><th class="text-end">Item</th><th class="text-end">Total</th>
      </tr></thead>
      <tbody>
      <?php foreach($rows as $r):
        $link = $r['sumber']==='kasir'
          ? '../apoteker/penjualan_detail.php?id='.$r['trx_id']
          : 'pemesanan.php?id='.$r['trx_id'];
      ?>
        <tr>
          <td><a href="<?= e($link) ?>"><?= e($r['no_transaksi']) ?></a></td>
          <td><?= e($r['tgl_transaksi']) ?></td>
          <td><span class="badge bg-<?= $r['sumber']==='kasir'?'primary':'info' ?>"><?= e($r['sumber']) ?></span></td>
          <td><?= e($r['petugas']) ?></td>
          <td><?= e($r['pelanggan']) ?></td>
          <td><?= e($r['metode_bayar']?:'-') ?></td>
          <td><span class="badge bg-<?= $r['status_bayar']==='lunas'||$r['status_bayar']==='dibayar'?'success':'warning' ?>"><?= e($r['status_bayar']) ?></span></td>
          <td class="text-end"><?= (int)$r['jml_item'] ?></td>
          <td class="text-end">Rp <?= number_format($r['total'],0,',','.') ?></td>
        </tr>
      <?php endforeach; if(!$rows): ?>
        <tr><td colspan="9" class="text-center text-muted">Tidak ada data pada rentang tanggal ini.</td></tr>
      <?php endif; ?>
      </tbody>
      <tfoot><tr class="fw-bold"><td colspan="8" class="text-end">TOTAL</td><td class="text-end">Rp <?= number_format($total,0,',','.') ?></td></tr></tfoot>
    </table>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
const labels = <?= json_encode(array_column($harian,'tgl')) ?>;
const kasir  = <?= json_encode(array_map(fn($x)=>(float)$x['kasir'],$harian)) ?>;
const online = <?= json_encode(array_map(fn($x)=>(float)$x['online'],$harian)) ?>;
new Chart(document.getElementById('chartHarian'),{
  type:'bar',
  data:{labels,datasets:[
    {label:'Kasir',data:kasir,backgroundColor:'#0d9488'},
    {label:'Online',data:online,backgroundColor:'#0891b2'}
  ]},
  options:{responsive:true,scales:{x:{stacked:true},y:{stacked:true,beginAtZero:true}}}
});
</script>

<style>@media print{form,.btn,nav,aside,.sidebar{display:none!important}}</style>

<?php require __DIR__.'/../includes/footer.php'; ?>
