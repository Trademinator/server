import { chartTimeOptions, formatTimestamp, subscribeTimeDisplay } from './time-display.js';
import { chartData, formatPrice } from './market-review-chart.js';
import { historyPanDirection, mergeCandleHistory } from './candlestick-history.js';

export function mountDashboardMarkets(root) {
    const form = root.querySelector('[data-market-search]');
    const input = form.querySelector('input[type="search"]');
    const favorites = form.querySelector('[name="favorites"]');
    const scope = form.querySelector('[name="scope"]');
    const results = root.querySelector('[data-market-results]');
    const status = root.querySelector('[data-search-status]');
    const attentionToggle = document.querySelector('[data-attention-toggle]');
    const overview = document.querySelector('[data-dashboard-overview]');
    const attentionPanel = () => results.querySelector('[data-dashboard-attention]');
    let attentionOpen = attentionPanel()?.open ?? false;
    const updateAttentionCount = count => {
        if (!attentionToggle || !Number.isInteger(count)) return;
        attentionToggle.hidden = count === 0;
        overview?.classList.toggle('xl:grid-cols-3', count === 0);
        overview?.classList.toggle('xl:grid-cols-4', count > 0);
        const attention = document.querySelector('[data-attention-count]');
        if (attention) attention.textContent = count;
    };
    const reflectAttention = () => attentionToggle?.setAttribute('aria-expanded', attentionPanel()?.open ? 'true' : 'false');
    const attentionChanged = event => {
        if (event.target !== attentionPanel()) return;
        attentionOpen = event.target.open;
        reflectAttention();
    };
    const toggleAttention = () => {
        const panel = attentionPanel();
        if (!panel) return;
        panel.open = !panel.open;
        attentionOpen = panel.open;
        reflectAttention();
        if (panel.open) panel.scrollIntoView({ block: 'nearest' });
    };
    let timer, request, revision = 0, disposed = false;
    const addFilters = url => {
        if (scope?.value) url.searchParams.set('scope', scope.value);
        else url.searchParams.delete('scope');
        if (favorites?.checked) url.searchParams.set('favorites', '1');
        else url.searchParams.delete('favorites');
    };
    const logos = () => results.querySelectorAll('.dashboard-exchange-logo img').forEach(image => {
        const fallback = () => image.remove();
        image.addEventListener('error', fallback, { once: true });
        if (image.complete && image.naturalWidth === 0) fallback();
    });
    async function search(page = 1) {
        const current = ++revision;
        request?.abort();
        const controller = new AbortController();
        request = controller;
        const timeout = setTimeout(() => controller.abort(), 15000);
        const url = new URL(root.dataset.url, window.location.href);
        url.searchParams.set('q', input.value.trim());
        url.searchParams.set('page', page);
        addFilters(url);
        if (root.dataset.selected) url.searchParams.set('subscription', root.dataset.selected);
        results.setAttribute('aria-busy', 'true');
        status.textContent = 'Searching markets…';
        try {
            const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store', signal: request.signal });
            if ([401, 403, 419].includes(response.status) || response.redirected) {
                if (current === revision) { results.replaceChildren(); updateAttentionCount(0); reflectAttention(); }
                throw new Error('Your session has changed. Reload the dashboard.');
            }
            if (!response.ok) throw new Error('Search failed. Your previous results are still shown; try again.');
            const data = await response.json();
            if (disposed || current !== revision) return;
            if (typeof data.html !== 'string' || !Number.isInteger(data.count)) throw new Error('Unexpected search response. Try again.');
            const previousPanel = attentionPanel();
            if (previousPanel) attentionOpen = previousPanel.open;
            results.innerHTML = data.html;
            const panel = attentionPanel();
            if (panel) panel.open = attentionOpen;
            updateAttentionCount(data.attention_count);
            reflectAttention();
            logos();
            status.textContent = `${data.count} matching market${data.count === 1 ? '' : 's'}`;
        } catch (error) {
            if (!disposed && current === revision) status.textContent = error.name === 'AbortError'
                ? 'Search timed out. Try again.' : error.message;
        } finally {
            clearTimeout(timeout);
            if (current === revision) results.removeAttribute('aria-busy');
        }
    }
    const changed = () => {
        clearTimeout(timer);
        ++revision;
        request?.abort();
        timer = setTimeout(() => search(), 180);
    };
    const submit = event => { event.preventDefault(); clearTimeout(timer); return search(); };
    const paginate = event => {
        const link = event.target.closest('[data-market-pagination] a');
        if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button > 0) return;
        event.preventDefault();
        clearTimeout(timer);
        return search(new URL(link.href).searchParams.get('page') ?? 1);
    };
    const navigateFilters = () => {
        const url = new URL(root.dataset.url, window.location.href);
        const query = input.value.trim();
        if (query) url.searchParams.set('q', query);
        addFilters(url);
        if (root.dataset.selected) url.searchParams.set('subscription', root.dataset.selected);
        window.location.assign(url.toString());
    };
    const dispose = () => {
        disposed = true;
        ++revision;
        clearTimeout(timer);
        request?.abort();
        input.removeEventListener('input', changed);
        form.removeEventListener('submit', submit);
        favorites?.removeEventListener('change', navigateFilters);
        scope?.removeEventListener('change', navigateFilters);
        results.removeEventListener('click', paginate);
        results.removeEventListener('toggle', attentionChanged, true);
        attentionToggle?.removeEventListener('click', toggleAttention);
    };
    input.addEventListener('input', changed);
    form.addEventListener('submit', submit);
    favorites?.addEventListener('change', navigateFilters);
    scope?.addEventListener('change', navigateFilters);
    results.addEventListener('click', paginate);
    results.addEventListener('toggle', attentionChanged, true);
    attentionToggle?.addEventListener('click', toggleAttention);
    window.addEventListener('pagehide', event => { if (!event.persisted) dispose(); }, { once: true });
    logos();
    reflectAttention();
    return dispose;
}

// Attach observations to their actual source candles; recording time remains separate.
export function signalMarkers(chart) {
    const candles = new Set((chart.series ?? []).map(c => Number(c.time)));
    return (chart.signals ?? []).flatMap(s => {
        const time = Number(s.source_time);
        if (!candles.has(time) || !['supported','degraded_action_only'].includes(s.reason) ||
            !['buy','sell'].includes(s.action)) return [];
        const buy = s.action === 'buy';
        return [{ id:s.id, time, position:buy?'belowBar':'aboveBar',
            color:buy?'#087b6b':'#c33e50', shape:buy?'arrowUp':'arrowDown', text:buy?'BUY':'SELL' }];
    }).sort((a,b)=>a.time-b.time);
}
export function actionDecisionMarkers(chart) {
    const candles = new Set((chart.series ?? []).map(c => Number(c.time)));
    return (chart.signals ?? []).flatMap(s => {
        const time = Number(s.source_time), buy = s.action_prediction === 'buy';
        if (!candles.has(time) || s.action_reason !== 'supported' ||
            !['buy','sell'].includes(s.action_prediction)) return [];
        return [{ id:'action-'+s.id, time, position:buy?'belowBar':'aboveBar',
            color:'#2563eb', shape:buy?'arrowUp':'arrowDown', text:buy?'A BUY':'A SELL' }];
    }).sort((a,b)=>a.time-b.time);
}
// Observed movement, never a predicted numerical target price.
export function outcomeSegments(chart) {
    const candles = chart.series ?? [];
    const positions = new Map(candles.map((c,i)=>[Number(c.time),i]));
    return (chart.signals ?? []).flatMap(s => {
        if (s.outcome_reason !== 'supported' ||
            !['bull','super_bull','bear','super_bear'].includes(s.outcome_prediction)) return [];
        const start=Number(s.source_time), end=Number(s.horizon_end_time), h=Number(s.horizon_candles);
        const a=positions.get(start), b=positions.get(end);
        if (!Number.isInteger(h) || h<1 || a===undefined || b!==a+h) return [];
        return [{ id:'outcome-'+s.id, points:[
            {time:start,value:Number(candles[a].close)},
            {time:end,value:Number(candles[b].close)}
        ] }];
    });
}

export function humanMarkers(chart) {
    const candles = new Set((chart.series ?? []).map(row => Number(row.time)));
    return (chart.human_labels ?? []).flatMap(label => {
        const time = Number(label.time);
        const action = String(label.action ?? '');
        if (!candles.has(time) || !['buy', 'hold', 'sell'].includes(action)) return [];
        const buy = action === 'buy';
        const sell = action === 'sell';

        return [{
            id: `human-${time}`,
            time,
            position: buy ? 'belowBar' : 'aboveBar',
            color: buy ? '#087b6b' : sell ? '#c33e50' : '#64748b',
            shape: buy ? 'arrowUp' : sell ? 'arrowDown' : 'circle',
            text: `H ${action.toUpperCase()}`,
            size: 1,
        }];
    }).sort((a, b) => a.time - b.time);
}

export function clientMarkers(chart, tickSize = 0.00000001) {
    const candles = chart.series ?? [];
    return (chart.client_events ?? []).flatMap(event => {
        const candle = candles.find(row => row.time * 1000 >= Number(event.occurred_at_ms));
        if (!candle) return [];
        const side = String(event.side ?? '');
        const buy = side === 'buy';
        const fill = event.event === 'fill';
        const price = fill && Number.isFinite(Number(event.price)) ? ` ${formatPrice(Number(event.price), tickSize)}` : '';
        const label = fill ? `FILL ${side.toUpperCase()}${price}`
            : event.event === 'acted' ? `C ${side.toUpperCase()}`
                : `C ${String(event.event ?? '').toUpperCase()}`;

        return [{
            id: `client-${event.id}`,
            time: candle.time,
            position: buy ? 'belowBar' : 'aboveBar',
            color: fill ? '#7c3aed' : '#2563eb',
            shape: fill ? 'square' : buy ? 'arrowUp' : side === 'sell' ? 'arrowDown' : 'circle',
            text: label,
            size: fill ? 2 : 1,
        }];
    }).sort((a, b) => a.time - b.time);
}

export async function mountDashboardChart(root) {
    const canvas = root.querySelector('[data-canvas]');
    const status = root.querySelector('[data-status]');
    const legend = root.querySelector('[data-legend]');
    const refreshButton = root.querySelector('[data-refresh]');
    const fitButton = root.querySelector('[data-fit]');
    const earliestButton = root.querySelector('[data-earliest]');
    const historyStatus = root.querySelector('[data-history-status]');
    const historyRetry = root.querySelector('[data-history-retry]');
    const showMarkers = root.querySelector('[data-markers]');
    const showHuman = root.querySelector('[data-human-training]');
    const showAction = root.querySelector('[data-action-decisions]');
    const showOutcome = root.querySelector('[data-outcome-decisions]');
    const showClient = root.querySelector('[data-client-activity]');
    const automatic = root.querySelector('[data-auto]');
    let data = JSON.parse(root.dataset.chart);
    data.human_labels ??= [];
    data.client_events ??= [];
    data.has_older ??= false;
    data.has_newer ??= false;

    let chart, price, volume, markers, observer, timer, request, timeout, historyTimer, historyRequest, unsubscribeTime;
    let chartLibrary, outcomeLines = [];
    let inspectedCandle, lastTimedStatus;
    let disposed = false, stopped = false, busy = false, historyBusy = false, historyFailed = false;
    let userInteracted = false, previousPeriod = null, lastVisibleRange = null, retryDirection = 'older';
    let browsingHistory = false;
    let historyCeilingMs = Number(data.last_closed_at_ms) || null;
    const tickSize = Number(root.dataset.tickSize) || 0.00000001;

    const theme = () => {
        const dark = document.documentElement.classList.contains('dark');
        return { layout: { background: { type: 'solid', color: dark ? '#111827' : '#ffffff' }, textColor: dark ? '#e5edf5' : '#172c43', attributionLogo: true },
            grid: { vertLines: { color: dark ? '#263348' : '#edf1f5' }, horzLines: { color: dark ? '#263348' : '#edf1f5' } },
            timeScale: { timeVisible: true, secondsVisible: false } };
    };
    const mergeSignals = (current = [], incoming = []) => [...new Map([...current, ...incoming].map(item => [item.id, item])).values()]
        .sort((a, b) => Number(a.recorded_at_ms) - Number(b.recorded_at_ms));
    const mergeHuman = (current = [], incoming = []) => [...new Map([...current, ...incoming].map(item => [Number(item.time), {
        time: Number(item.time), action: item.action,
    }])).values()].sort((a, b) => a.time - b.time);
    const mergeClient = (current = [], incoming = []) => [...new Map([...current, ...incoming].map(item => [item.id, item])).values()]
        .sort((a, b) => Number(a.occurred_at_ms) - Number(b.occurred_at_ms));
    const updateMarkers = () => {
        if (!markers) return;
        markers.setMarkers([
            ...(showMarkers?.checked ? signalMarkers(data) : []),
            ...(showAction?.checked ? actionDecisionMarkers(data) : []),
            ...(showHuman?.checked ? humanMarkers(data) : []),
            ...(showClient?.checked ? clientMarkers(data, tickSize) : []),
        ].sort((a, b) => Number(a.time) - Number(b.time) || String(a.id).localeCompare(String(b.id))));
    };
    const updateOutcomes = () => {
        if (!chart || !price) return;
        outcomeLines.forEach(series => chart.removeSeries(series));
        outcomeLines = [];
        if (!showOutcome?.checked) return;
        for (const segment of outcomeSegments(data).slice(-120)) {
            const line = chart.addSeries(chartLibrary.LineSeries, {color:'#2563eb',
                lineWidth:2,priceLineVisible:false,lastValueVisible:false,
                crosshairMarkerVisible:false});
            line.setData(segment.points);
            outcomeLines.push(line);
        }
    };
    const updateOverlays = () => { updateMarkers(); updateOutcomes(); };
    const updateStatus = () => {
        const checked = data.checked_at_ms != null && Number.isFinite(Number(data.checked_at_ms))
            ? `Checked ${formatTimestamp(Number(data.checked_at_ms))}.`
            : '';
        status.textContent = `${data.stale ? 'History is stale or awaiting collection. ' : 'Stored closed candles are current. '}`
            + `${data.gaps ? 'Gaps in loaded history; missing intervals are not filled. ' : ''}`
            + `${data.invalid_candles ? 'Invalid candles were omitted. ' : ''}${checked}`;
        lastTimedStatus = status.textContent;
    };
    const draw = ({ fit = false, offset = 0 } = {}) => {
        const values = chartData(data);
        const range = chart.timeScale().getVisibleLogicalRange();
        price.setData(values.candles);
        volume.setData(values.volume);
        updateOverlays();

        if (fit || previousPeriod !== data.period || range === null) {
            chart.timeScale().fitContent();
        } else {
            chart.timeScale().setVisibleLogicalRange({ from: range.from + offset, to: range.to + offset });
        }
        lastVisibleRange = chart.timeScale().getVisibleLogicalRange();
        previousPeriod = data.period;
        canvas.hidden = values.candles.length === 0;
        earliestButton.disabled = historyCeilingMs === null || !root.dataset.historyUrl;
        inspectedCandle = null;
        const latest = data.series.at(-1);
        legend.textContent = latest
            ? `Latest loaded price ${formatPrice(latest.close, tickSize)} · ${data.series.length} candles · ${data.period}`
            : 'No closed candles collected yet.';
        updateStatus();
    };
    const schedule = () => {
        clearTimeout(timer);
        if (!disposed && !stopped && automatic.checked && !document.hidden) {
            timer = setTimeout(() => refresh(false), 60000);
        }
    };
    async function refresh(resetToLatest = false) {
        if (disposed || stopped || busy) return;
        if (browsingHistory && !resetToLatest) {
            schedule();
            return;
        }

        busy = true;
        refreshButton.disabled = true;
        request = new AbortController();
        timeout = setTimeout(() => request?.abort(), 15000);
        try {
            const response = await fetch(root.dataset.url, { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store', signal: request.signal });
            if ([401, 403, 404, 419].includes(response.status) || response.redirected) {
                stopped = true;
                automatic.checked = false;
                automatic.disabled = true;
                data.series = [];
                data.signals = [];
                data.human_labels = [];
                data.client_events = [];
                price.setData([]);
                volume.setData([]);
                markers.setMarkers([]);
                throw new Error('Your session or active subscription has changed. Reload the dashboard.');
            }
            if (response.status === 429) throw new Error('Refresh limit reached. Try again in a minute.');
            if (!response.ok) throw new Error('Refresh failed. Showing the last successful sample.');
            const result = await response.json();
            if (result.subscription_id !== root.dataset.subscription || !Array.isArray(result.chart?.series)) {
                throw new Error('Unexpected chart response. Reload the dashboard.');
            }
            if (disposed) return;

            data = result.chart;
            data.human_labels ??= [];
            data.client_events ??= [];
            data.has_older ??= false;
            data.has_newer ??= false;
            historyCeilingMs = Number(data.last_closed_at_ms) || null;
            browsingHistory = false;
            historyFailed = false;
            userInteracted = false;
            if (historyRetry) historyRetry.hidden = true;
            if (historyStatus) historyStatus.textContent = '';
            draw({ fit: true });
        } catch (error) {
            if (!disposed) status.textContent = error.name === 'AbortError'
                ? 'Refresh timed out. Showing the last successful sample.' : error.message;
        } finally {
            clearTimeout(timeout);
            request = null;
            busy = false;
            refreshButton.disabled = stopped || disposed;
            schedule();
        }
    }
    const applyHistoryPage = (page, direction) => {
        if (page.period !== data.period || !Array.isArray(page.series) || !Array.isArray(page.signals)
            || !Array.isArray(page.human_labels) || !Array.isArray(page.client_events)) {
            throw new Error('Unexpected history response. Reload the dashboard.');
        }

        if (direction === 'earliest') {
            data = {
                ...data,
                ...page,
                series: page.series,
                signals: page.signals,
                human_labels: page.human_labels,
                client_events: page.client_events,
                stale: data.stale,
                last_closed_at_ms: historyCeilingMs,
            };
            browsingHistory = true;
            userInteracted = false;
            draw({ fit: true });
            return page.series.length;
        }

        const merged = mergeCandleHistory(data.series, page.series, direction, historyCeilingMs);
        data.series = merged.series;
        data.signals = mergeSignals(data.signals, page.signals);
        data.human_labels = mergeHuman(data.human_labels, page.human_labels);
        data.client_events = mergeClient(data.client_events, page.client_events);
        data.gaps = Math.max(Number(data.gaps ?? 0), Number(page.gaps ?? 0));
        data.invalid_candles = Number(data.invalid_candles ?? 0) + Number(page.invalid_candles ?? 0);
        if (direction === 'older') data.has_older = page.has_older === true && merged.added > 0;
        else data.has_newer = page.has_newer === true && merged.added > 0;
        browsingHistory = true;
        draw({ offset: direction === 'older' ? merged.added : 0 });

        return merged.added;
    };
    async function loadHistory(direction = 'older') {
        const newer = direction === 'newer';
        if (disposed || historyBusy || historyCeilingMs === null || !root.dataset.historyUrl) return;
        if (direction === 'older' && !data.has_older) return;
        if (newer && !data.has_newer) return;

        clearTimeout(historyTimer);
        historyBusy = true;
        historyFailed = false;
        retryDirection = direction;
        if (historyRetry) historyRetry.hidden = true;
        if (historyStatus) historyStatus.textContent = direction === 'earliest'
            ? 'Loading the earliest stored candles…'
            : `Loading ${direction} candles…`;
        historyRequest = new AbortController();
        const historyTimeout = setTimeout(() => historyRequest?.abort(), 15000);
        try {
            const url = new URL(root.dataset.historyUrl, window.location.href);
            url.searchParams.set('direction', direction);
            url.searchParams.set('until_ms', String(historyCeilingMs));
            if (direction !== 'earliest') {
                const edge = newer ? data.series.at(-1) : data.series[0];
                if (!edge) return;
                url.searchParams.set('anchor_ms', String(Number(edge.time) * 1000));
            }

            const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin',
                cache: 'no-store', signal: historyRequest.signal });
            if ([401, 403, 404, 419].includes(response.status) || response.redirected) {
                throw new Error('Your session or active subscription has changed. Reload the dashboard.');
            }
            if (response.status === 429) throw new Error('History limit reached. Wait a minute, then retry.');
            if (!response.ok) throw new Error('History could not load. Retry or reload the dashboard.');

            const result = await response.json();
            if (result.subscription_id !== root.dataset.subscription) throw new Error('Unexpected history response. Reload the dashboard.');
            const added = applyHistoryPage(result.chart, direction);
            if (historyStatus) {
                if (direction === 'earliest') {
                    historyStatus.textContent = data.has_newer
                        ? 'Earliest stored data loaded. Drag right to fill newer candles from this browsing snapshot.'
                        : 'Earliest stored data loaded.';
                } else if (direction === 'older') {
                    historyStatus.textContent = data.has_older
                        ? `${added} older candles loaded.` : 'Earliest available data reached.';
                } else {
                    historyStatus.textContent = data.has_newer
                        ? `${added} newer candles loaded.` : 'Newest candle from this browsing snapshot reached.';
                }
            }
        } catch (error) {
            if (!disposed) {
                historyFailed = true;
                if (historyRetry) historyRetry.hidden = false;
                if (historyStatus) historyStatus.textContent = error.name === 'AbortError'
                    ? 'History loading timed out. Please retry.' : error.message;
            }
        } finally {
            clearTimeout(historyTimeout);
            historyRequest = null;
            historyBusy = false;
        }
    }

    const retryHistory = () => loadHistory(retryDirection);
    const onVisibleRangeChange = range => {
        clearTimeout(historyTimer);
        const previousRange = lastVisibleRange;
        lastVisibleRange = range;
        if (!userInteracted || !range || !previousRange || historyBusy || historyFailed || disposed) return;
        const direction = historyPanDirection(range, previousRange, data.series.length, data.has_older, data.has_newer);
        if (direction) historyTimer = setTimeout(() => loadHistory(direction), 180);
    };
    const onInteraction = () => { userInteracted = true; };
    const visibility = () => { if (document.hidden) clearTimeout(timer); else schedule(); };
    const fit = () => {
        userInteracted = false;
        clearTimeout(historyTimer);
        chart?.timeScale().fitContent();
        lastVisibleRange = chart?.timeScale().getVisibleLogicalRange() ?? null;
    };
    const manualRefresh = () => refresh(true);
    const earliest = () => loadHistory('earliest');
    const dispose = () => {
        disposed = true;
        clearTimeout(timer);
        clearTimeout(timeout);
        clearTimeout(historyTimer);
        request?.abort();
        historyRequest?.abort();
        unsubscribeTime?.();
        observer?.disconnect();
        chart?.timeScale().unsubscribeVisibleLogicalRangeChange?.(onVisibleRangeChange);
        chart?.remove();
        refreshButton.removeEventListener('click', manualRefresh);
        fitButton.removeEventListener('click', fit);
        earliestButton.removeEventListener('click', earliest);
        historyRetry?.removeEventListener('click', retryHistory);
        showMarkers.removeEventListener('change', updateMarkers);
        showAction?.removeEventListener('change', updateMarkers);
        showOutcome?.removeEventListener('change', updateOutcomes);
        showHuman?.removeEventListener('change', updateMarkers);
        showClient?.removeEventListener('change', updateMarkers);
        automatic.removeEventListener('change', schedule);
        canvas.removeEventListener('wheel', onInteraction);
        canvas.removeEventListener('pointerdown', onInteraction);
        document.removeEventListener('visibilitychange', visibility);
        window.removeEventListener('pagehide', pagehide);
    };
    const pagehide = event => { if (event.persisted) clearTimeout(timer); else dispose(); };
    window.addEventListener('pagehide', pagehide);

    try {
        const library = await import('lightweight-charts');
        if (disposed) return dispose;
        chartLibrary = library;
        chart = library.createChart(canvas, { autoSize: true, ...theme() });
        price = chart.addSeries(library.CandlestickSeries, { upColor: '#159b83', downColor: '#d64a5e', borderVisible: false,
            wickUpColor: '#159b83', wickDownColor: '#d64a5e',
            priceFormat: { type: 'custom', minMove: tickSize, formatter: value => formatPrice(value, tickSize) } });
        volume = chart.addSeries(library.HistogramSeries, { priceFormat: { type: 'volume' }, priceLineVisible: false,
            lastValueVisible: false }, 1);
        chart.panes()[1].setHeight(80);
        markers = library.createSeriesMarkers(price, []);
        const renderLegend = () => {
            const candle = inspectedCandle;
            if (candle) legend.textContent = `${formatTimestamp(candle.time * 1000, { precision: 'minutes' })} · O ${formatPrice(candle.open, tickSize)} H ${formatPrice(candle.high, tickSize)} L ${formatPrice(candle.low, tickSize)} C ${formatPrice(candle.close, tickSize)}`;
        };
        chart.subscribeCrosshairMove(({ time }) => {
            inspectedCandle = data.series.find(row => row.time === time) ?? inspectedCandle;
            renderLegend();
        });
        const renderTime = () => {
            chart.applyOptions(chartTimeOptions());
            renderLegend();
            if (status.textContent === lastTimedStatus) updateStatus();
        };
        unsubscribeTime = subscribeTimeDisplay(renderTime);
        renderTime();
        observer = new MutationObserver(() => chart?.applyOptions(theme()));
        observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
        chart.timeScale().subscribeVisibleLogicalRangeChange?.(onVisibleRangeChange);
        canvas.addEventListener('wheel', onInteraction, { passive: true });
        canvas.addEventListener('pointerdown', onInteraction);
        draw();

        refreshButton.addEventListener('click', manualRefresh);
        fitButton.addEventListener('click', fit);
        earliestButton.addEventListener('click', earliest);
        historyRetry?.addEventListener('click', retryHistory);
        showMarkers.addEventListener('change', updateMarkers);
        showAction?.addEventListener('change', updateMarkers);
        showOutcome?.addEventListener('change', updateOutcomes);
        showHuman?.addEventListener('change', updateMarkers);
        showClient?.addEventListener('change', updateMarkers);
        automatic.addEventListener('change', schedule);
        document.addEventListener('visibilitychange', visibility);
        schedule();
    } catch {
        dispose();
        canvas.hidden = true;
        status.textContent = 'The chart could not load. The candle table and recorded signal journal remain available.';
    }

    return dispose;
}

export async function loadDashboardSuggestions(root) {
    const abort = new AbortController();
    const timeout = setTimeout(() => abort.abort(), 30000);
    try {
        const response = await fetch(root.dataset.url, { headers: { Accept: 'text/html' }, credentials: 'same-origin', cache: 'no-store', signal: abort.signal });
        if (!response.ok || response.redirected) throw new Error('Unavailable');
        // Same-origin, authenticated Blade output; all variable content is escaped server-side.
        root.innerHTML = await response.text();
    } catch {
        root.querySelector('[role="status"]').textContent = 'Suggestions could not load. Open pair suggestions to try again.';
    } finally {
        clearTimeout(timeout);
    }
}
