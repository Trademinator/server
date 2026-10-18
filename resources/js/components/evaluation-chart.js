import { chartTimeOptions, formatTimestamp } from './time-display.js';

export async function mountEvaluationChart(root) {
    const { createChart, CandlestickSeries, createSeriesMarkers } = await import('lightweight-charts');
    const data = JSON.parse(root.dataset.series || '{}');
    const candles = data.candles || [];
    const status = root.querySelector('[data-status]');
    const canvas = root.querySelector('[data-canvas]');
    const inspect = root.querySelector('[data-inspect]');
    if (!candles.length) {
        status.textContent = 'No retained evaluation candle trace. Rebuild the model to generate one.';
        return;
    }
    const dark = () => document.documentElement.classList.contains('dark');
    const theme = () => ({
        layout: { background: { type: 'solid', color: dark() ? '#111827' : '#fff' },
            textColor: dark() ? '#e5edf5' : '#172c43' },
        grid: { vertLines: { color: dark() ? '#263348' : '#edf1f5' },
            horzLines: { color: dark() ? '#263348' : '#edf1f5' } },
        timeScale: { ...chartTimeOptions(), timeVisible: true, secondsVisible: false },
    });
    const chart = createChart(canvas, { autoSize: true, ...theme() });
    const series = chart.addSeries(CandlestickSeries, {
        upColor: '#159b83', downColor: '#d64a5e', borderVisible: false,
        wickUpColor: '#159b83', wickDownColor: '#d64a5e',
    });
    series.setData(candles);
    const markers = createSeriesMarkers(series, []);
    const algorithm = root.parentElement.querySelector('[data-algorithm]');
    const action = root.parentElement.querySelector('[data-action-toggle]');
    const outcome = root.parentElement.querySelector('[data-outcome-toggle]');
    const abstain = root.parentElement.querySelector('[data-abstain-toggle]');
    const render = () => {
        const marks = [];
        const selected = algorithm?.value || 'production';
        const actionPoints = selected === 'production' ? data.action : data.candidates?.action?.[selected];
        const outcomePoints = selected === 'production' ? data.outcome : data.candidates?.outcome?.[selected];
        if (action?.checked) for (const item of (actionPoints || [])) {
            if (!item.supported && !abstain.checked) continue;
            const buy = item.predicted === 'buy';
            const sell = item.predicted === 'sell';
            const correct = item.supported && item.predicted === item.actual;
            marks.push({
                time: item.time,
                position: buy ? 'belowBar' : sell ? 'aboveBar' : 'inBar',
                shape: buy ? 'arrowUp' : sell ? 'arrowDown' : 'circle',
                color: !item.supported ? '#94a3b8' : correct ? (buy ? '#16a34a' : sell ? '#dc2626' : '#64748b') : '#eab308',
                text: !item.supported ? 'ABSTAIN' : item.predicted?.toUpperCase(),
                size: 1,
            });
        }
        if (outcome?.checked) for (const item of (outcomePoints || [])) {
            if (!item.supported && !abstain.checked) continue;
            const bull = String(item.predicted).includes('bull');
            const bear = String(item.predicted).includes('bear');
            marks.push({
                time: item.time, position: 'aboveBar', shape: 'circle',
                color: !item.supported ? '#94a3b8' : item.predicted === item.actual
                    ? bull ? '#2563eb' : bear ? '#f97316' : '#64748b' : '#eab308',
                text: item.supported ? String(item.predicted).toUpperCase().replace('SUPER_', 'S.') : 'ABSTAIN',
                size: 0.7,
            });
        }
        marks.sort((a, b) => a.time - b.time || a.position.localeCompare(b.position));
        markers.setMarkers(marks);
    };
    [algorithm, action, outcome, abstain].forEach(toggle => toggle?.addEventListener('change', render));
    render();
    chart.timeScale().fitContent();
    chart.subscribeCrosshairMove(param => {
        if (!param.time) return;
        const selected = algorithm?.value || 'production';
        const actions = (selected === 'production' ? data.action : data.candidates?.action?.[selected] || []).filter(x => x.time === param.time);
        const outcomes = (selected === 'production' ? data.outcome : data.candidates?.outcome?.[selected] || []).filter(x => x.time === param.time);
        inspect.textContent = [formatTimestamp(Number(param.time) * 1000, { precision: 'minutes' }),
            ...actions.map(x => 'Action: ' + (x.supported ? x.predicted : 'ABSTAIN') + ' / actual ' + x.actual + ' / confidence ' + Number(x.confidence).toFixed(3)),
            ...outcomes.map(x => 'Outcome: ' + (x.supported ? x.predicted : 'ABSTAIN') + ' / actual ' + x.actual + ' / confidence ' + Number(x.confidence).toFixed(3))].join(' · ');
    });
    const observer = new MutationObserver(() => chart.applyOptions(theme()));
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
    window.addEventListener('pagehide', () => { observer.disconnect(); chart.remove(); }, { once: true });
    status.textContent = candles.length + ' historical candles · BUY/SELL and Outcome overlays (not executions)';
}
