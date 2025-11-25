-- bootstrap.sql
CREATE TABLE IF NOT EXISTS trades (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  trade_date TEXT NOT NULL,           -- ISO date (YYYY-MM-DD)
  symbol TEXT NOT NULL,
  sector TEXT,                        -- optional; inferred/supplied
  volume INTEGER,                     -- shares
  value REAL,                         -- currency (e.g., GHS)
  price REAL,                         -- last price or close
  vwap REAL,                          -- optional
  source_row INTEGER,                 -- row number in CSV
  raw_json TEXT                       -- original row snapshot
);

CREATE INDEX IF NOT EXISTS idx_trades_date ON trades(trade_date);
CREATE INDEX IF NOT EXISTS idx_trades_symbol ON trades(symbol);
CREATE INDEX IF NOT EXISTS idx_trades_sector ON trades(sector);
