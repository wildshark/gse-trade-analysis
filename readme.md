# 📊 Trade Analysis Dashboard (Shares & ETFs)

A lightweight PHP + SQLite web application for analyzing daily trades of shares and ETFs.  
It ingests CSV files, computes KPIs, generates charts, highlights buy/sell signals, forecasts future candidates, and includes a calculator for trade scenarios.

---

## ✨ Features

- **CSV Upload & Ingestion**: Import daily trade data into SQLite.
- **Filters**: Query by date range, symbol, and sector.
- **KPIs**: Total volume, value, trading days, distinct symbols.
- **Charts**: Interactive daily volume and value charts (Chart.js).
- **Top Leaders**: Top 10 symbols by volume and value.
- **Sector Breakdown**: Aggregated volume/value by sector.
- **Buy/Sell Suggestions**: Based on liquidity, momentum, consistency, and pump-risk.
- **Forecast Predictions**: Predicts buy/sell candidates for the next 30 days.
- **Calculator**: Simulates buy/sell scenarios with amount, duration, and symbol selection.

---

## 📂 File Structure

```
trade-analysis/
├── public/
│   ├── index.php        # Main UI dashboard
│   ├── api.php          # Backend API (KPIs, signals, forecast, calculator)
│   ├── upload.php       # CSV ingestion into SQLite
│   └── assets/
│       ├── app.js       # Frontend logic (fetch, charts, tables, calculator)
│       └── styles.css   # Custom styles
├── storage/
│   └── trades.db        # SQLite database (auto-created)
├── bootstrap.sql        # Schema definition for trades table
└── README.md            # Project documentation
```

---

## 🚀 Deployment

1. **Clone the repository**:
   ```bash
   git clone https://github.com/your-org/trade-analysis.git
   cd trade-analysis
   ```

2. **Setup environment**:
   - Ensure PHP ≥ 8.0 with `pdo_sqlite` enabled.
   - Ensure web server (Apache/Nginx) points to the `public/` directory.

3. **Initialize database**:
   ```bash
   sqlite3 storage/trades.db < bootstrap.sql
   ```

4. **Permissions**:
   - Allow web server write access to `storage/` for database updates.

5. **Run locally** (PHP built-in server):
   ```bash
   php -S localhost:8000 -t public
   ```

6. Open [http://localhost:8000](http://localhost:8000) in your browser.

---

## 🛠️ Usage

1. **Upload CSV**:
   - Use the upload form to ingest daily trades.
   - Optionally set a default sector if missing in CSV.

2. **Explore Dashboard**:
   - Apply filters (date, symbol, sector).
   - View KPIs, charts, top leaders, and sector breakdown.

3. **Signals & Forecasts**:
   - Review Buy/Sell suggestions.
   - Check predicted candidates for the next 30 days.

4. **Calculator**:
   - Select trade type (buy/sell), amount, duration, and symbol.
   - Get projected shares, price, and portfolio value.

---

## 📌 Example CSV Format

```csv
Date,Symbol,Sector,Volume,Value,Price
2025-09-30,MTNGH,ICT,39507901,153540283.80,4.20
2025-09-30,GLD,ETF,100000,4570000.00,45.70
...
```

---

## ⚠️ Important Disclaimer

This application is for **educational and analytical purposes only**.  
It does **not** provide financial advice, recommendations, or guarantees.  
Before making any investment decisions, **seek guidance from a licensed broker or qualified financial professional**.  

---

## 📄 License

MIT License – free to use and adapt.