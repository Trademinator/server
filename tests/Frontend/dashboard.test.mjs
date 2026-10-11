import { chartTimeOptions, formatTimestamp, subscribeTimeDisplay, configureTimeDisplay, setTimeMode } from '../../resources/js/components/time-display.js';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { humanMarkers, signalMarkers, actionDecisionMarkers, outcomeSegments } from '../../resources/js/components/dashboard.js';
import { historyPanDirection, mergeCandleHistory } from '../../resources/js/components/candlestick-history.js';
import { chartData, formatPrice } from '../../resources/js/components/market-review-chart.js';

const series = [60, 120, 240].map(time => ({ time, open: 100, high: 103, low: 98, close: 102, volume: 50 }));

test('shows directional decisions on source candle, hides HOLD and abstention', () => {
    const markers = signalMarkers({ series, signals: [
        { id:'buy',action:'buy',reason:'supported',source_time:60,recorded_at_ms:200000 },
        { id:'sell',action:'sell',reason:'degraded_action_only',source_time:120 },
        { id:'hold',action:'hodl',reason:'supported',source_time:240 },
        { id:'wait',action:'hodl',reason:'knn_abstention',source_time:240 },
    ] });
    assert.deepEqual(markers.map(({id,time,text})=>({id,time,text})),[
        {id:'buy',time:60,text:'BUY'}, {id:'sell',time:120,text:'SELL'}
    ]);
});
test('optional Action KNN overlay only shows supported blue BUY/SELL markers', () => {
    const marks = actionDecisionMarkers({series,signals:[
        {id:'a',source_time:60,action_prediction:'buy',action_reason:'supported'},
        {id:'b',source_time:120,action_prediction:'sell',action_reason:'supported'},
        {id:'c',source_time:240,action_prediction:'buy',action_reason:'no_eligible_k'},
    ]});
    assert.deepEqual(marks.map(m=>m.text),['A BUY','A SELL']);
    assert.ok(marks.every(m=>m.color==='#2563eb'));
});
test('Outcome segments use observed closes only after H candles exist', () => {
    const history=[60,120,180,240].map((time,i)=>({time,close:100+i}));
    const signals=[{id:'first',source_time:60,horizon_end_time:180,horizon_candles:2,
        outcome_prediction:'bull',outcome_reason:'supported'}];
    assert.deepEqual(outcomeSegments({series:history,signals})[0].points,
        [{time:60,value:100},{time:180,value:102}]);
    assert.equal(outcomeSegments({series:history.slice(0,2),signals}).length,0);
});


test('draws this trainers human candle labels separately from Server observations', () => {
    const markers = humanMarkers({ series, human_labels: [
        { time: 60, action: 'buy' },
        { time: 120, action: 'hold' },
        { time: 240, action: 'sell' },
        { time: 999, action: 'buy' },
    ] });
    assert.deepEqual(markers.map(({ time, text, shape }) => ({ time, text, shape })), [
        { time: 60, text: 'H BUY', shape: 'arrowUp' },
        { time: 120, text: 'H HOLD', shape: 'circle' },
        { time: 240, text: 'H SELL', shape: 'arrowDown' },
    ]);
});

test('uses the same directional edge rule as Candle Training and preserves existing bars while filling', () => {
    assert.equal(historyPanDirection({ from: -1, to: 2 }, { from: 0, to: 3 }, 20, true, false), 'older');
    assert.equal(historyPanDirection({ from: 14, to: 20 }, { from: 13, to: 19 }, 20, false, true), 'newer');
    assert.equal(historyPanDirection({ from: 14, to: 20 }, { from: 13, to: 19 }, 20, false, false), null);

    const newer = mergeCandleHistory(series, [
        { time: 240, open: 1, high: 1, low: 1, close: 999, volume: 1 },
        { time: 300, open: 103, high: 104, low: 102, close: 103, volume: 1 },
    ], 'newer', 301000);
    assert.deepEqual(newer.series.map(row => row.time), [60, 120, 240, 300]);
    assert.equal(newer.series[2].close, 102);
    assert.equal(newer.added, 1);
});

async function harness() {
    const { readFileSync } = await import('node:fs');
    const { runInNewContext } = await import('node:vm');
    const source = readFileSync(new URL('../../resources/js/components/dashboard.js', import.meta.url), 'utf8')
        .replace(/^import .*;\n/gm, '').replaceAll('export ', '').replace("await import('lightweight-charts')", 'await loadChartLibrary()');
    const elements = new Map();
    const root = { dataset: { chart: JSON.stringify({ series, signals: [], period: '1m', checked_at_ms: 300000, stale: false }),
        subscription: 'own-subscription', url: '/chart', tickSize: '0.01' },
        querySelector(selector) {
            if (!elements.has(selector)) elements.set(selector, { checked: true, hidden: false, disabled: false, textContent: '', listeners: {},
                addEventListener(name, callback) { this.listeners[name] = callback; }, removeEventListener(name) { delete this.listeners[name]; } });
            return elements.get(selector);
        } };
    const states = [];
    const chart = { addSeries() { const state = { data: [] }; states.push(state); return { setData(data) { state.data = data; } }; },
        panes: () => [{}, { setHeight() {} }], subscribeCrosshairMove() {}, applyOptions() {}, remove() {},
        timeScale: () => ({ fitContent() {}, getVisibleLogicalRange: () => null, setVisibleLogicalRange() {} }) };
    let response = { ok: true, json: async () => ({ subscription_id: 'own-subscription', chart: JSON.parse(root.dataset.chart) }) };
    let scheduled = 0;
    const context = { chartTimeOptions, formatTimestamp, subscribeTimeDisplay, chartData, formatPrice, historyPanDirection, mergeCandleHistory, Intl, Math, Number, JSON, Date, Array, Error, AbortController,
        loadChartLibrary: async () => ({ createChart: () => chart, createSeriesMarkers: () => ({ setMarkers() {} }) }),
        document: { hidden: false, documentElement: { classList: { contains: () => false } }, addEventListener() {}, removeEventListener() {} },
        window: { addEventListener() {}, removeEventListener() {} }, MutationObserver: class { observe() {} disconnect() {} },
        setTimeout() { return ++scheduled; }, clearTimeout() {}, fetch: async () => response };
    runInNewContext(source, context);
    const dispose = await context.mountDashboardChart(root);
    return { elements, states, dispose, setResponse(value) { response = value; },
        refresh: () => elements.get('[data-refresh]').listeners.click(), scheduled: () => scheduled };
}

test('refreshes safely, retains the previous chart during outages and clears it after subscription revocation', async () => {
    const view = await harness();
    assert.equal(view.states[0].data.length, 3);
    await view.refresh();
    assert.match(view.elements.get('[data-status]').textContent, /Checked/);
    view.setResponse({ ok: false, status: 503 });
    await view.refresh();
    assert.equal(view.states[0].data.length, 3);
    assert.match(view.elements.get('[data-status]').textContent, /last successful sample/);
    view.setResponse({ ok: false, status: 404 });
    await view.refresh();
    assert.equal(view.states[0].data.length, 0);
    assert.equal(view.states[1].data.length, 0);
    assert.equal(view.elements.get('[data-refresh]').disabled, true);
    assert.equal(view.elements.get('[data-auto]').checked, false);
    const scheduled = view.scheduled();
    await view.refresh();
    assert.equal(view.scheduled(), scheduled);
    view.dispose();
});

test('rejects a refresh response for another subscription', async () => {
    const view = await harness();
    view.setResponse({ ok: true, json: async () => ({ subscription_id: 'other', chart: { series: [] } }) });
    await view.refresh();
    assert.match(view.elements.get('[data-status]').textContent, /Unexpected chart response/);
    assert.equal(view.states[0].data.length, 3);
    view.dispose();
});

async function searchHarness({ owner = true } = {}) {
    const { readFileSync } = await import('node:fs');
    const { runInNewContext } = await import('node:vm');
    const source = readFileSync(new URL('../../resources/js/components/dashboard.js', import.meta.url), 'utf8')
        .replace(/^import .*;\n/gm, '').replaceAll('export ', '');
    const element = () => ({ value: '', textContent: '', innerHTML: 'initial cards', hidden: false, listeners: {}, attributes: {},
        addEventListener(name, callback) { this.listeners[name] = callback; },
        removeEventListener(name) { delete this.listeners[name]; },
        setAttribute(name, value) { this.attributes[name] = value; },
        removeAttribute(name) { delete this.attributes[name]; },
        querySelectorAll() { return []; }, replaceChildren() { this.innerHTML = ''; } });
    const input = element(), form = element(), results = element(), status = element(), attention = element();
    const attentionToggle = owner ? element() : null;
    const overview = element(), columns = new Set(['xl:grid-cols-4']);
    overview.classList = {
        toggle(name, enabled) { if (enabled) columns.add(name); else columns.delete(name); },
        contains(name) { return columns.has(name); },
    };
    const makePanel = () => owner ? { open: false, scrolls: 0, scrollIntoView() { this.scrolls++; } } : null;
    let hasAttention = owner, attentionPanel = makePanel(), cards = 'initial cards';
    Object.defineProperty(results, 'innerHTML', {
        get: () => cards,
        set(value) { cards = value; attentionPanel = hasAttention ? makePanel() : null; },
    });
    results.querySelector = selector => selector === '[data-dashboard-attention]' ? attentionPanel : null;
    results.replaceChildren = () => { cards = ''; attentionPanel = null; };
    const favorites = element(), scope = owner ? element() : null;
    favorites.checked = false;
    if (scope) scope.value = 'all';
    form.querySelector = selector => ({ 'input[type="search"]': input, '[name="favorites"]': favorites, '[name="scope"]': scope })[selector] ?? null;
    const parts = { '[data-market-search]': form, '[data-market-results]': results, '[data-search-status]': status };
    const root = { dataset: { url: '/dashboard', selected: 'selected-subscription' }, querySelector: selector => parts[selector] };
    const pending = [], timers = new Map();
    let timerId = 0;
    let navigated = null;
    const context = { URL, AbortController, Number, Error,
        window: { location: { href: 'https://trademinator.test/dashboard', assign(value) { navigated = value; } }, addEventListener() {} },
        document: { querySelector: selector => ({ '[data-attention-count]': attention, '[data-attention-toggle]': attentionToggle, '[data-dashboard-overview]': overview })[selector] },
        setTimeout(callback, delay) { const id = ++timerId; timers.set(id, { callback, delay }); return id; },
        clearTimeout(id) { timers.delete(id); },
        fetch(url, options) { return new Promise(resolve => pending.push({ url, options, resolve })); } };
    runInNewContext(source, context);
    const dispose = context.mountDashboardMarkets(root);
    const submit = value => {
        input.value = value;
        let prevented = false;
        const promise = form.listeners.submit({ preventDefault() { prevented = true; } });
        assert.equal(prevented, true);
        return promise;
    };
    const respond = (index, data, options = {}) => {
        if (owner && Number.isInteger(data.attention_count)) hasAttention = data.attention_count > 0;
        pending[index].resolve({ ok: true, status: 200, json: async () => data, ...options });
    };
    const toggleTitle = () => {
        attentionPanel.open = !attentionPanel.open;
        results.listeners.toggle({ target: attentionPanel });
    };
    return { input, favorites, scope, form, results, status, attention, attentionToggle, overview,
        get attentionPanel() { return attentionPanel; }, get navigated() { return navigated; },
        toggleTitle, pending, timers, submit, respond, dispose };
}

test('toggles the collapsed attention panel from the overview card and reflects title toggles', async () => {
    const view = await searchHarness();
    assert.equal(view.attentionPanel.open, false);
    assert.equal(view.attentionToggle.attributes['aria-expanded'], 'false');
    view.attentionToggle.listeners.click();
    assert.equal(view.attentionPanel.open, true);
    assert.equal(view.attentionToggle.attributes['aria-expanded'], 'true');
    assert.equal(view.attentionPanel.scrolls, 1);
    view.toggleTitle();
    assert.equal(view.attentionPanel.open, false);
    assert.equal(view.attentionToggle.attributes['aria-expanded'], 'false');
    view.toggleTitle();
    assert.equal(view.attentionToggle.attributes['aria-expanded'], 'true');
    view.attentionToggle.listeners.click();
    assert.equal(view.attentionPanel.open, false);
    assert.equal(view.attentionToggle.attributes['aria-expanded'], 'false');
    assert.equal(view.pending.length, 0);
    view.dispose();
    assert.equal(view.attentionToggle.listeners.click, undefined);
    assert.equal(view.results.listeners.toggle, undefined);
});

test('preserves the attention panel state across live searches and reconnects the overview card', async () => {
    const view = await searchHarness();
    view.attentionToggle.listeners.click();
    const previousPanel = view.attentionPanel;
    const first = view.submit('eth');
    view.respond(0, { html: 'filtered cards', count: 1, attention_count: 1 });
    await first;
    assert.notEqual(view.attentionPanel, previousPanel);
    assert.equal(view.attentionPanel.open, true);
    assert.equal(view.attentionToggle.attributes['aria-expanded'], 'true');
    const clear = view.submit('');
    view.toggleTitle();
    view.respond(1, { html: 'all cards', count: 27 });
    await clear;
    assert.equal(view.attentionPanel.open, false);
    assert.equal(view.attentionToggle.attributes['aria-expanded'], 'false');
    view.attentionToggle.listeners.click();
    assert.equal(view.attentionPanel.open, true);
    const revoked = view.submit('btc');
    view.respond(2, {}, { ok: false, status: 401 });
    await revoked;
    assert.equal(view.attentionToggle.attributes['aria-expanded'], 'false');
    assert.doesNotThrow(() => view.attentionToggle.listeners.click());
    view.dispose();
});

test('hides empty attention controls and restores them after live search finds issues', async () => {
    const view = await searchHarness();
    view.attentionToggle.listeners.click();
    assert.equal(view.attentionPanel.open, true);

    const clean = view.submit('healthy');
    view.respond(0, { html: 'healthy cards', count: 1, attention_count: 0 });
    await clean;
    assert.equal(view.attentionPanel, null);
    assert.equal(view.attentionToggle.hidden, true);
    assert.equal(view.attentionToggle.attributes['aria-expanded'], 'false');
    assert.equal(view.overview.classList.contains('xl:grid-cols-3'), true);
    assert.equal(view.overview.classList.contains('xl:grid-cols-4'), false);

    const issues = view.submit('blocked');
    view.respond(1, { html: 'blocked cards', count: 1, attention_count: 1 });
    await issues;
    assert.equal(view.attentionToggle.hidden, false);
    assert.equal(view.attention.textContent, 1);
    assert.equal(view.attentionPanel.open, true);
    assert.equal(view.attentionToggle.attributes['aria-expanded'], 'true');
    assert.equal(view.overview.classList.contains('xl:grid-cols-3'), false);
    assert.equal(view.overview.classList.contains('xl:grid-cols-4'), true);
    view.dispose();
});

test('searches and clears all followed markets without navigating or reloading', async () => {
    const view = await searchHarness();
    const first = view.submit(' eth/us ');
    assert.equal(view.pending[0].url.searchParams.get('q'), 'eth/us');
    assert.equal(view.pending[0].url.searchParams.get('page'), '1');
    assert.equal(view.pending[0].url.searchParams.get('subscription'), 'selected-subscription');
    view.respond(0, { html: 'ETH/USDT card', count: 1, attention_count: 0 });
    await first;
    assert.equal(view.results.innerHTML, 'ETH/USDT card');
    assert.equal(view.status.textContent, '1 matching market');
    assert.equal(view.attention.textContent, 0);
    const clear = view.submit('');
    view.respond(1, { html: 'all cards', count: 27 });
    await clear;
    assert.equal(view.pending[1].url.searchParams.get('q'), '');
    assert.equal(view.results.innerHTML, 'all cards');
    assert.equal(view.status.textContent, '27 matching markets');
    view.dispose();
});

test('carries owner scope and favourites through live search and navigates when those filters change', async () => {
    const view = await searchHarness();
    view.scope.value = 'mine';
    view.favorites.checked = true;
    const request = view.submit('xrp');
    assert.equal(view.pending[0].url.searchParams.get('scope'), 'mine');
    assert.equal(view.pending[0].url.searchParams.get('favorites'), '1');
    view.respond(0, { html: 'XRP card', count: 1, attention_count: 0 });
    await request;

    view.scope.value = 'all';
    view.scope.listeners.change();
    let url = new URL(view.navigated);
    assert.equal(url.searchParams.get('q'), 'xrp');
    assert.equal(url.searchParams.get('scope'), 'all');
    assert.equal(url.searchParams.get('favorites'), '1');
    assert.equal(url.searchParams.get('subscription'), 'selected-subscription');

    view.favorites.checked = false;
    view.favorites.listeners.change();
    url = new URL(view.navigated);
    assert.equal(url.searchParams.has('favorites'), false);
    view.dispose();
});

test('ignores out-of-order searches, preserves results on failure and clears them on lost access', async () => {
    const view = await searchHarness();
    const old = view.submit('btc');
    const latest = view.submit('coinbase');
    assert.equal(view.pending[0].options.signal.aborted, true);
    view.respond(1, { html: 'new results', count: 2 });
    await latest;
    view.respond(0, { html: 'stale results', count: 1 });
    await old;
    assert.equal(view.results.innerHTML, 'new results');
    const failed = view.submit('kraken');
    view.respond(2, {}, { ok: false, status: 503 });
    await failed;
    assert.equal(view.results.innerHTML, 'new results');
    assert.match(view.status.textContent, /Search failed/);
    const revoked = view.submit('kraken');
    view.respond(3, {}, { ok: false, status: 401 });
    await revoked;
    assert.equal(view.results.innerHTML, '');
    assert.match(view.status.textContent, /session has changed/);
    view.dispose();
});

test('debounces typing and fetches the chosen results page without navigation', async () => {
    const view = await searchHarness({ owner: false });
    view.input.value = 'b'; view.input.listeners.input();
    view.input.value = 'bi'; view.input.listeners.input();
    view.input.value = 'bit'; view.input.listeners.input();
    assert.equal(view.pending.length, 0);
    assert.equal([...view.timers.values()].filter(timer => timer.delay === 180).length, 1);
    const delayed = [...view.timers.values()].find(timer => timer.delay === 180).callback();
    assert.equal(view.pending[0].url.searchParams.get('q'), 'bit');
    view.respond(0, { html: 'first page', count: 30 });
    await delayed;
    let prevented = false;
    const page = view.results.listeners.click({ button: 0,
        target: { closest: () => ({ href: 'https://trademinator.test/dashboard?page=2&q=bit' }) },
        preventDefault() { prevented = true; } });
    assert.equal(prevented, true);
    assert.equal(view.pending[1].url.searchParams.get('page'), '2');
    assert.equal(view.pending[1].url.searchParams.get('q'), 'bit');
    view.respond(1, { html: 'second page', count: 30 });
    await page;
    assert.equal(view.results.innerHTML, 'second page');
    view.dispose();
});
