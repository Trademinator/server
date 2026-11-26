<x-layouts.app :title="__('Market subscriptions')">
    <style>
        .market-page { color: #212529; }
        .dark .market-page { color: #f8f9fa; }
        .market-hero {
            border-radius: 16px; padding: 24px 28px; color: #fff;
            background: linear-gradient(115deg, #084298, #0b5ed7);
            box-shadow: 0 12px 28px rgba(13, 110, 253, .18);
        }
        .market-hero h1 { font-size: 1.7rem; font-weight: 750; line-height: 1.2; }
        .market-hero p { margin-top: 8px; color: #fff; }
        .market-panel, .market-group {
            border: 1px solid #ced4da; border-radius: 16px; background: #fff;
            color: #212529; box-shadow: 0 4px 16px rgba(33, 37, 41, .06);
        }
        .dark .market-panel, .dark .market-group {
            background: #111827; color: #f8f9fa; border-color: #5c6672;
        }
        .market-panel { padding: 22px; }
        .market-panel h2, .market-list h2 { font-size: 1.15rem; font-weight: 750; }
        .market-row-scroll { overflow-x: auto; padding: 4px 2px 10px; }
        .market-form-row {
            display: grid; min-width: 640px;
            grid-template-columns: minmax(160px, 1.45fr) minmax(145px, 1.2fr) minmax(115px, .75fr) auto;
            align-items: end; gap: 12px;
        }
        .market-field { display: block; font-size: .86rem; font-weight: 700; }
        .market-control, .market-output {
            display: block; width: 100%; min-height: 46px; margin-top: 7px;
            padding: 9px 11px; border: 2px solid #adb5bd; border-radius: 10px;
            background: #fff; color: #212529; font-size: .94rem; font-weight: 600;
        }
        .market-control:disabled { background: #e9ecef; color: #495057; opacity: 1; cursor: not-allowed; }
        .market-control:focus-visible, .market-subscribe:focus-visible, .market-unsubscribe:focus-visible, .market-review:focus-visible {
            outline: 3px solid #ffc107; outline-offset: 2px; border-color: #0d6efd;
        }
        .market-output { color: #084298; font-variant-numeric: tabular-nums; }
        .market-subscribe {
            min-height: 46px; padding: 9px 21px; border-radius: 10px;
            background: #0b5ed7; color: #fff; font-weight: 750;
            box-shadow: 0 5px 14px rgba(13, 110, 253, .25);
        }
        .market-subscribe:hover:not(:disabled) { background: #084298; }
        .market-subscribe:disabled { background: #495057; color: #fff; box-shadow: none; cursor: not-allowed; }
        .market-explain-title { margin: 16px 0 10px; font-size: .93rem; font-weight: 750; }
        .market-explain { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; }
        .market-explain > div { padding: 12px 13px; border-radius: 10px; color: #212529; font-size: .83rem; line-height: 1.45; }
        .market-explain strong { display: block; margin-bottom: 4px; font-size: .9rem; }
        .market-explain .exchange { background: #cfe2ff; border-left: 4px solid #0d6efd; }
        .market-explain .pair { background: #cff4fc; border-left: 4px solid #0dcaf0; }
        .market-explain .tick { background: #fff3cd; border-left: 4px solid #ffc107; }
        .market-explain .period { background: #d1e7dd; border-left: 4px solid #198754; }
        @media (max-width: 1050px) { .market-explain { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 600px) { .market-explain { grid-template-columns: 1fr; } }
        .market-feedback { margin-top: 8px; min-height: 1.4em; font-size: .86rem; font-weight: 600; color: #084298; }
        .dark .market-feedback { color: #9ec5fe; }
        .market-feedback.is-error { color: #842029; }
        .dark .market-feedback.is-error { color: #ffb3b3; }
        .market-errors { padding: 12px 15px; border-radius: 10px; background: #f8d7da; color: #842029; }
        .market-flash { border-radius: 10px; padding: 12px 15px; background: #d1e7dd; color: #0f5132; font-weight: 650; }
        .market-list h2 { margin-bottom: 12px; }
        .market-group { margin-top: 20px; overflow: hidden; }
        .market-group-heading { margin: 0; background: #f1f5f9; font-size: 1.02rem; font-weight: 750; }
        .market-group-toggle {
            display: flex; flex-wrap: wrap; align-items: center; gap: 11px;
            width: 100%; padding: 16px 19px; background: transparent; color: inherit;
            text-align: left; cursor: pointer;
        }
        .dark .market-group-heading { background: #1f2937; }
        .market-group-toggle:hover { background: rgba(13, 110, 253, .08); }
        .market-group-toggle:focus-visible { outline: 3px solid #ffc107; outline-offset: -3px; }
        .market-group-count { margin-left: auto; font-size: .83rem; font-weight: 600; color: #495057; }
        .dark .market-group-count { color: #dbe4ef; }
        .market-group-chevron {
            flex: none; width: 9px; height: 9px; margin: 0 3px 4px;
            border-right: 2px solid currentColor; border-bottom: 2px solid currentColor;
            transform: rotate(45deg); transition: transform .35s ease;
        }
        .market-group-toggle[aria-expanded="true"] .market-group-chevron { transform: rotate(225deg); margin-bottom: -4px; }
        .market-group > .collapse, .market-group > .collapsing { visibility: visible; }
        .market-group > .collapse:not(.show) { display: none; }
        .market-group > .collapsing { height: 0; overflow: hidden; transition: height .35s ease; }
        @media (prefers-reduced-motion: reduce) {
            .market-group > .collapsing, .market-group-chevron { transition: none; }
        }
        .market-logo {
            position: relative; display: inline-flex; flex: none; align-items: center; justify-content: center;
            width: 32px; height: 32px; border: 0; background: transparent;
            color: inherit; font-size: .9rem; overflow: hidden;
        }
        .market-logo.has-image { width: 88px; height: 40px; }
        .market-logo.has-image .market-logo-initial { visibility: hidden; }
        .market-logo img { position: absolute; inset: 0; width: 100%; height: 100%; padding: 4px; object-fit: contain; background: transparent; }
        .market-group-items { margin: 0; padding: 0; list-style: none; }
        .market-row {
            display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 14px;
            padding: 16px 19px; border-top: 1px solid #dee2e6;
        }
        .dark .market-row { border-top-color: #374151; }
        .market-row-title { font-weight: 750; overflow-wrap: anywhere; }
        .market-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
        .market-review { border-radius: 9px; padding: 8px 12px; background: #0b5ed7; color: #fff; font-weight: 700; }
        .market-review:hover { background: #084298; }
        .market-meta { margin-top: 4px; font-size: .87rem; color: #495057; }
        .dark .market-meta { color: #dbe4ef; }
        .market-badge { margin-right: 6px; border-radius: 999px; padding: 3px 9px; background: #fff3cd; color: #664d03; font-weight: 750; }
        .market-badge.is-active { background: #d1e7dd; color: #0f5132; }
        .market-unsubscribe { border: 1px solid #0d6efd; border-radius: 9px; padding: 7px 12px; color: #0b5ed7; font-weight: 700; }
        .dark .market-unsubscribe { color: #9ec5fe; border-color: #9ec5fe; }
        .market-unsubscribe:hover { background: #cfe2ff; color: #084298; }
    </style>
    <section class="market-page max-w-6xl space-y-8">
        <header class="market-hero">
            <h1>Market subscriptions</h1>
            <p>Choose a trading pair. Trademinator collects its candles and shares the feed with everyone watching that market.</p>
        </header>

        @if (session('status'))
            <p role="status" class="market-flash">{{ session('status') }}</p>
        @endif

        <div class="market-panel">
            <h2>Need help choosing pairs?</h2>
            @if (\Illuminate\Support\Facades\Route::has(['markets.suggestions', 'markets.preferences.store', 'markets.preferences.destroy']))
            <p style="margin: 8px 0 14px">Answer a few optional questions to explore spot pairs that fit your holdings and preferences. You make the final choice.</p>
            <a href="{{ route('markets.suggestions') }}" class="market-subscribe" style="display: inline-block">Help me choose pairs</a>
            @else
            <p role="status" class="market-feedback">Pair suggestions are temporarily unavailable. You can still manage your market subscriptions below.</p>
            @endif
        </div>
        <form method="POST" action="{{ route('markets.store') }}" class="market-panel">
            @csrf
            <h2>Add a market</h2>
            @if ($catalogueError)
                <p role="alert" class="market-feedback is-error">{{ $catalogueError }}</p>
            @elseif ($exchanges === [])
                <p role="status" class="market-feedback">No exchanges are available yet. Please contact the site administrator to configure an exchange.</p>
            @endif
            <div class="market-row-scroll">
                <div class="market-form-row">
                    <label class="market-field">Exchange
                        <select id="market-exchange" name="exchange" required @disabled($exchanges === []) data-options-url="{{ route('markets.options', ['exchange' => '__EXCHANGE__']) }}" class="market-control">
                            <option value="">Select an exchange</option>
                            @foreach ($exchanges as $choice)
                                <option value="{{ $choice['value'] }}" @selected(old('exchange', $prefillExchange ?? '') === $choice['value'])>{{ $choice['label'] }}{{ ($choice['access'] ?? null) === 'authentication_required' ? ' (API access required)' : '' }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="market-field">Pair (base/quote)
                        <select id="market-symbol" name="symbol" required disabled data-old-symbol="{{ old('symbol', $prefillSymbol ?? '') }}" class="market-control">
                            <option value="">Select an exchange first</option>
                        </select>
                    </label>
                    <div class="market-field">
                        <span>Price tick size</span>
                        <output id="market-tick-size" for="market-symbol" class="market-output">—</output>
                    </div>
                    <button id="market-subscribe" type="submit" disabled class="market-subscribe">Subscribe</button>
                </div>
            </div>
            <h3 class="market-explain-title">What these choices mean</h3>
            <div class="market-explain">
                <div class="exchange"><strong>Exchange</strong>The trading platform that supplies price data, such as Kraken. Only exchanges with spot markets and supported candles are listed. Some require API credentials.</div>
                <div class="pair"><strong>Pair</strong>In BTC/USDT, BTC is the asset and USDT is the currency used to price it.</div>
                <div class="tick"><strong>Price tick size</strong>The smallest price step the exchange allows. A tick of 0.01 means prices move in steps of 0.01 of the second currency. It is a price amount, not a time interval.</div>
                <div class="period"><strong>Candle period</strong>The time covered by each price candle, such as 15 minutes. Trademinator selects it automatically based on candle quality and coverage. Everyone watching this exchange/pair shares the same period, shown under Your markets once selected.</div>
            </div>
            <p id="market-options-status" role="status" aria-live="polite" class="market-feedback"></p>
            @if ($errors->any())
                <div role="alert" class="market-errors"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
            @endif
        </form>

        <div class="market-list space-y-3">
            <h2>Your markets</h2>
            @if ($subscriptionGroups->isNotEmpty())
                <p class="market-meta">Select an exchange to show or hide its markets.</p>
            @endif
            @forelse ($subscriptionGroups as $group)
                <section class="market-group" aria-label="{{ $group['name'] }} markets">
                    <h3 class="market-group-heading">
                        <button type="button" class="market-group-toggle collapsed" data-bs-toggle="collapse"
                            data-bs-target="#exchange-markets-{{ $group['exchange_id'] }}"
                            aria-expanded="false" aria-controls="exchange-markets-{{ $group['exchange_id'] }}">
                        <span class="market-logo {{ $group['logo_url'] ? 'has-image' : '' }}" aria-hidden="true">
                            <span class="market-logo-initial">{{ mb_strtoupper(mb_substr($group['name'], 0, 1)) }}</span>
                            @if ($group['logo_url'])
                                <img src="{{ $group['logo_url'] }}" width="88" height="40" loading="lazy" decoding="async" referrerpolicy="no-referrer" alt="">
                            @endif
                        </span>
                        {{ $group['name'] }}
                        <span class="market-group-count">{{ $group['subscriptions']->count() }} {{ $group['subscriptions']->count() === 1 ? 'market' : 'markets' }}</span>
                        <span class="market-group-chevron" aria-hidden="true"></span>
                        </button>
                    </h3>
                    <div class="collapse" id="exchange-markets-{{ $group['exchange_id'] }}">
                    <ul class="market-group-items">
                        @foreach ($group['subscriptions'] as $subscription)
                        <li class="market-row">
                            <div>
                                <strong class="market-row-title">{{ $subscription->market->symbol }}{{ $subscription->market->feed?->selected_period ? ' · '.$subscription->market->feed->selected_period : '' }}
                                    <x-knn-readiness :report="$subscriptionTrainingReports->get($subscription->getKey())" :coingecko="$subscriptionContexts->get($subscription->getKey())" class="ml-2" />
                                </strong>
                                <p class="market-meta">
                                    <span class="market-badge {{ $subscription->active ? 'is-active' : '' }}">{{ $subscription->active ? 'Active' : 'Inactive' }}</span>
                                    Feed: {{ $subscription->market->feed->status ?? 'pending' }}{{ $subscription->market->feed?->selected_period ? '' : ' · Period: selecting' }}
                                </p>
                                <p class="market-meta">Badges: latest training readiness. Production: {{ \App\Domain\Intelligence\ModelStore::isReadyReport($subscriptionReports->get($subscription->getKey())) ? 'Available' : 'Unavailable' }}.</p>
                            </div>
                            <div class="market-actions">
                                @if ($subscription->active)
                                    <a href="{{ route('markets.intelligence', $subscription->getKey()) }}" class="market-review">Intelligence</a>
                                @endif
                                <a href="{{ route('markets.suggestions.review', ['exchange' => $subscription->market->exchange->class, 'symbol' => $subscription->market->symbol]) }}" class="market-review" aria-label="Review {{ $subscription->market->symbol }} on {{ $group['name'] }}">Review pair</a>
                                @if ($subscription->active)
                                    <form method="POST" action="{{ route('markets.destroy', $subscription->market_subscription_id) }}">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="market-unsubscribe">Unsubscribe</button>
                                    </form>
                                @endif
                            </div>
                        </li>
                        @endforeach
                    </ul>
                    </div>
                </section>
            @empty
                <p>No market subscriptions yet.</p>
            @endforelse
        </div>
    </section>

    <script>
        (() => {
            document.querySelectorAll('.market-logo img').forEach(image => {
                const showInitial = () => {
                    image.parentElement?.classList.remove('has-image');
                    image.remove();
                };
                image.addEventListener('error', showInitial);
                if (image.complete && !image.naturalWidth) showInitial();
            });
            const exchange = document.getElementById('market-exchange');
            const symbol = document.getElementById('market-symbol');
            const tickSize = document.getElementById('market-tick-size');
            const subscribe = document.getElementById('market-subscribe');
            const status = document.getElementById('market-options-status');
            const oldSymbol = symbol.dataset.oldSymbol;
            const oldExchange = exchange.value;
            let request;
            let hasPeriods = false;

            function reset(message) {
                symbol.replaceChildren(new Option(message, ''));
                symbol.disabled = true;
                tickSize.textContent = '—';
                subscribe.disabled = true;
                hasPeriods = false;
            }

            function selectedSymbol() {
                const option = symbol.selectedOptions[0];
                tickSize.textContent = option?.dataset.tickSize || '—';
                subscribe.disabled = !hasPeriods || !option?.value || !option.dataset.tickSize;
                status.classList.toggle('is-error', Boolean(option?.value && !option.dataset.tickSize));
                status.textContent = option?.value && !option.dataset.tickSize
                    ? 'This pair does not have a fixed price tick size in CCXT.' : '';
            }

            async function loadExchange() {
                request?.abort();
                reset(exchange.value ? 'Loading pairs…' : 'Select an exchange first');
                status.textContent = '';
                status.classList.remove('is-error');
                if (!exchange.value) return;

                const current = new AbortController();
                request = current;
                try {
                    const url = exchange.dataset.optionsUrl.replace('__EXCHANGE__', encodeURIComponent(exchange.value));
                    const response = await fetch(url, { headers: { Accept: 'application/json' }, signal: current.signal });
                    const data = await response.json().catch(() => null);
                    if (!response.ok || !data) {
                        if (response.status === 401 || response.status === 419) {
                            throw new Error('Your session expired. Please sign in again.');
                        }
                        const message = data?.message || 'The server could not load pairs. Please retry; if this continues, ask the administrator to check the server log and PHP memory limit.';
                        throw new Error(message + (data?.reference ? ` Reference: ${data.reference}` : ''));
                    }
                    if (!Array.isArray(data.symbols) || !Array.isArray(data.periods)) {
                        throw new Error('The server returned an invalid pair list. Please retry or contact the administrator.');
                    }
                    if (request !== current) return;
                    hasPeriods = data.periods.length > 0;
                    symbol.replaceChildren(new Option(data.symbols.length ? 'Select a pair' : 'No spot pairs available', ''));
                    for (const market of data.symbols) {
                        const option = new Option(market.value, market.value);
                        if (market.tick_size) option.dataset.tickSize = market.tick_size;
                        else option.textContent += ' (price increment unavailable)';
                        symbol.add(option);
                    }
                    symbol.disabled = !hasPeriods || data.symbols.length === 0;
                    if (exchange.value === oldExchange && [...symbol.options].some(option => option.value === oldSymbol)) {
                        symbol.value = oldSymbol;
                    }
                    selectedSymbol();
                    if (!hasPeriods) {
                        status.classList.add('is-error');
                        status.textContent = 'This exchange does not provide a supported candle period.';
                    } else if (data.symbols.length === 0) {
                        status.textContent = 'The exchange responded successfully but returned no supported spot pairs.';
                    }
                } catch (error) {
                    if (error.name === 'AbortError') return;
                    if (request === current) {
                        reset('Could not load pairs');
                        status.classList.add('is-error');
                        status.textContent = error.message;
                    }
                }
            }

            exchange.addEventListener('change', loadExchange);
            symbol.addEventListener('change', selectedSymbol);
            if (exchange.value) loadExchange();
        })();
    </script>
</x-layouts.app>
