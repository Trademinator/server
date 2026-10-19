import { chartTimeOptions, formatTimestamp } from './time-display.js';

/**
 * Outcomes are observed close-to-close changes H complete candles later.
 * The predicted class is retained as metadata, never converted to a target price.
 */
export function evaluationOutcomeSegments(candles, predictions, horizon) {
    if (!Number.isInteger(horizon) || horizon < 1) return [];
    const positions = new Map(candles.map((c, i) => [Number(c.time), i]));
    return (predictions || []).flatMap(item => {
        if (!item.supported || !['super_bear', 'bear', 'neutral', 'bull', 'super_bull'].includes(item.predicted)) return [];
        const start = positions.get(Number(item.time));
        const end = start === undefined ? -1 : start + horizon;
        if (end < 0 || end >= candles.length) return [];
        const a = candles[start], b = candles[end];
        if (!Number.isFinite(a.close) || !Number.isFinite(b.close)) return [];
        return [{ predicted: item.predicted, actual: item.actual, confidence: item.confidence,
            points: [{ time: a.time, value: a.close }, { time: b.time, value: b.close }] }];
    });
}

export async function mountEvaluationChart(root) {
    const { createChart, CandlestickSeries, LineSeries, createSeriesMarkers } = await import('lightweight-charts');
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
    let outcomeLines = [];
    const clearOutcomeLines = () => {
        outcomeLines.forEach(line => chart.removeSeries(line));
        outcomeLines = [];
    };
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
        clearOutcomeLines();
        if (outcome?.checked) {
            const segments = evaluationOutcomeSegments(candles, outcomePoints, Number(data.horizon_candles)).slice(-120);
            for (const segment of segments) {
                const color = segment.predicted.includes('bear') ? '#dc2626'
                    : segment.predicted.includes('bull') ? '#16a34a' : '#64748b';
                const line = chart.addSeries(LineSeries, { color, lineWidth: 2,
                    priceLineVisible: false, lastValueVisible: false, crosshairMarkerVisible: false });
                line.setData(segment.points);
                outcomeLines.push(line);
                const [a, b] = segment.points;
                if (segment.predicted === 'neutral' || Math.abs(b.value / a.value - 1) < 0.002) {
                    marks.push({ time: a.time, position: 'aboveBar', shape: 'circle', color,
                        text: segment.predicted.toUpperCase().replace('SUPER_', 'S.'), size: 0.5 });
                }
            }
            if (abstain?.checked) for (const item of (outcomePoints || [])) {
                if (!item.supported) marks.push({ time: item.time, position: 'aboveBar',
                    shape: 'circle', color: '#94a3b8', text: 'ABSTAIN', size: 0.5 });
            }
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
    window.addEventListener('pagehide', () => { observer.disconnect(); clearOutcomeLines(); chart.remove(); }, { once: true });
    status.textContent = candles.length + ' historical candles · BUY/SELL markers and observed close-to-close Outcome lines over H candles (not price targets or executions)';
}
