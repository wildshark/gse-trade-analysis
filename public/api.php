<?php
// public/api.php
header('Content-Type: application/json; charset=utf-8');

try {
  // Connect to SQLite
  $dbPath = dirname(__DIR__) . '/storage/trades.db';
  if (!file_exists($dbPath)) {
    echo json_encode(['error' => 'Database not found. Please upload a CSV first.']);
    exit;
  }
  $db = new PDO("sqlite:" . $dbPath);
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

  // Utils
  $qAll = function(string $sql, array $bind = []) use ($db) {
    $st = $db->prepare($sql);
    $st->execute($bind);
    return $st->fetchAll();
  };
  $qOne = function(string $sql, array $bind = []) use ($db) {
    $st = $db->prepare($sql);
    $st->execute($bind);
    $row = $st->fetch();
    return $row ?: [];
  };

  // --- Calculator endpoint (fixes included) ---
  if (isset($_GET['calc'])) {
    $symbol   = strtoupper(trim($_GET['symbol'] ?? ''));
    $amt      = floatval($_GET['amt'] ?? 0);
    $duration = max(1, intval($_GET['duration'] ?? 30)); // days
    $type     = ($_GET['type'] ?? 'buy') === 'sell' ? 'sell' : 'buy';

    if ($symbol === '' || $amt <= 0) {
      echo json_encode(['error' => 'Provide a valid symbol and positive amount.']);
      exit;
    }

    // 1) Latest price (prefer explicit price column)
    $stPrice = $db->prepare("
      SELECT price, trade_date
      FROM trades
      WHERE symbol = :sym AND price IS NOT NULL
      ORDER BY trade_date DESC
      LIMIT 1
    ");
    $stPrice->execute([':sym' => $symbol]);
    $latestPriceRow = $stPrice->fetch();

    $latestPrice = $latestPriceRow['price'] ?? null;
    $latestDate  = $latestPriceRow['trade_date'] ?? null;

    // 2) Fallback price using last traded day VWAP (value/volume)
    if ($latestPrice === null) {
      $stVWAP = $db->prepare("
        SELECT trade_date, SUM(value) AS v, SUM(volume) AS vol
        FROM trades
        WHERE symbol = :sym
        GROUP BY trade_date
        ORDER BY trade_date DESC
        LIMIT 1
      ");
      $stVWAP->execute([':sym' => $symbol]);
      $vwapRow = $stVWAP->fetch();
      if (!empty($vwapRow) && floatval($vwapRow['vol']) > 0) {
        $latestPrice = floatval($vwapRow['v']) / floatval($vwapRow['vol']);
        $latestDate  = $vwapRow['trade_date'];
      }
    }

    if ($latestPrice === null || $latestPrice <= 0) {
      echo json_encode(['error' => "No usable price found for $symbol"]);
      exit;
    }

    // 3) Shares (round down sensibly)
    $shares = floor(($amt / $latestPrice) * 100) / 100.0; // keep 2 decimals for units if ETFs, floor for conservative calc
    if ($shares <= 0) {
      echo json_encode(['error' => 'Amount too small relative to price. Increase amount.']);
      exit;
    }

    // 4) Build recent price series (for projected price using average daily return)
    $stSeries = $db->prepare("
      SELECT trade_date, price
      FROM trades
      WHERE symbol = :sym AND price IS NOT NULL
      ORDER BY trade_date ASC
      LIMIT 90
    ");
    $stSeries->execute([':sym' => $symbol]);
    $series = $stSeries->fetchAll();

    // If price series is too sparse, try VWAP series as fallback
    if (count($series) < 10) {
      $stVSeries = $db->prepare("
        SELECT trade_date, (SUM(value)*1.0 / NULLIF(SUM(volume),0)) AS price
        FROM trades
        WHERE symbol = :sym
        GROUP BY trade_date
        HAVING price IS NOT NULL
        ORDER BY trade_date ASC
        LIMIT 90
      ");
      $stVSeries->execute([':sym' => $symbol]);
      $series = $stVSeries->fetchAll();
    }

    // Compute average daily return (guard against outliers)
    $avgDailyRet = 0.0;
    if (count($series) >= 5) {
      $rets = [];
      for ($i = 1; $i < count($series); $i++) {
        $p0 = floatval($series[$i-1]['price']);
        $p1 = floatval($series[$i]['price']);
        if ($p0 > 0 && $p1 > 0) {
          $r = ($p1 - $p0) / $p0;
          // clamp extreme moves to ±20% to avoid noisy spikes
          $rets[] = max(-0.20, min(0.20, $r));
        }
      }
      if (count($rets) > 0) {
        $avgDailyRet = array_sum($rets) / count($rets);
      }
    }

    // Projected price using compounding over 'duration' trading days
    $projPrice = $latestPrice * pow(1.0 + $avgDailyRet, $duration);
    // If we have no return estimate, use latest price as projection
    if ($avgDailyRet === 0.0) {
      $projPrice = $latestPrice;
    }

    // Projected portfolio value
    $projValue = $shares * $projPrice;

    echo json_encode([
      'symbol'       => $symbol,
      'trade_type'   => $type,
      'amount'       => round($amt, 2),
      'price_date'   => $latestDate,
      'price'        => round($latestPrice, 4),
      'shares'       => $shares, // preserve decimals
      'duration'     => $duration,
      'avg_daily_ret'=> round($avgDailyRet, 6),
      'proj_price'   => round($projPrice, 4),
      'proj_value'   => round($projValue, 2)
    ]);
    exit;
  }

  // --- Filters for analytics ---
  $params = [
    'start'  => $_GET['start'] ?? null,
    'end'    => $_GET['end'] ?? null,
    'symbol' => isset($_GET['symbol']) ? strtoupper(trim($_GET['symbol'])) : null,
    'sector' => isset($_GET['sector']) ? strtoupper(trim($_GET['sector'])) : null,
  ];
  $where = [];
  $bind  = [];
  if ($params['start']) { $where[] = "trade_date >= :start";  $bind[':start']  = $params['start']; }
  if ($params['end'])   { $where[] = "trade_date <= :end";    $bind[':end']    = $params['end']; }
  if ($params['symbol']){ $where[] = "symbol = :symbol";      $bind[':symbol'] = $params['symbol']; }
  if ($params['sector']){ $where[] = "sector = :sector";      $bind[':sector'] = $params['sector']; }
  $wsql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
  $wsqlBase = $wsql ? $wsql : 'WHERE 1=1';

  // KPIs
  $kpis = $qOne("
    SELECT
      COALESCE(SUM(volume),0) AS total_volume,
      COALESCE(SUM(value),0)  AS total_value,
      COUNT(DISTINCT trade_date) AS trading_days,
      COUNT(DISTINCT symbol) AS distinct_symbols
    FROM trades $wsql
  ", $bind);

  // Daily aggregates
  $daily = $qAll("
    SELECT trade_date,
           COALESCE(SUM(volume),0) AS volume,
           COALESCE(SUM(value),0)  AS value
    FROM trades $wsql
    GROUP BY trade_date
    ORDER BY trade_date ASC
  ", $bind);

  // Top symbols
  $topVol = $qAll("
    SELECT symbol, COALESCE(SUM(volume),0) AS volume
    FROM trades $wsql
    GROUP BY symbol
    ORDER BY volume DESC
    LIMIT 10
  ", $bind);
  $topVal = $qAll("
    SELECT symbol, COALESCE(SUM(value),0) AS value
    FROM trades $wsql
    GROUP BY symbol
    ORDER BY value DESC
    LIMIT 10
  ", $bind);

  // Sector breakdown
  $sector = $qAll("
    SELECT COALESCE(sector,'(UNKNOWN)') AS sector,
           COALESCE(SUM(volume),0) AS volume,
           COALESCE(SUM(value),0)  AS value
    FROM trades $wsql
    GROUP BY sector
    ORDER BY value DESC
  ", $bind);

  // Distinct symbols list (for dropdowns)
  $symbolsRows = $qAll("
    SELECT DISTINCT symbol
    FROM trades $wsqlBase
    ORDER BY symbol ASC
  ", $bind);
  $symbols = array_map(fn($r) => $r['symbol'], $symbolsRows);

  // --- Signals ---
  $periodDaysRow = $qOne("SELECT COUNT(DISTINCT trade_date) AS days FROM trades $wsql", $bind);
  $periodDays = intval($periodDaysRow['days'] ?? 0);

  // Windows for momentum
  $window30 = $qOne("
    WITH d AS (
      SELECT DISTINCT trade_date FROM trades $wsql ORDER BY trade_date
    ),
    r AS (
      SELECT trade_date, ROW_NUMBER() OVER (ORDER BY trade_date DESC) AS rn FROM d
    )
    SELECT COALESCE(MAX(trade_date),'') AS end_d, COALESCE(MIN(trade_date),'') AS start_d
    FROM r WHERE rn <= 30
  ", $bind);

  $window5 = $qOne("
    WITH d AS (
      SELECT DISTINCT trade_date FROM trades $wsql ORDER BY trade_date
    ),
    r AS (
      SELECT trade_date, ROW_NUMBER() OVER (ORDER BY trade_date DESC) AS rn FROM d
    )
    SELECT COALESCE(MAX(trade_date),'') AS end_d, COALESCE(MIN(trade_date),'') AS start_d
    FROM r WHERE rn <= 5
  ", $bind);

  // Per-symbol aggregates
  $perSymbol = $qAll("
    SELECT symbol,
           COUNT(DISTINCT trade_date) AS traded_days,
           COALESCE(SUM(value),0)     AS total_value,
           COALESCE(SUM(volume),0)    AS total_volume,
           COALESCE(AVG(price),NULL)  AS avg_price,
           COALESCE(MAX(value),0)     AS max_day_value
    FROM trades $wsql
    GROUP BY symbol
  ", $bind);

  // Helpers
  $windowAvgValue = function(string $symbol, string $ws, string $we) use ($db, $bind, $wsqlBase) {
    if ($ws === '' || $we === '') return 0.0;
    $sql = "
      SELECT COALESCE(AVG(v),0) AS avg_v
      FROM (
        SELECT trade_date, COALESCE(SUM(value),0) AS v
        FROM trades $wsqlBase AND symbol = :sym AND trade_date >= :ws AND trade_date <= :we
        GROUP BY trade_date
      ) x
    ";
    $st = $db->prepare($sql);
    $loc = $bind;
    $loc[':sym'] = strtoupper($symbol);
    $loc[':ws']  = $ws;
    $loc[':we']  = $we;
    $st->execute($loc);
    $r = $st->fetch();
    return floatval($r['avg_v'] ?? 0);
  };
  $priceChangePct = function(string $symbol) use ($db, $bind, $wsqlBase) {
    $st1 = $db->prepare("SELECT price FROM trades $wsqlBase AND symbol = :sym AND price IS NOT NULL ORDER BY trade_date ASC  LIMIT 1");
    $st2 = $db->prepare("SELECT price FROM trades $wsqlBase AND symbol = :sym AND price IS NOT NULL ORDER BY trade_date DESC LIMIT 1");
    $loc = $bind; $loc[':sym'] = strtoupper($symbol);
    $st1->execute($loc); $st2->execute($loc);
    $p0 = $st1->fetchColumn(); $p1 = $st2->fetchColumn();
    if ($p0 === false || $p1 === false || !is_numeric($p0) || !is_numeric($p1) || floatval($p0) == 0.0) return null;
    return floatval(($p1 - $p0) / $p0 * 100.0);
  };

  // Thresholds
  $TH = [
    'liq_avg_value'    => 100000.0,
    'low_avg_value'    => 10000.0,
    'mom_up_ratio'     => 1.30,
    'mom_down_ratio'   => 0.70,
    'price_up_pct'     => 5.0,
    'price_down_pct'   => -5.0,
    'consistency_frac' => 0.60,
    'pump_share'       => 0.60,
  ];

  $buy = [];
  $sell = [];

  if ($periodDays > 0) {
    foreach ($perSymbol as $row) {
      $sym = $row['symbol'];
      $totalValue  = floatval($row['total_value'] ?? 0);
      $avgDailyVal = $periodDays > 0 ? ($totalValue / $periodDays) : 0.0;

      $tradedDays  = intval($row['traded_days'] ?? 0);
      $consistency = $periodDays > 0 ? ($tradedDays / $periodDays) : 0.0;

      $v5  = $windowAvgValue($sym, $window5['start_d'] ?? '',  $window5['end_d'] ?? '');
      $v30 = $windowAvgValue($sym, $window30['start_d'] ?? '', $window30['end_d'] ?? '');
      if ($v30 <= 0) $v30 = max($avgDailyVal, 1.0);
      $momRatio = $v30 > 0 ? ($v5 / $v30) : 0.0;

      $pchg = $priceChangePct($sym); // may be null
      $maxDayVal = floatval($row['max_day_value'] ?? 0);
      $pumpShare = $totalValue > 0 ? ($maxDayVal / $totalValue) : 0.0;

      // BUY
      $buyChecks = [
        'liquidity'   => ($avgDailyVal >= $TH['liq_avg_value']),
        'momentum'    => ($momRatio     >= $TH['mom_up_ratio']),
        'consistency' => ($consistency  >= $TH['consistency_frac']),
        'price_up'    => (is_numeric($pchg) ? ($pchg >= $TH['price_up_pct']) : true),
      ];
      if ($buyChecks['liquidity'] && $buyChecks['momentum'] && $buyChecks['consistency'] && $buyChecks['price_up']) {
        $buy[] = [
          'symbol'           => $sym,
          'avg_daily_value'  => round($avgDailyVal, 2),
          'mom_ratio'        => round($momRatio, 2),
          'traded_days'      => $tradedDays,
          'period_days'      => $periodDays,
          'price_change_pct' => is_numeric($pchg) ? round($pchg, 2) : null,
          'reason'           => 'Liquid + positive momentum + consistent trading' . (is_numeric($pchg) ? ' + price up' : ''),
        ];
      }

      // SELL
      $sellChecks = [
        'illiquid'     => ($avgDailyVal < $TH['low_avg_value']),
        'neg_momentum' => ($momRatio     <= $TH['mom_down_ratio']),
        'price_down'   => (is_numeric($pchg) ? ($pchg <= $TH['price_down_pct']) : false),
        'pump_risk'    => ($pumpShare    >= $TH['pump_share']),
      ];
      if ($sellChecks['illiquid'] || $sellChecks['neg_momentum'] || $sellChecks['price_down'] || $sellChecks['pump_risk']) {
        $reasons = [];
        if ($sellChecks['illiquid'])     $reasons[] = 'Illiquid';
        if ($sellChecks['neg_momentum']) $reasons[] = 'Negative momentum';
        if ($sellChecks['price_down'])   $reasons[] = 'Price down';
        if ($sellChecks['pump_risk'])    $reasons[] = 'Pump-like concentration';

        $sell[] = [
          'symbol'           => $sym,
          'avg_daily_value'  => round($avgDailyVal, 2),
          'mom_ratio'        => round($momRatio, 2),
          'traded_days'      => $tradedDays,
          'period_days'      => $periodDays,
          'price_change_pct' => is_numeric($pchg) ? round($pchg, 2) : null,
          'pump_share'       => round($pumpShare, 2),
          'reason'           => implode(' + ', $reasons),
        ];
      }
    }

    usort($buy, function($a, $b) {
      return ($b['mom_ratio'] <=> $a['mom_ratio']) ?: ($b['avg_daily_value'] <=> $a['avg_daily_value']);
    });
    usort($sell, function($a, $b) {
      return ($b['pump_share'] <=> $a['pump_share']) ?: ($a['avg_daily_value'] <=> $b['avg_daily_value']);
    });
  }

  // --- Forecast (simple: reuse signals as proxy) ---
  $forecastBuys  = array_slice($buy, 0, 10);
  $forecastSells = array_slice($sell, 0, 10);

  // Output
  echo json_encode([
    'kpis'     => $kpis,
    'daily'    => $daily,
    'topVol'   => $topVol,
    'topVal'   => $topVal,
    'sector'   => $sector,
    'symbols'  => $symbols, // for dropdowns
    'signals'  => ['buy' => $buy, 'sell' => $sell],
    'forecast' => [
      'predicted_buys'  => $forecastBuys,
      'predicted_sells' => $forecastSells
    ]
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error' => 'Internal server error', 'message' => $e->getMessage()]);
}
