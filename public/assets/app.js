// public/assets/app.js

async function fetchJSON(url) {
    const res = await fetch(url);
    if (!res.ok) throw new Error(`API error: ${res.status}`);
    return res.json();
}

function buildQueryParams() {
    const s = document.getElementById('startDate').value;
    const e = document.getElementById('endDate').value;
    const sym = document.getElementById('symbol').value.trim();
    const sec = document.getElementById('sector').value.trim();
    const params = new URLSearchParams();
    if (s) params.append('start', s);
    if (e) params.append('end', e);
    if (sym) params.append('symbol', sym);
    if (sec) params.append('sector', sec);
    return params.toString();
}

let volChart, valChart;

async function loadAll() {
    try {
        const data = await fetchJSON('api.php?' + buildQueryParams());

        // KPIs
        document.getElementById('kpiVolume').textContent =
            new Intl.NumberFormat().format(data.kpis?.total_volume || 0);
        document.getElementById('kpiValue').textContent =
            new Intl.NumberFormat().format(Math.round(data.kpis?.total_value || 0));
        document.getElementById('kpiDays').textContent =
            data.kpis?.trading_days || 0;
        document.getElementById('kpiSymbols').textContent =
            data.kpis?.distinct_symbols || 0;

        // Charts
        const labels = (data.daily || []).map(d => d.trade_date);
        const vol = (data.daily || []).map(d => Number(d.volume));
        const val = (data.daily || []).map(d => Number(d.value));

        const volCtx = document.getElementById('volChart').getContext('2d');
        const valCtx = document.getElementById('valChart').getContext('2d');

        if (volChart) volChart.destroy();
        if (valChart) valChart.destroy();

        volChart = new Chart(volCtx, {
            type: 'line',
            data: { labels, datasets: [{ label: 'Volume', data: vol, borderColor: '#0d6efd', fill: false }] },
            options: { scales: { y: { beginAtZero: true } }, plugins: { legend: { display: false } } }
        });

        valChart = new Chart(valCtx, {
            type: 'line',
            data: { labels, datasets: [{ label: 'Value', data: val, borderColor: '#198754', fill: false }] },
            options: { scales: { y: { beginAtZero: true } }, plugins: { legend: { display: false } } }
        });

        // Top tables
        const topVolTBody = document.querySelector('#topVolTbl tbody');
        const topValTBody = document.querySelector('#topValTbl tbody');
        topVolTBody.innerHTML = (data.topVol || []).map(r =>
            `<tr><td>${r.symbol}</td><td class="text-end">${Intl.NumberFormat().format(r.volume)}</td></tr>`
        ).join('');
        topValTBody.innerHTML = (data.topVal || []).map(r =>
            `<tr><td>${r.symbol}</td><td class="text-end">${Intl.NumberFormat().format(Math.round(r.value))}</td></tr>`
        ).join('');

        // Sector breakdown
        const secTBody = document.querySelector('#sectorTbl tbody');
        secTBody.innerHTML = (data.sector || []).map(r =>
            `<tr><td>${r.sector}</td>
       <td class="text-end">${Intl.NumberFormat().format(r.volume)}</td>
       <td class="text-end">${Intl.NumberFormat().format(Math.round(r.value))}</td></tr>`
        ).join('');

        // Buy/Sell suggestions
        const buyTBody = document.querySelector('#buyTbl tbody');
        const sellTBody = document.querySelector('#sellTbl tbody');
        const fmt = new Intl.NumberFormat();
        const pct = x => (x === null || x === undefined) ? '—' : `${(Number(x)).toFixed(2)}%`;

        buyTBody.innerHTML = (data.signals?.buy || []).map(r => `
      <tr>
        <td>${r.symbol}</td>
        <td class="text-end">${fmt.format(Math.round(r.avg_daily_value))}</td>
        <td class="text-end">${(Number(r.mom_ratio)).toFixed(2)}×</td>
        <td class="text-end">${r.traded_days}/${r.period_days}</td>
        <td class="text-end">${pct(r.price_change_pct)}</td>
        <td>${r.reason}</td>
      </tr>
    `).join('');

        sellTBody.innerHTML = (data.signals?.sell || []).map(r => `
      <tr>
        <td>${r.symbol}</td>
        <td class="text-end">${fmt.format(Math.round(r.avg_daily_value))}</td>
        <td class="text-end">${(Number(r.mom_ratio)).toFixed(2)}×</td>
        <td class="text-end">${r.traded_days}/${r.period_days}</td>
        <td class="text-end">${pct(r.price_change_pct)}</td>
        <td>${r.reason}</td>
      </tr>
    `).join('');

        // Forecast tables (fixed syntax)
        const predBuyTBody = document.querySelector('#predBuyTbl tbody');
        const predSellTBody = document.querySelector('#predSellTbl tbody');

        predBuyTBody.innerHTML = (data.forecast?.predicted_buys || []).map(r => `
      <tr>
        <td>${r.symbol}</td>
        <td>${r.is_etf ? 'ETF' : 'Share'}</td>
        <td class="text-end">${fmt.format(Math.round(r.avg_daily_value || 0))}</td>
        <td class="text-end">${Number(r.mom_ratio || 0).toFixed(2)}×</td>
        <td class="text-end">${Number(r.vol_stdev || 0).toFixed(3)}</td>
        <td>${r.reason || ''}</td>
      </tr>
    `).join('');

        predSellTBody.innerHTML = (data.forecast?.predicted_sells || []).map(r => `
      <tr>
        <td>${r.symbol}</td>
        <td>${r.is_etf ? 'ETF' : 'Share'}</td>
        <td class="text-end">${fmt.format(Math.round(r.avg_daily_value || 0))}</td>
        <td class="text-end">${Number(r.mom_ratio || 0).toFixed(2)}×</td>
        <td class="text-end">${Number(r.vol_stdev || 0).toFixed(3)}</td>
        <td>${r.reason || ''}</td>
      </tr>
    `).join('');

        // Populate calculator symbol dropdown from distinct symbols
        const sel = document.getElementById('tradeSymbol');
        sel.innerHTML = (data.symbols || []).map(sym => `<option value="${sym}">${sym}</option>`).join('');

    } catch (err) {
        console.error('Error loading data:', err);
        alert('Failed to load data. Check console for details.');
    }
}

// Events
document.getElementById('applyFilters').addEventListener('click', loadAll);
document.getElementById('resetFilters').addEventListener('click', () => {
    ['startDate', 'endDate', 'symbol', 'sector'].forEach(id => document.getElementById(id).value = '');
    loadAll();
});
document.getElementById('exportCsv').addEventListener('click', async () => {
    try {
        const data = await fetchJSON('api.php?' + buildQueryParams());
        const rows = [['trade_date', 'volume', 'value']].concat(
            (data.daily || []).map(d => [d.trade_date, d.volume, d.value])
        );
        const csv = rows.map(r => r.join(',')).join('\n');
        const blob = new Blob([csv], { type: 'text/csv' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'daily_summary.csv';
        a.click();
    } catch (err) {
        alert('Export failed: ' + err.message);
    }
});
document.getElementById('rebuildBtn').addEventListener('click', async () => {
    const ok = confirm('This will drop the current table. Continue?');
    if (!ok) return;
    const res = await fetch('upload.php', { method: 'POST', body: new URLSearchParams({ rebuild: 1 }) });
    alert(await res.text());
});

// Calculator (with validation and robust output)
document.getElementById('calcBtn').addEventListener('click', async () => {
    const btn = document.getElementById('calcBtn');
    const type = document.getElementById('tradeType').value;
    const amt = parseFloat(document.getElementById('tradeAmt').value);
    const dur = parseInt(document.getElementById('tradeDuration').value, 10);
    const sym = document.getElementById('tradeSymbol').value;

    if (!sym) {
        document.getElementById('calcResult').innerHTML = `<div class="alert alert-warning">Select a symbol.</div>`;
        return;
    }
    if (!amt || amt <= 0) {
        document.getElementById('calcResult').innerHTML = `<div class="alert alert-warning">Enter a positive amount.</div>`;
        return;
    }
    if (!dur || dur <= 0) {
        document.getElementById('calcResult').innerHTML = `<div class="alert alert-warning">Enter a positive duration (days).</div>`;
        return;
    }

    btn.disabled = true;
    document.getElementById('calcResult').innerHTML = `<div class="alert alert-secondary">Calculating…</div>`;

    try {
        const params = new URLSearchParams({ calc: 1, type, amt, duration: dur, symbol: sym });
        const res = await fetchJSON('api.php?' + params.toString());

        if (res.error) {
            document.getElementById('calcResult').innerHTML =
                `<div class="alert alert-danger">${res.error}</div>`;
            btn.disabled = false;
            return;
        }

        const nf = new Intl.NumberFormat();
        document.getElementById('calcResult').innerHTML = `
      <div class="alert alert-info">
        <strong>${(res.trade_type || type).toUpperCase()} ${res.symbol}</strong><br>
        Price date: ${res.price_date || '—'}<br>
        Amount: GH¢${nf.format(res.amount)}<br>
        Latest Price: GH¢${nf.format(res.price)}<br>
        Shares/Units: ${res.shares}<br>
        Duration: ${res.duration} days<br>
        Avg daily return: ${(Number(res.avg_daily_ret) * 100).toFixed(3)}%<br>
        Projected Price: GH¢${nf.format(res.proj_price)}<br>
        Projected Value: GH¢${nf.format(res.proj_value)}
      </div>
    `;
    } catch (err) {
        document.getElementById('calcResult').innerHTML =
            `<div class="alert alert-danger">Calculation failed: ${err.message}</div>`;
    } finally {
        btn.disabled = false;
    }
});

// Initial load
window.addEventListener('DOMContentLoaded', loadAll);
