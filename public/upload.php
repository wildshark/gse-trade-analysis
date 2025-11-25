<?php
// public/upload.php
ini_set('auto_detect_line_endings', true);
$dbPath = __DIR__ . '/../storage/trades.db';
$uploads = __DIR__ . '/../storage/uploads';
if (!is_dir($uploads)) mkdir($uploads, 0777, true);

if (!isset($_FILES['csv'])) {
  http_response_code(400); echo "No file."; exit;
}

$defaultSector = isset($_POST['default_sector']) ? trim($_POST['default_sector']) : null;

$fname = time() . '-' . preg_replace('/\s+/', '_', $_FILES['csv']['name']);
move_uploaded_file($_FILES['csv']['tmp_name'], "$uploads/$fname");

$db = new PDO("sqlite:$dbPath");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec(file_get_contents(__DIR__ . '/../bootstrap.sql'));

$fh = fopen("$uploads/$fname", 'r');
if (!$fh) { echo "Cannot open CSV."; exit; }

$header = fgetcsv($fh);
if (!$header) { echo "Empty CSV."; exit; }

// Map headers to canonical fields
function hmap($h) {
  $k = strtolower(trim($h));
  return match (true) {
    str_contains($k, 'date') => 'trade_date',
    $k === 'symbol' || str_contains($k, 'code') => 'symbol',
    str_contains($k, 'sector') => 'sector',
    str_contains($k, 'vol') => 'volume',
    str_contains($k, 'val') || str_contains($k, 'turnover') => 'value',
    str_contains($k, 'price') && !str_contains($k, 'avg') => 'price',
    str_contains($k, 'vwap') || str_contains($k, 'avg') => 'vwap',
    default => null
  };
}
$cols = array_map('hmap', $header);

$insert = $db->prepare("
  INSERT INTO trades (trade_date, symbol, sector, volume, value, price, vwap, source_row, raw_json)
  VALUES (:trade_date, :symbol, :sector, :volume, :value, :price, :vwap, :source_row, :raw_json)
");

$rowNum = 1;
$inserted = 0;
while (($row = fgetcsv($fh)) !== false) {
  $data = ['trade_date'=>null,'symbol'=>null,'sector'=>$defaultSector,'volume'=>null,'value'=>null,'price'=>null,'vwap'=>null];
  foreach ($row as $i=>$val) {
    $key = $cols[$i] ?? null;
    if (!$key) continue;
    $data[$key] = $val;
  }
  // Normalize date
  if (!empty($data['trade_date'])) {
    $dt = date_create($data['trade_date']);
    $data['trade_date'] = $dt ? $dt->format('Y-m-d') : null;
  }
  // Clean numerics
  foreach (['volume','value','price','vwap'] as $n) {
    if ($data[$n] !== null) {
      $data[$n] = floatval(str_replace([',',' '],'',$data[$n]));
    }
  }
  if (!$data['trade_date'] || !$data['symbol']) { $rowNum++; continue; }

  $insert->execute([
    ':trade_date'=>$data['trade_date'],
    ':symbol'=>strtoupper(trim($data['symbol'])),
    ':sector'=>$data['sector'] ? strtoupper(trim($data['sector'])) : null,
    ':volume'=>is_numeric($data['volume']) ? intval($data['volume']) : null,
    ':value'=>is_numeric($data['value']) ? floatval($data['value']) : null,
    ':price'=>is_numeric($data['price']) ? floatval($data['price']) : null,
    ':vwap'=>is_numeric($data['vwap']) ? floatval($data['vwap']) : null,
    ':source_row'=>$rowNum,
    ':raw_json'=>json_encode($row, JSON_UNESCAPED_UNICODE)
  ]);
  $inserted++; $rowNum++;
}
fclose($fh);

echo "Uploaded: $fname<br>Inserted rows: $inserted";
