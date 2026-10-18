# Trademinator CLI reference

Canonical operator reference for all **44 `trademinator:*` commands**, the local `inspire` command, and the committed metadata utility. Common Laravel operating commands are listed separately; package-provided commands vary with installed dependencies, so `php artisan list` remains the complete runtime inventory.

Command signatures, descriptions and behavior were checked against repository source at `c0ce12f87ec570783381fd7854f03905ae2bc5bd`. Update this file whenever a command or its parameters change. `tests/Feature/TrademinatorCliDocumentationTest.php` checks the registered project-command inventory, exact normalized signatures and descriptions.

## Getting started

Run from the directory containing `artisan`, with the application's PHP, environment, database/cache access and Composer dependencies. Examples use illustrative markets; use an exchange, pair and selected period actually configured on your server. `DATASET_UUID`, `MODEL_UUID`, email addresses and private paths are placeholders, not working credentials or IDs.

```bash
php artisan list
php artisan list trademinator
php artisan help trademinator:sync-ohlcv
php artisan trademinator:knn-build --help
```

A dispatcher queues work; it does not wait for that work to finish. A successful process exit can mean “nothing due”, “disabled” or “model abstaining”, not necessarily new data or a validated model. Check the command's output and saved status.

### Signature notation and shared parameters

Signatures below are Laravel declarations, not text to paste literally into the shell. `{name}` is required, `{name?}` is optional, `{--flag}` is boolean, `{--name=value}` declares a default, and `{--name=*}` is repeatable. A description following ` : ` is help text. Supply an option value as `--name=value`; do not include the braces.

| Parameter | Meaning |
| --- | --- |
| `exchange` | Configured CCXT ID, such as `bitso`; not the exchange UUID. |
| `symbol` / pair | Exact exchange symbol, such as `'ATOM/USD'`; preserve case. |
| `period` | Supported timeframe; `1m` is one minute and `1M` is one month. |
| Tick size | Actual positive exchange minimum price increment, not the candle period or a desired rounding precision. |
| Basis points | 1 bps = 0.01%; 100 bps = 1%. Research examples do not prescribe production exchange fees. |

### Dates and timestamps

| Command family | Accepted form |
| --- | --- |
| `fetch-ohlcv`, `sync-ohlcv`, `select-candle-period`, `create-indicators` | Legacy date parsing. Quote relative values such as `'7 days ago'`; for reproducible runs use explicit timezone-qualified dates such as `'2024-02-28T20:45:00Z'`. |
| `build-dataset`, `knn-build`, `derive-timeframe` | UTC `YYYY-MM-DD`, `YYYY-MM-DDTHH:MM:SSZ`, or nonnegative Unix milliseconds. Relative/local-date strings are rejected. |
| `archive-restore --from/--to` | Numeric Unix milliseconds only; both bounds are inclusive. |
| `archive-tickers month` | UTC calendar month, `YYYY-MM`. |

Database candle timestamps are milliseconds. Do not assume that every command accepts raw seconds/milliseconds merely because the database stores them.

### Common Artisan options

These are framework options, not repeated in every command signature. Runtime `--help` is authoritative for the installed version.

| Option | Meaning |
| --- | --- |
| `-h`, `--help` | Show help without running the command's operation. |
| `-n`, `--no-interaction` | Disable prompts; does not bypass required arguments or authorize deletion. |
| `-v`, `-vv`, `-vvv` | Increase console verbosity; not a substitute for a command-specific `--debug`. |
| `-q`, `--quiet` | Suppress ordinary console output. |
| `--ansi`, `--no-ansi` | Force/disable ANSI formatting. |
| `--env=NAME` | Select Laravel's environment handling; not a database-isolation guarantee. |

Keep credentials out of command-line arguments, shell history and shared diagnostic output. Test commands against an isolated test database, never by resetting production data.

## Command index

Each link contains the exact signature, parameter meanings, execution/side-effect notes and examples.

| Area | Command |
| --- | --- |
| Exchanges and subscriptions | [`trademinator:exchange`](#trademinatorexchange) |
| Exchanges and subscriptions | [`trademinator:refresh-exchanges`](#trademinatorrefresh-exchanges) |
| Exchanges and subscriptions | [`trademinator:market-subscription`](#trademinatormarket-subscription) |
| Candles and history | [`trademinator:dispatch-market-feeds`](#trademinatordispatch-market-feeds) |
| Candles and history | [`trademinator:fetch-ohlcv`](#trademinatorfetch-ohlcv) |
| Candles and history | [`trademinator:sync-ohlcv`](#trademinatorsync-ohlcv) |
| Candles and history | [`trademinator:backfill-ohlcv`](#trademinatorbackfill-ohlcv) |
| Candles and history | [`trademinator:candle-gaps`](#trademinatorcandle-gaps) |
| Candles and history | [`trademinator:select-candle-period`](#trademinatorselect-candle-period) |
| Candles and history | [`trademinator:evaluate-candle-period`](#trademinatorevaluate-candle-period) |
| Features and external context | [`trademinator:build-features`](#trademinatorbuild-features) |
| Features and external context | [`trademinator:create-indicators`](#trademinatorcreate-indicators) |
| Features and external context | [`trademinator:dispatch-market-features`](#trademinatordispatch-market-features) |
| Features and external context | [`trademinator:derive-timeframe`](#trademinatorderive-timeframe) |
| Features and external context | [`trademinator:collect-market-context`](#trademinatorcollect-market-context) |
| Features and external context | [`trademinator:fetch-market-context`](#trademinatorfetch-market-context) |
| Features and external context | [`trademinator:collect-market-events`](#trademinatorcollect-market-events) |
| Features and external context | [`trademinator:refresh-market-discovery`](#trademinatorrefresh-market-discovery) |
| Research datasets and backtests | [`trademinator:build-dataset`](#trademinatorbuild-dataset) |
| Research datasets and backtests | [`trademinator:dataset-info`](#trademinatordataset-info) |
| Research datasets and backtests | [`trademinator:backtest`](#trademinatorbacktest) |
| Models and signals | [`trademinator:knn-build`](#trademinatorknn-build) |
| Models and signals | [`trademinator:compact-model`](#trademinatorcompact-model) |
| Models and signals | [`trademinator:dispatch-market-intelligence`](#trademinatordispatch-market-intelligence) |
| Models and signals | [`trademinator:auto-label`](#trademinatorauto-label) |
| Models and signals | [`trademinator:dispatch-lead-lag`](#trademinatordispatch-lead-lag) |
| Models and signals | [`trademinator:signal`](#trademinatorsignal) |
| Models and signals | [`trademinator:dispatch-market-signals`](#trademinatordispatch-market-signals) |
| Models and signals | [`trademinator:model-info`](#trademinatormodel-info) |
| Models and signals | [`trademinator:analyze-validation-gates`](#trademinatoranalyze-validation-gates) |
| Human training | [`trademinator:human-candle-audit`](#trademinatorhuman-candle-audit) |
| Human training | [`trademinator:human-training-export`](#trademinatorhuman-training-export) |
| Archives and portable data | [`trademinator:archive-tickers`](#trademinatorarchive-tickers) |
| Archives and portable data | [`trademinator:archive-eligible-tickers`](#trademinatorarchive-eligible-tickers) |
| Archives and portable data | [`trademinator:archive-verify`](#trademinatorarchive-verify) |
| Archives and portable data | [`trademinator:archive-restore`](#trademinatorarchive-restore) |
| Archives and portable data | [`trademinator:archive-rebuild-catalog`](#trademinatorarchive-rebuild-catalog) |
| Archives and portable data | [`trademinator:portable-export`](#trademinatorportable-export) |
| Archives and portable data | [`trademinator:portable-import`](#trademinatorportable-import) |
| Archives and portable data | [`trademinator:prune-portable-archives`](#trademinatorprune-portable-archives) |
| Administration | [`trademinator:mailgun-check`](#trademinatormailgun-check) |
| Administration | [`trademinator:prune-access-statistics`](#trademinatorprune-access-statistics) |

## trademinator:exchange

Signature: `trademinator:exchange {action : add, edit, delete, or list} {class? : The CCXT exchange ID, such as kraken} {--name= : Exchange display name for add or edit} {--config= : CCXT settings as a JSON object} {--config-file= : Path to a file containing a CCXT JSON object} {--timezone= : Explicit IANA timezone for intelligence session context} {--search= : Filter the list by CCXT ID or name} {--force : Skip the deletion confirmation}`

Description: Add, edit, list, or delete configured CCXT exchanges

Manage exchange configuration immediately. Listing is read-only. Add requires an adapter installed in CCXT; edit requires at least one setting. Configuration JSON replaces the stored settings object, rather than merging individual keys. Use a protected configuration file for credentials, not shell-history arguments.

**Deletion warning:** active subscriptions block deletion, even with `--force`. Deleting an exchange removes its configuration, markets, inactive subscriptions and feeds; historical candles remain.

| Parameter | Default / requirement | Explanation |
| --- | --- | --- |
| `action` | Required | One of `add`, `edit`, `delete`, `list`. |
| `class` | Required except for list | CCXT ID. Omit for `list`; use `--search` instead. |
| `--name` | CCXT ID on add; unchanged on edit | Display name, 1–64 characters. |
| `--config` | `{}` on add; unchanged on edit | CCXT settings as a JSON object; mutually exclusive with `--config-file`. |
| `--config-file` | Unset | Readable JSON-object file, at most 64 KiB; mutually exclusive with `--config`. |
| `--timezone` | Unset | Valid IANA timezone for `add`/`edit`, such as `America/Toronto`; an explicit override is retained across refreshes. |
| `--search` | No filter | Match CCXT ID or display name when listing. |
| `--force` | Off | Skip only the deletion confirmation; does not bypass active-subscription protection. |

```bash
php artisan trademinator:exchange list --search=kraken
php artisan trademinator:exchange add kraken --name=Kraken
php artisan trademinator:exchange edit kraken --config-file=/secure/kraken.json
# Destructive: prompts before deleting an unsubscribed exchange.
php artisan trademinator:exchange delete kraken
```

Implementation: [`ManageExchanges.php`](../app/Console/Commands/ManageExchanges.php).

## trademinator:refresh-exchanges

Signature: `trademinator:refresh-exchanges {--check : Inspect without changing metadata or exchange rows} {--json : Print the complete inspection report as JSON}`

Description: Refresh CCXT access classifications and add missing exchange entries without deleting data

Inspect installed CCXT adapters offline. Normal mode updates the local runtime metadata, adds missing exchange rows and seeds timezone metadata while preserving existing configuration and user data. It neither upgrades CCXT nor automatically approves adapters that require review. Runs immediately, with local/shared locking; it does not queue work.

| Parameter | Default / requirement | Explanation |
| --- | --- | --- |
| `--check` | Off | Inspect/report only; do not replace metadata or modify exchange rows. A local lock is still acquired. |
| `--json` | Off | Print the complete inspection report instead of the summary tables. |

```bash
php artisan trademinator:refresh-exchanges --check --json
php artisan trademinator:refresh-exchanges
```

Implementation: [`RefreshExchanges.php`](../app/Console/Commands/RefreshExchanges.php).

## trademinator:market-subscription

Signature: `trademinator:market-subscription {action : subscribe, unsubscribe, or list} {user : User email or UUID} {exchange? : CCXT exchange ID} {symbol? : Market symbol, e.g. BTC/USD} {--tick-size= : Minimum price increment from the exchange market metadata}`

Description: Manage user subscriptions that drive shared market collection

List or change the named user's collection subscriptions. The user and exchange must exist. Subscribe/reactivate requires the actual market tick size and usable exchange access/credentials. Unsubscribe marks the subscription inactive; it does not erase candles or stop a shared feed still needed by other subscribers. This is not billing administration.

The tick size below is illustrative: replace it with the exchange's current metadata value.

| Parameter | Default / requirement | Explanation |
| --- | --- | --- |
| `action` | Required | `subscribe`, `unsubscribe`, or `list`. |
| `user` | Required | Existing user email address or user UUID. |
| `exchange` | Required for subscribe/unsubscribe | Configured CCXT exchange ID. Omit for `list`. |
| `symbol` | Required for subscribe/unsubscribe | Exact exchange pair. Omit for `list`. |
| `--tick-size` | Required for subscribe | Positive minimum price increment, including when reactivating an existing market subscription. |

```bash
php artisan trademinator:market-subscription list user@example.com
php artisan trademinator:market-subscription subscribe user@example.com kraken 'BTC/USD' --tick-size=0.01
php artisan trademinator:market-subscription unsubscribe user@example.com kraken 'BTC/USD'
```

Implementation: [`ManageMarketSubscriptions.php`](../app/Console/Commands/ManageMarketSubscriptions.php).

## trademinator:dispatch-market-feeds

Signature: `trademinator:dispatch-market-feeds {--limit=100}`

Description: Queue due market feeds with at least one active subscription

Queue due shared collection work; it does not wait for candles to arrive. Requires a persistent queue and active subscriptions. It selects due feeds, not every configured exchange. Run the appropriate queue worker after dispatching.

| Parameter | Default / requirement | Explanation |
| --- | --- | --- |
| `--limit` | 100 | Maximum due feeds to dispatch in this invocation; integer 1–1000. |

```bash
php artisan trademinator:dispatch-market-feeds
php artisan trademinator:dispatch-market-feeds --limit=25
```

Implementation: [`DispatchMarketFeeds.php`](../app/Console/Commands/DispatchMarketFeeds.php).

## trademinator:fetch-ohlcv

Signature: `trademinator:fetch-ohlcv {exchange} {symbol} {period} {from?} {to?} {--debug}`

Description: Fetch OHLCV information from a specific Exchange-Symbol-Period from a specific time frame

Fetch, **persist**, and print candles immediately through the exchange repository. This is not a read-only preview. Prefer `sync-ohlcv` for bounded pagination, incremental updates or gap repairs. The exchange must be configured and support the requested symbol/timeframe.

The legacy mixed-case name `trademinator:FetchOHLCV` is not registered.

| Parameter | Default / requirement | Explanation |
| --- | --- | --- |
| `exchange` | Required | Configured CCXT exchange ID, for example `bitso` or `kraken`; not an exchange UUID. |
| `symbol` | Required | Exact exchange pair, for example `ATOM/USD`. Preserve its case and quote it in the shell. |
| `period` | Required | Supported candle timeframe, for example `15m` or `1h`. `1m` and `1M` are different. |
| `from` | `yesterday` | Optional positional fetch start; legacy date parsing. |
| `to` | `now` | Optional positional fetch end; legacy date parsing. |
| `--debug` | Off | Request verbose CCXT diagnostics; authenticated request details are suppressed when credentials are in use. |
| `--isolated[=EXIT_CODE]` | Off | Laravel-added option: acquire a command-wide cache lock. On contention, exit 0 by default or the supplied code; this is not a per-market lock. |

```bash
php artisan trademinator:fetch-ohlcv bitso 'ATOM/USD' 15m 'yesterday' now
php artisan trademinator:fetch-ohlcv bitso 'ATOM/USD' 15m 'yesterday' now --isolated=1
```

Implementation: [`FetchOHLCV.php`](../app/Console/Commands/FetchOHLCV.php).

## trademinator:sync-ohlcv

Signature: `trademinator:sync-ohlcv {exchange} {symbol} {period} {--from=7 days ago} {--to=now} {--incremental} {--repair-gaps} {--queue} {--page-size=90}`

Description: Fetch and upsert exchange candles, optionally inspect and repair missing ranges

Fetch and save candles in bounded overlapping pages. Runs immediately unless `--queue` is supplied; queued pages chain the next page after success. Direct output includes `pages`, `fetched`, `repaired`, `reconstructed` and `missing_ranges`. Fetched totals may include already stored candles; database uniqueness prevents duplicate rows.

Repair can reconstruct isolated missing candles using smaller-timeframe/parent evidence, or the explicit inferred next-open policy after successful empty lower-timeframe requests. Provenance and evidence-availability times are retained; failed requests are not evidence for filling a gap. Repairs may trigger the existing downstream rebuild pipeline. Recheck the whole feed with `candle-gaps`. This command has no `--dry-run` option.

| Parameter | Default / requirement | Explanation |
| --- | --- | --- |
| `exchange` | Required | Configured CCXT exchange ID, for example `bitso` or `kraken`; not an exchange UUID. |
| `symbol` | Required | Exact exchange pair, for example `ATOM/USD`. Preserve its case and quote it in the shell. |
| `period` | Required | Supported candle timeframe, for example `15m` or `1h`. `1m` and `1M` are different. |
| `--from` | `7 days ago` | Requested fetch start, using legacy date parsing. Initial start must precede end. |
| `--to` | `now` | Requested fetch end. Downstream features use only completed candles. |
| `--incremental` | Off | Move start forward to the later of `--from` and the latest stored candle, refreshing that candle too. |
| `--repair-gaps` | Off | Retry missing intervals and apply the supported isolated-gap reconstruction policy. |
| `--queue` | Off | Queue sequential page jobs; rejects `sync`/`null` queue connections. |
| `--page-size` | 90 | Candles per request/page; integer 10–100. |

```bash
php artisan trademinator:sync-ohlcv bitso 'ATOM/USD' 15m --from='7 days ago' --incremental
php artisan trademinator:sync-ohlcv bitso 'ATOM/USD' 15m --from='2024-02-28T20:45:00Z' --to='2024-02-28T21:15:00Z' --repair-gaps
php artisan trademinator:sync-ohlcv bitso 'ATOM/USD' 15m --from='30 days ago' --queue --page-size=90
```

Implementation: [`SyncOHLCV.php`](../app/Console/Commands/SyncOHLCV.php).

## trademinator:backfill-ohlcv

Signature: `trademinator:backfill-ohlcv {--exchange=} {--symbol=} {--period=} {--status} {--resume}`

Description: Queue resumable older OHLCV history for subscribed markets, or inspect and resume paused backfills

Queue resumable older-history passes for active shared feeds. Normal mode also scans/queues closed-candle gap repairs and recovers pending feature/KNN rebuild steps. It uses the configured history and intelligence queues, not an all-history synchronous download. Filters force a fresh gap scan of matching feeds.

`--status` only reads saved state and does not initialize or queue work. Dispatch requires enabled historical backfill and a persistent queue. `--resume` preserves candles and the cursor; it does not reset a live lease. Resume only a market's currently selected period. An empty historical response does not prove the coin's listing date.

| Parameter | Default / requirement | Explanation |
| --- | --- | --- |
| `--exchange` | All matching exchanges | Filter by configured CCXT exchange ID. |
| `--symbol` | All matching pairs | Filter by the exact exchange symbol, for example `ATOM/USD`. |
| `--period` | All matching selected periods | Filter by the shared feed's selected, case-sensitive candle timeframe. |
| `--status` | Off | Print saved progress/reasons/revisions as JSON without dispatching work. Available even when backfill is disabled. |
| `--resume` | Off | Resume a paused cursor. Requires all three filters and cannot be combined with `--status`. |

```bash
php artisan trademinator:backfill-ohlcv
php artisan trademinator:backfill-ohlcv --exchange=bitso --symbol='ATOM/USD' --period=15m --status
php artisan trademinator:backfill-ohlcv --exchange=bitso --symbol='ATOM/USD' --period=15m --resume
```

Implementation: [`BackfillOHLCV.php`](../app/Console/Commands/BackfillOHLCV.php).

## trademinator:candle-gaps

Signature: `trademinator:candle-gaps {--exchange=} {--symbol=} {--period=} {--no-scan}`

Description: Scan and report missing closed candles for active subscribed market feeds with suggested fixes

Scan active subscribed feeds and print missing ranges plus copyable `sync-ohlcv` repair commands. A normal scan updates existing gap-tracking records, but does not contact exchanges or queue repairs. The currently open candle is excluded. Adjacent ranges are combined; a one-candle hole gets one neighbouring candle on each side in its suggested repair interval.

With `--no-scan`, the report is only saved gap state: “NO TRACKED GAPS” is not proof that history is complete.

| Parameter | Default / requirement | Explanation |
| --- | --- | --- |
| `--exchange` | All matching exchanges | Filter by configured CCXT exchange ID. |
| `--symbol` | All matching pairs | Filter by the exact exchange symbol, for example `ATOM/USD`. |
| `--period` | All matching selected periods | Filter by the shared feed's selected, case-sensitive candle timeframe. |
| `--no-scan` | Off | Read previously tracked gaps without performing a fresh scan. |

```bash
php artisan trademinator:candle-gaps
php artisan trademinator:candle-gaps --exchange=bitso --symbol='ATOM/USD' --period=15m
php artisan trademinator:candle-gaps --exchange=bitso --no-scan
```

Implementation: [`CandleGaps.php`](../app/Console/Commands/CandleGaps.php).

## trademinator:select-candle-period

Signature: `trademinator:select-candle-period {exchange} {symbol} {--periods=1m,3m,5m,15m,30m,1h,4h,1d} {--tick-size=} {--threshold=0.7} {--coverage=0.8} {--minimum=50} {--sample=250} {--from=7 days ago} {--to=now}`

Description: Select the shortest sufficiently informative candle period

Fetch bounded recent samples and select the shortest candidate meeting information-quality, coverage and sample-size requirements. Saves fetched candles and a successful selection record, then prints JSON. **This diagnostic command does not change the shared feed's selected period.** Use `evaluate-candle-period` to reevaluate/update automatic shared-feed selection.

The example tick size is illustrative; use the actual exchange metadata value.

| Parameter | Default / requirement | Explanation |
| --- | --- | --- |
| `exchange` | Required | Configured CCXT exchange ID, for example `bitso` or `kraken`; not an exchange UUID. |
| `symbol` | Required | Exact exchange pair, for example `ATOM/USD`. Preserve its case and quote it in the shell. |
| `--periods` | `1m,3m,5m,15m,30m,1h,4h,1d` | Comma-separated candidate periods supported by the exchange and application. |
| `--tick-size` | Required | Positive minimum exchange price increment. |
| `--threshold` | 0.7 | Minimum information-quality score, 0–1. |
| `--coverage` | 0.8 | Minimum candle coverage, 0–1. |
| `--minimum` | 50 | Minimum completed sample count; integer at least 1. |
| `--sample` | 250 | Maximum recent sample count; integer at least `--minimum`. |
| `--from` | `7 days ago` | Start of the candidate fetch interval; legacy date parsing. |
| `--to` | `now` | End of the interval; only completed samples qualify. |

```bash
php artisan trademinator:select-candle-period kraken 'BTC/USD' --tick-size=0.01
php artisan trademinator:select-candle-period kraken 'BTC/USD' --tick-size=0.01 --periods=5m,15m,1h --sample=250
```

Implementation: [`SelectCandlePeriod.php`](../app/Console/Commands/SelectCandlePeriod.php).

## trademinator:evaluate-candle-period

Signature: `trademinator:evaluate-candle-period {--exchange=} {--pair=} {--dry-run} {--outdated-only}`

Description: Evaluate and optionally update automatic candle periods for subscribed markets

Reevaluate active subscribed feeds using the automatic selection policy, including fee-aware label viability and available history. Runs immediately and reports current/result periods, BUY/SELL ratios, status and reason. Normal mode may change a selected period or request more history; it keeps a current period until a replacement qualifies. Unlike gap/backfill commands, the pair filter is **`--pair`**, not `--symbol`.

The hard true-flat cap (`CANDLE_PERIOD_MAX_TRUE_FLAT_RATIO`, default 10%) rejects O=H=L=C-heavy periods regardless of their composite quality score, including when the seven-day window is followed by a longer fallback. The existing BUY and SELL action-ratio floor is independent; keep `CANDLE_PERIOD_MINIMUM_ACTION_RATIO=0.05` if that is the operational target.

Moving to a **shorter** period is intentionally harder: it must satisfy `CANDLE_PERIOD_SHORTER_REENTRY_FLAT_RATIO` (default 5%) and all existing quality and fee-aware BUY/SELL gates in `CANDLE_PERIOD_SHORTER_CONFIRMATION_WINDOWS` (default 2) separate, non-overlapping historical windows. Each window is at least `CANDLE_PERIOD_EVALUATION_DAYS` (default 7) and long enough to provide `CANDLE_PERIOD_MINIMUM_CANDLES`. Repeating the same evaluation does not create a new confirmation. When history is missing, the existing backfill queue is used; no new queue is introduced.

Dry run still performs evaluation and can contact exchanges; it does not change a selected period or queue history backfill. It now shows true-flat percentages and limits for each attempted timeframe/window, with specific rejection statuses such as `flat_failed`.

| Parameter | Default / requirement | Explanation |
| --- | --- | --- |
| `--exchange` | All matching exchanges | Filter by configured CCXT exchange ID. |
| `--pair` | All matching pairs | Exact exchange pair, such as `ATOM/USD`. |
| `--dry-run` | Off | Evaluate/report without changing the period or queuing history backfill. |
| `--outdated-only` | Off | Only due feeds with no selection or an older selection version; use the configured scheduled batch limit (default 10). |

```bash
php -d memory_limit=512M artisan trademinator:evaluate-candle-period --exchange=bitso --pair='ATOM/USD' --dry-run
php -d memory_limit=512M artisan trademinator:evaluate-candle-period --exchange=bitso --pair='ATOM/USD'
php artisan trademinator:evaluate-candle-period --outdated-only
```

Implementation: [`EvaluateCandlePeriod.php`](../app/Console/Commands/EvaluateCandlePeriod.php).

## trademinator:build-features

Signature: `trademinator:build-features {exchange} {symbol} {period}`

Description: Replay stored completed candles into versioned, causal M2 features

Replay stored closed candles into `market_features` immediately. Does not fetch candles or train a model. Missing context and indicator warm-up stay missing; gaps restart warm-up. A long direct replay is still subject to its cooperative time limit. For routine queued catch-up, use `dispatch-market-features` and drain the `features` queue.

| Parameter | Default / requirement | Explanation |
| --- | --- | --- |
| `exchange` | Required | Configured CCXT exchange ID, for example `bitso` or `kraken`; not an exchange UUID. |
| `symbol` | Required | Exact exchange pair, for example `ATOM/USD`. Preserve its case and quote it in the shell. |
| `period` | Required | Supported candle timeframe, for example `15m` or `1h`. `1m` and `1M` are different. |

```bash
php artisan trademinator:build-features bitso 'ATOM/USD' 15m
```

Implementation: [`BuildMarketFeatures.php`](../app/Console/Commands/BuildMarketFeatures.php).

## trademinator:create-indicators

Signature: `trademinator:create-indicators {exchange} {symbol} {period} {from?} {to?} {--debug}`

Description: Build M2 indicators and feature vectors from stored completed candles

Compatibility entry point for the same M2 feature builder, with optional positional time bounds. Writes features, not source candles. Earlier history can still seed indicators before the requested output start. The legacy mixed-case `trademinator:CreateIndicators` is not registered.

| Parameter | Default / requirement | Explanation |
| --- | --- | --- |
| `exchange` | Required | Configured CCXT exchange ID, for example `bitso` or `kraken`; not an exchange UUID. |
| `symbol` | Required | Exact exchange pair, for example `ATOM/USD`. Preserve its case and quote it in the shell. |
| `period` | Required | Supported candle timeframe, for example `15m` or `1h`. `1m` and `1M` are different. |
| `from` | All stored history | Optional lower candle-opening bound for feature output; legacy date parsing. |
| `to` | Now | Optional completion cutoff, capped at now; legacy date parsing. |
| `--debug` | Off | Retained for compatibility; currently has no effect in this command. |

```bash
php artisan trademinator:create-indicators bitso 'ATOM/USD' 15m '7 days ago' now
```

Implementation: [`CreateIndicators.php`](../app/Console/Commands/CreateIndicators.php).

## trademinator:dispatch-market-features

Signature: `trademinator:dispatch-market-features`

Description: Queue M2 feature builds for subscribed markets whose current feature version is behind

Queue catch-up only for active subscribed feeds with a selected period and source candles newer than the latest current-version feature. Requires `FEATURES_ENABLED=true`. Jobs use `FEATURES_QUEUE` (default **`features`**) and bounded replay continuations; a worker listening only to `default` will not drain that separate queue.

Parameters: none beyond standard Artisan options.

```bash
php artisan trademinator:dispatch-market-features
```

Implementation: [`DispatchMarketFeatures.php`](../app/Console/Commands/DispatchMarketFeatures.php).

## trademinator:derive-timeframe

Signature: `trademinator:derive-timeframe {exchange} {symbol} {base} {period} {--from=} {--as-of=}`

Description: Derive complete larger candles from the selected base period and reuse the M2 feature pipeline

Build larger closed candles from stored selected-base history and reuse the feature pipeline. Runs immediately; it does not fetch missing source candles. Only complete, compatible larger intervals can be derived. This does not switch the feed's selected period or automatically train every derived timeframe.

| Parameter | Default / requirement | Explanation |
| --- | --- | --- |
| `exchange` | Required | Configured CCXT exchange ID, for example `bitso` or `kraken`; not an exchange UUID. |
| `symbol` | Required | Exact exchange pair, for example `ATOM/USD`. Preserve its case and quote it in the shell. |
| `base` | Required | Stored selected source timeframe, for example `15m`. |
| `period` | Required | Compatible larger target timeframe, for example `1h`. |
| `--from` | Unset | Optional lower history bound; research UTC date/millisecond format. |
| `--as-of` | Current cutoff | Latest observable completion time; research UTC date/millisecond format. |

```bash
php artisan trademinator:derive-timeframe bitso 'ATOM/USD' 15m 1h
```

Implementation: [`DeriveMarketHistory.php`](../app/Console/Commands/DeriveMarketHistory.php).

## trademinator:collect-market-context

Signature: `trademinator:collect-market-context`

Description: Collect timestamped CoinGecko context for subscribed markets

Collect and store CoinGecko snapshots for active subscriptions in the current process. Uses the shared context lock, resolves eligible pending mappings and deduplicates observations by UTC hour. Requires enabled/configured CoinGecko access. Ambiguous mappings need resolution; fresh snapshots cannot recreate missing historical context. No worker is required for this command itself.

Parameters: none beyond standard Artisan options.

```bash
php artisan trademinator:collect-market-context
```

Implementation: [`CollectMarketContext.php`](../app/Console/Commands/CollectMarketContext.php).

## trademinator:fetch-market-context

Signature: `trademinator:fetch-market-context {--coin=} {--vs-currency=} {--exchange=} {--symbol=}`

Description: Fetch fresh CoinGecko context immediately for one mapped coin/quote or exchange/pair

Fetch and save fresh context immediately for one already resolved mapping. Supply **exactly one complete selector**: coin plus quote, or exchange plus symbol. Mixed/incomplete selectors fail. An active subscription is not required for this manual fetch. No jobs are queued and no `--force` option is needed. It does not rebuild features or models.

| Parameter | Default / requirement | Explanation |
| --- | --- | --- |
| `--coin` | Unset | CoinGecko coin ID, not ticker; requires `--vs-currency`. |
| `--vs-currency` | Unset | Mapped quote currency such as `usd`; requires `--coin`. |
| `--exchange` | Unset | Configured CCXT exchange ID; requires `--symbol`. |
| `--symbol` | Unset | Mapped exchange pair; requires `--exchange`. |

```bash
php artisan trademinator:fetch-market-context --exchange=bitso --symbol='ATOM/USD'
php artisan trademinator:fetch-market-context --coin=cosmos --vs-currency=usd
```

Implementation: [`FetchMarketContext.php`](../app/Console/Commands/FetchMarketContext.php).

## trademinator:collect-market-events

Signature: `trademinator:collect-market-events {--force : Reprocess the latest GDELT GKG batch}`

Description: Discover GDELT GKG fork-related event candidates for owner review

Collect GDELT GKG fork-related candidates immediately for owner review. Requires enabled GDELT configuration; otherwise exits without collection. Uses a shared lock. A candidate is not an automatically confirmed event or an order instruction.

| Parameter | Default / requirement | Explanation |
| --- | --- | --- |
| `--force` | Off | Reprocess the latest GKG batch even if already seen; does not bypass the enabled setting or lock. |

```bash
php artisan trademinator:collect-market-events
php artisan trademinator:collect-market-events --force
```

Implementation: [`CollectGdeltMarketEvents.php`](../app/Console/Commands/CollectGdeltMarketEvents.php).

## trademinator:refresh-market-discovery

Signature: `trademinator:refresh-market-discovery`

Description: Refresh bounded CoinGecko discovery and market conditions without subscribing or trading

Refresh optional market-discovery context immediately under a shared lock. Updates discovery data, not user subscriptions, and never executes trades. Uses configured provider access; an already-running refresh is reported without starting another.

Parameters: none beyond standard Artisan options.

```bash
php artisan trademinator:refresh-market-discovery
```

Implementation: [`RefreshMarketDiscovery.php`](../app/Console/Commands/RefreshMarketDiscovery.php).

## trademinator:build-dataset

Signature: `trademinator:build-dataset {exchange} {symbol} {period} {--schema=core} {--features=} {--horizon=12} {--fee-bps=10} {--slippage-bps=5} {--min-return-bps=10} {--from=} {--to=} {--as-of=}`

Description: Freeze M2 features and fee-aware future labels into an immutable M3 dataset

Create a new immutable **fee-aware research** snapshot from existing features and closed candles. Runs immediately and prints a manifest with `dataset_id`. It neither fetches market data nor trains KNN. Missing selected inputs exclude a row rather than becoming zero. Repeat runs create additional snapshots.

Basis-point inputs must be nonnegative and below 10,000. The example values are research assumptions, not a claim about the named exchange's current fees. These M3 labels are distinct from the semantic datasets used by `knn-build`.

| Parameter | Default / requirement | Explanation |
| --- | --- | --- |
| `exchange` | Required | Configured CCXT exchange ID, for example `bitso` or `kraken`; not an exchange UUID. |
| `symbol` | Required | Exact exchange pair, for example `ATOM/USD`. Preserve its case and quote it in the shell. |
| `period` | Required | Supported candle timeframe, for example `15m` or `1h`. `1m` and `1M` are different. |
| `--schema` | `core` | `core`, `technical`, `full`, or `custom`; see Choosing a feature schema. |
| `--features` | Unset | Ordered comma-separated known M2 keys. Required for `custom`, rejected for other schemas; no duplicate keys. |
| `--horizon` | 12 | Future candle count, integer 1–10,000. Research entry is next open and exit is the horizon-th subsequent close. |
| `--fee-bps` | 10 | Assumed fee per side in basis points; 10 bps = 0.10%. |
| `--slippage-bps` | 5 | Assumed adverse slippage per side; 5 bps = 0.05%. |
| `--min-return-bps` | 10 | Strict net-return threshold beyond costs for directional labels; equality is HODL. |
| `--from` | Earliest eligible feature | Inclusive decision-time lower bound; research UTC date/millisecond format. |
| `--to` | As-of cutoff | Inclusive decision-time upper bound. Outcomes can mature later, up to `--as-of`. |
| `--as-of` | Now | Latest time label outcomes may be known, capped at now. Does not recreate old provider snapshots. |

```bash
php artisan trademinator:build-dataset bitso 'ATOM/USD' 15m --schema=core --horizon=12 --fee-bps=10 --slippage-bps=5
php artisan trademinator:build-dataset bitso 'ATOM/USD' 15m --schema=custom --features=trend.direction,momentum.rsi_14 --horizon=6
```

Implementation: [`BuildResearchDataset.php`](../app/Console/Commands/BuildResearchDataset.php).

## trademinator:dataset-info

Signature: `trademinator:dataset-info {dataset}`

Description: Verify an M3 dataset checksum and display its frozen manifest

Read and verify a stored dataset and print its manifest as JSON. Needs both its database record and private files. A missing/corrupt snapshot fails; no new dataset is created.

| Parameter | Default / requirement | Explanation |
| --- | --- | --- |
| `dataset` | Required | Dataset UUID printed by a successful build, not a filename. Replace `DATASET_UUID` with that real ID. |

```bash
php artisan trademinator:dataset-info DATASET_UUID
```

Implementation: [`InspectResearchDataset.php`](../app/Console/Commands/InspectResearchDataset.php).

## trademinator:backtest

Signature: `trademinator:backtest {dataset} {--strategy=majority} {--train=500} {--test=100} {--gap=0} {--rolling}`

Description: Run purged walk-forward baseline evaluation on a frozen M3 dataset

Evaluate a frozen fee-aware M3 dataset using chronological, outcome-purged folds. Save a new research backtest record/report and print its ID/path/summary. This is not live trading or a KNN training command. Cost-free M4 semantic datasets are rejected. Enough mature rows must remain after purging.

| Parameter | Default / requirement | Explanation |
| --- | --- | --- |
| `dataset` | Required | Verified fee-aware research dataset UUID; replace `DATASET_UUID`. |
| `--strategy` | `majority` | `majority`: most frequent training label; `trend`: sign of `trend.direction` (must be in schema); `buy`: always BUY; `hodl`: always HODL. |
| `--train` | 500 | Minimum eligible training rows, integer 1–50,000; fixed window size in rolling mode. |
| `--test` | 100 | Test-block row count, integer 1–50,000. |
| `--gap` | 0 | Additional excluded dataset rows before each test block, integer 0–50,000. Outcome purging still applies. |
| `--rolling` | Off | Use a fixed-length training window instead of expanding history. |

```bash
php artisan trademinator:backtest DATASET_UUID --train=500 --test=100
php artisan trademinator:backtest DATASET_UUID --strategy=trend --train=500 --test=100 --gap=12 --rolling
```

Implementation: [`BacktestResearchDataset.php`](../app/Console/Commands/BacktestResearchDataset.php).

## trademinator:outcome-audit

Signature: `trademinator:outcome-audit {exchange} {symbol} {period} {--dataset= : Current semantic dataset; defaults to the published model dataset} {--k= : Fixed K for all research comparisons; defaults to the published Outcome K} {--timeout=3600 : Read-only research budget in seconds}`

Description: Read-only Outcome KNN research across horizons and feature groups with final holdout reserved

Read-only Outcome KNN research command. It never publishes a model, moves an intelligence head, changes readiness, or evaluates the final reserved holdout. The command uses one fixed K across comparisons so horizon and feature-group diagnostics do not silently retune K on the same research data.

The report reserves the final 20% of the frozen dataset by decision time and reports it as `final_holdout.evaluated=false`. Research comparisons use only earlier rows whose alternative Outcome labels are available before that cutoff.

The horizon sweep evaluates approximately `H/2`, `H`, `1.5H`, and `2H`. On technical/full source datasets it uses the Technical feature group for all horizons; otherwise it reports `source_schema`. At the current H, it compares Core and Technical. Patterns are reported as informational-only and are never appended to KNN vectors.

Each evaluated variant reports:

- five-class supported accuracy, Macro-F1 and per-class precision/recall/F1;
- the chronological majority-class baseline and improvement over it;
- the collapsed three-class BEAR / NEUTRAL / BULL confusion matrix, accuracy and Macro-F1;
- ordinal diagnostics: exact accuracy, within-one-class accuracy, mean absolute class error, same-direction accuracy, opposite-direction rate and extreme-opposite rate;
- coverage, confidence and the current Outcome validation gates for reference.

Examples:

```bash
php -d memory_limit=512M artisan trademinator:outcome-audit bitso 'ATOM/USD' 15m
php -d memory_limit=512M artisan trademinator:outcome-audit bitso 'ATOM/USD' 15m --k=7 --timeout=3600
php -d memory_limit=512M artisan trademinator:outcome-audit bitso 'ATOM/USD' 15m --dataset=DATASET_UUID --k=7
```

Use this command to investigate Outcome target/horizon/feature quality before changing production validation rules. After choosing a research direction, validate that redesign on a still-untouched final holdout before changing readiness or signal scoring.

## trademinator:knn-build

Signature: `trademinator:knn-build {exchange} {symbol} {period} {--dataset=} {--schema=core} {--from=} {--to=} {--as-of=} {--context-fallback= : Deprecated compatibility flag; CoinGecko is always optional}`

Description: Build closed-candle semantic knowledge and validate KNN and pattern intelligence

Build/validate intelligence for one market immediately from existing data. Creates immutable artifacts and can update the active head; it does not fetch missing history. Requires features, private artifact storage and the appropriate locks. A successful command can produce an **abstaining** model: exit 0 does not mean validation passed.

`--dataset` cannot be combined with any date option or a non-default schema. It requires a compatible semantic dataset for the same market, not a fee-aware M3 research dataset. There is no `--all`, `--queue`, `--force` or `--timeout` option on this command. Use the dispatcher for scheduled bulk training; its weekly-generation behavior is not a forced rebuild of all completed models.

| Parameter | Default / requirement | Explanation |
| --- | --- | --- |
| `exchange` | Required | Configured CCXT exchange ID, for example `bitso` or `kraken`; not an exchange UUID. |
| `symbol` | Required | Exact exchange pair, for example `ATOM/USD`. Preserve its case and quote it in the shell. |
| `period` | Required | Supported candle timeframe, for example `15m` or `1h`. `1m` and `1M` are different. |
| `--dataset` | New snapshot | Existing compatible semantic dataset UUID. Mutually exclusive with date selection and non-default schema. |
| `--schema` | `core` | New snapshot input schema: `core`, `technical`, or `full`. |
| `--from` | Configured recent-history window | Inclusive decision-time start; clamped by the configured model-age window. |
| `--to` | Training cutoff | Inclusive decision-time end, bounded by the effective cutoff. |
| `--as-of` | Before newest closed feature | Outcome-availability cutoff, capped at now; research UTC date/millisecond format. |

```bash
php -d memory_limit=512M artisan trademinator:knn-build bitso 'ATOM/USD' 15m --schema=core
php -d memory_limit=512M artisan trademinator:knn-build bitso 'ATOM/USD' 15m --schema=full
php artisan trademinator:knn-build bitso 'ATOM/USD' 15m --dataset=DATASET_UUID
```

CoinGecko is **independent optional Outcome intelligence**, not part of the KNN vector.
Schema/profile definitions:

| Profile | KNN features | Optional CoinGecko Random Forest |
| --- | --- | --- |
| `core` | 15 technical | No |
| `technical` | 18 technical | No |
| `enhanced` | 15 technical | Yes, when separately enabled and validated |
| `full` | 18 technical | Yes, when separately enabled and validated |

The prior Full 27-column KNN was retired. Historical observations and annotations are
preserved, but old 27-feature Full datasets/models require rebuilding.
The `--context-fallback` flag is deprecated and no longer changes selected KNN columns.
Existing `--schema=full --context-fallback=technical` scripts will build the new
18-dimensional Full model, which works without CoinGecko.

CoinGecko insight training is controlled independently with
`INTELLIGENCE_COINGECKO_INSIGHT_ENABLED`. Trading influence remains off with
`INTELLIGENCE_COINGECKO_INFLUENCE_ENABLED=false` unless explicitly enabled after
paired holdout validation.

```bash
php -d memory_limit=512M artisan trademinator:knn-build bitso 'ATOM/USD' 15m --schema=enhanced
php -d memory_limit=512M artisan trademinator:knn-build bitso 'ATOM/USD' 15m --schema=full
```

## trademinator:auto-label

Signature: `trademinator:auto-label {exchange? : Optional exchange class, for example bitso} {symbol? : Optional pair; requires exchange, for example ATOM/USD} {--json : Emit machine-readable JSON instead of a table}`

Description: Run Action auto-labeling and publish shared Outcome/Action diagnostics for active market feeds

Run the exact Action auto-label / horizon analysis used by weekly intelligence training without publishing a model. With no arguments it analyzes every active subscribed market with a selected period. Supplying only `exchange` limits the run to that exchange; supplying `exchange symbol` limits it to one pair.

The analysis reads all available history inside `INTELLIGENCE_MAX_MODEL_AGE_DAYS`, splits history at candle gaps, and auto-labels each contiguous run independently. Every valid consecutive opposite BUY/SELL pivot distance contributes to `d`. At least 30 valid `d` observations are required for an Outcome horizon; 30 is a minimum, never a cap. When valid, `H = round(sum(d * frequency) / sum(frequency))`. The same finalized Action labels are the algorithmic Action KNN targets.

Examples:

```bash
php artisan trademinator:auto-label
php artisan trademinator:auto-label bitso
php artisan trademinator:auto-label bitso 'ATOM/USD'
php artisan trademinator:auto-label bitso 'ATOM/USD' --json
```

This command is diagnostic/manual auto-label execution only. Use `trademinator:knn-build` to publish a fresh model immediately. The normal Monday intelligence build performs the same auto-label pass automatically and uses it for both Action KNN training and Outcome horizon derivation.

## trademinator:dispatch-market-intelligence

Signature: `trademinator:dispatch-market-intelligence {--force : Create a fresh build generation, even if this week was already built or scheduling is disabled} {--all : With --force, also rebuild every previously published market and period}`

Description: Queue weekly KNN and pattern training once per subscribed market and selected period

Queue training for all active subscribed feeds with selected periods on `INTELLIGENCE_QUEUE` (default `intelligence`). Requires enabled intelligence and a persistent queue. Uses the current weekly generation key; already completed generations are not a force-rebuild target. This command only dispatches: **`--timeout=2200` belongs on `queue:work`, not here**.

Parameters: none beyond standard Artisan options.

```bash
php artisan trademinator:dispatch-market-intelligence
```

Implementation: [`DispatchMarketIntelligence.php`](../app/Console/Commands/DispatchMarketIntelligence.php).

## trademinator:dispatch-lead-lag

Signature: `trademinator:dispatch-lead-lag`

Description: Queue daily lead/lag and downstream intelligence reevaluation for overlapping subscribed markets

Queue daily intelligence reevaluation for matching symbol/selected-period groups present on at least two distinct subscribed exchanges. Requires intelligence, lead/lag and daily refresh to be enabled, plus a persistent queue. Work goes to the configured intelligence queue. It does not assume that an exchange always leads based on location.

Parameters: none beyond standard Artisan options.

```bash
php artisan trademinator:dispatch-lead-lag
```

Implementation: [`DispatchLeadLagIntelligence.php`](../app/Console/Commands/DispatchLeadLagIntelligence.php).

## trademinator:tournament

Signature: `trademinator:tournament {dataset? : Frozen research dataset UUID, omitted with --all} {--max-rows=1200 : Shared chronological sample cap between 100 and 4000} {--all : Queue each currently published market dataset}`

Description: Queue research-only Rubix Outcome and Action tournaments on the dedicated tournament queue

Dispatch one unique queue job per dataset to the dedicated tournament queue. Each worker runs Rubix weighted KNN, Classification Tree, Random Forest and Multilayer Perceptron sequentially for Outcome and Action. Results are research-only and do not affect production signals. Use a separate one-worker tournament queue consumer under a 512 MB PHP limit. Use:

    php -d memory_limit=512M artisan trademinator:tournament DATASET_UUID --max-rows=1200
    php -d memory_limit=512M artisan trademinator:tournament --all --max-rows=1200

## trademinator:signal

Signature: `trademinator:signal {exchange} {symbol} {period} {--raw : Compute a raw, unfiltered KNN proposal without advancing signal memory} {--recorded : Read the latest recorded Server observation without running inference}`

Description: Explain the recorded Server signal, or explicitly inspect a raw KNN proposal

Run inference immediately and print the current closed-candle signal/evidence as JSON. Does not train a new model or place an exchange order. Missing, stale, invalid or insufficient evidence can produce abstention; inspect the reported reasons rather than interpreting every HOLD as supported directional evidence.

| Parameter | Default / requirement | Explanation |
| --- | --- | --- |
| `exchange` | Required | Configured CCXT exchange ID, for example `bitso` or `kraken`; not an exchange UUID. |
| `symbol` | Required | Exact exchange pair, for example `ATOM/USD`. Preserve its case and quote it in the shell. |
| `period` | Required | Supported candle timeframe, for example `15m` or `1h`. `1m` and `1M` are different. |

```bash
php artisan trademinator:signal bitso 'ATOM/USD' 15m
```

Implementation: [`PredictMarketIntelligence.php`](../app/Console/Commands/PredictMarketIntelligence.php).

## trademinator:dispatch-market-signals

Signature: `trademinator:dispatch-market-signals`

Description: Queue current signal recording once per actively subscribed market

Queue shared-market signal recording on the intelligence queue for active subscribed feeds with selected periods. Requires dashboard signals and intelligence to be enabled and a persistent queue. Records observations; it does not send exchange orders or replace the training dispatcher.

Parameters: none beyond standard Artisan options.

```bash
php artisan trademinator:dispatch-market-signals
```

Implementation: [`DispatchMarketSignals.php`](../app/Console/Commands/DispatchMarketSignals.php).

## trademinator:model-info

Signature: `trademinator:model-info {model} {--validation-summary : Show compact persisted Outcome/Action validation without reading private KNN sidecars}`

Description: Verify an intelligence artifact or inspect its persisted validation summary

Default mode verifies the model artifact and KNN sidecars, then prints its saved report. The optional summary mode reads **only the persisted database report**, without loading large model sidecars; it separates Outcome/Action, algorithmic/human, HOLD/abstention counts and validation gates. Both modes are read-only.

| Parameter | Default / requirement | Explanation |
| --- | --- | --- |
| `model` | Required | Existing model UUID from a build/report; replace `MODEL_UUID`, not a filesystem path. |
| `--validation-summary` | Off | Compact, DB-only validation report; does not verify model sidecar checksums. |

```bash
php artisan trademinator:model-info MODEL_UUID
php artisan trademinator:model-info MODEL_UUID --validation-summary
```

Implementation: [`InspectIntelligenceModel.php`](../app/Console/Commands/InspectIntelligenceModel.php).

## trademinator:compact-model

Signature: `trademinator:compact-model {exchange} {symbol} {period}`

Description: Convert the active intelligence model to streamed, low-memory knowledge without retraining

Convert a previously trained, active v2 model to the v3 format without rerunning training or changing KNN labels, parameters, weights, or validation. Streams automatic knowledge into a new `.knowledge.jsonl` file and writes Human Outcome/Action knowledge to separate verified files. The previous model remains on disk and in the database for rollback. The newly published model gets a new UUID; its training generation key is deliberately not duplicated. Does not place orders, ingest candles or change model expiry. Requires read/write access to the private intelligence artifact directory and database. Not scheduled.

| Parameter | Default / requirement | Explanation |
| --- | --- | --- |
| `exchange` | Required | Exchange ID, for example `bitso`. |
| `symbol` | Required | Exact pair, for example `'ATOM/USD'`. |
| `period` | Required | Existing active model period, for example `15m`. |

```bash
# A pre-v3 artifact can still need a larger one-time PHP memory allowance.
php -d memory_limit=512M artisan trademinator:compact-model bitso 'ATOM/USD' 15m
php artisan trademinator:signal bitso 'ATOM/USD' 15m
```

Re-running on an already compact v3 model is a no-op. Models older than v2 must be rebuilt rather than compacted. During conversion, the old model is checksummed and the new model is written with separate SHA-256 digests; conversion fails instead of promoting damaged data. Predictions remain subject to the configured memory limit if other components (such as pattern models) are large. For pre-v3 models, run compaction under a suitable PHP CLI memory limit first.

Implementation: [`CompactIntelligenceModel.php`](../app/Console/Commands/CompactIntelligenceModel.php).

## trademinator:analyze-validation-gates

Signature: `trademinator:analyze-validation-gates {--directional=5,20,30,50,75,100 : Comma-separated candidate minimum directional counts} {--precision=0.55,0.60,0.65 : Comma-separated candidate absolute semantic precision floors} {--baseline-lift=0.10 : Required Wilson lower-bound lift over the observed Action holdout prediction-mix baseline} {--wilson-floor=0.50 : Absolute Wilson 95% lower-bound floor} {--all-models : Include historical models instead of current heads only} {--limit=500 : Maximum models when --all-models is used} {--json : Print the complete report as JSON}`

Description: Read-only Action KNN holdout calibration; never uses Outcome confusion

Analyze **independent algorithmic Action KNN holdouts only**. This command reads persisted database reports without loading entire research datasets or model sidecars. Models predating Phase 2 Action validation are skipped explicitly. Natural HOLD and abstention counts remain separate; no minimum directional coverage quota is imposed. Neither readiness nor training data is changed.

The Phase 2 prediction-mix baseline comes from **observed finalized Action holdout class frequencies**, not training priors. This candidate grid is exploratory: selecting thresholds based on an already observed final holdout requires fresh unseen chronological data for subsequent validation. Ratios below are fractions, not percentages.

| Parameter | Default / requirement | Explanation |
| --- | --- | --- |
| `--directional` | `5,20,30,50,75,100` | Comma-separated positive integer candidate minimum directional prediction counts. |
| `--precision` | `0.55,0.60,0.65` | Comma-separated candidate semantic precision floors; each 0–1. |
| `--baseline-lift` | 0.10 | Required Wilson lower-bound lift above the observed Action holdout prediction-mix baseline; 0–1. |
| `--wilson-floor` | 0.50 | Absolute Wilson 95% lower confidence-bound floor; 0–1. |
| `--all-models` | Off | Analyze historical models instead of current heads only. |
| `--limit` | 500 | Maximum historical models when `--all-models` is used; integer 1–5000. |
| `--json` | Off | Print the full report as JSON instead of tables. |

```bash
php artisan trademinator:analyze-validation-gates
php artisan trademinator:analyze-validation-gates --directional=5,20,50 --precision=0.55,0.60 --json
php artisan trademinator:analyze-validation-gates --all-models --limit=100 --json
```

Implementation: [`AnalyzeValidationGates.php`](../app/Console/Commands/AnalyzeValidationGates.php).

## trademinator:human-candle-audit

Signature: `trademinator:human-candle-audit {exchange} {symbol} {period} {--dataset= : Current-version target dataset; defaults to the published model dataset} {--timeout=300 : Eligibility inspection budget in seconds, without KNN validation}`

Description: Read-only audit of recorded human candles, current-feature reuse and exclusion reasons

Inspect Human Action Training eligibility using the **same loader as model training**, without creating a dataset, changing annotations, publishing a model, or running KNN tuning/holdout validation. Run as the application account so private dataset files are readable. The canonical history reader may populate its ordinary cache; authenticated source artifacts are never rewritten.

Each original annotation is checked against its checksum-verified frozen dataset. A feature-version or schema change alone no longer discards a provenance-bearing annotation: the loader verifies the reviewed OHLCV chart and projects the action onto the current technical feature vector at the same decision time. Changed, inserted or missing chart candles, unavailable evidence, missing current inputs, unauthorized reviewers and inconsistent source snapshots remain exclusions. Corrupt artifacts fail the audit rather than being silently skipped. Source-less legacy research records retain their same-version behavior but cannot cross feature versions or gain missing inputs.

| Parameter | Default / requirement | Explanation |
| --- | --- | --- |
| `exchange` | Required | Exact CCXT exchange ID, such as `bitso`. |
| `symbol` | Required | Exact exchange symbol, such as `'ATOM/USD'`. |
| `period` | Required | Exact candle period, such as `15m`. |
| `--dataset` | Published model's dataset | Current-feature-version target dataset UUID for this market. Its technical key subset and as-of cutoff define this audit; an old-version annotation source is not a target dataset. |
| `--timeout` | 300 | Positive integer inspection budget, at most 3,600 seconds. This only controls the audit, not model-training deadlines. |

```bash
php -d memory_limit=512M artisan trademinator:human-candle-audit bitso 'ATOM/USD' 15m
php -d memory_limit=512M artisan trademinator:human-candle-audit bitso 'ATOM/USD' 15m --dataset=DATASET_UUID --timeout=600
```

The JSON separates `recorded_labels`, `recorded_distinct_candles`, sequential `prefiltered_snapshots`, per-reason `excluded` snapshots, duplicate eligible snapshots, projected candles and final `samples`/class counts. Snapshot counts and distinct-candle counts are different units. `validation_performed: false` is deliberate: enough eligible annotations do not guarantee validation. To retrain after a satisfactory audit, use the normal `knn-build` command with the intended schema, for example `--schema=full`; no SQL relabeling or migration is needed. See [HUMAN-CANDLE-REUSE.md](HUMAN-CANDLE-REUSE.md) for deployment and verification.

Implementation: [`AuditHumanCandleTraining.php`](../app/Console/Commands/AuditHumanCandleTraining.php).

## trademinator:human-training-export

Signature: `trademinator:human-training-export {path : New private JSONL output file}`

Description: Export versioned human training snapshots and reviews without account secrets

Export verified human-training snapshots, submitted trend reviews and saved candle labels to versioned JSONL with a checksum. Runs immediately. Includes trainer UUIDs, not account secrets; keep the file private. It does not generate missing labels or train a model. The parent directory must already exist and the output filename must be new; the file is created with restrictive permissions.

| Parameter | Default / requirement | Explanation |
| --- | --- | --- |
| `path` | Required | New output file in an existing writable private directory; existing files are rejected. |

```bash
mkdir -p storage/app/private/exports
php artisan trademinator:human-training-export storage/app/private/exports/human-training-2026-10-05.jsonl
```

Implementation: [`ExportHumanTraining.php`](../app/Console/Commands/ExportHumanTraining.php).

## trademinator:archive-tickers

Signature: `trademinator:archive-tickers {exchange} {symbol} {period} {month : YYYY-MM} {--dry-run}`

Description: Export one immutable monthly ticker shard to verified cold storage without pruning hot rows.

Export one market/month to compressed JSONL plus a manifest, verify it and update the archive catalog. Requires enabled archiving and writable archive storage. **Does not delete hot database rows or free their database space.** Choose a completed historical month for normal archival. Returned paths are relative to `ARCHIVE_PATH`.

| Parameter | Default / requirement | Explanation |
| --- | --- | --- |
| `exchange` | Required | Configured CCXT exchange ID, for example `bitso` or `kraken`; not an exchange UUID. |
| `symbol` | Required | Exact exchange pair, for example `ATOM/USD`. Preserve its case and quote it in the shell. |
| `period` | Required | Supported candle timeframe, for example `15m` or `1h`. `1m` and `1M` are different. |
| `month` | Required | UTC calendar month in `YYYY-MM` format. |
| `--dry-run` | Off | Report row count and intended paths without writing an archive. |

```bash
php artisan trademinator:archive-tickers bitso 'ATOM/USD' 15m 2026-08 --dry-run
php artisan trademinator:archive-tickers bitso 'ATOM/USD' 15m 2026-08
```

Implementation: [`ArchiveTickers.php`](../app/Console/Commands/ArchiveTickers.php).

## trademinator:archive-eligible-tickers

Signature: `trademinator:archive-eligible-tickers {--dry-run}`

Description: Export and verify complete ticker months older than the configured hot-retention boundary; never prunes rows.

Process complete eligible historical months across stored markets immediately. Uses `ARCHIVE_AFTER_DAYS` (default 90; minimum 32), rounded to a month boundary. Requires enabled archiving. Reports shard/failure counts; failures make the command fail. **Pruning remains disabled.**

| Parameter | Default / requirement | Explanation |
| --- | --- | --- |
| `--dry-run` | Off | Count eligible rows/shards without writing archive files. |

```bash
php artisan trademinator:archive-eligible-tickers --dry-run
php artisan trademinator:archive-eligible-tickers
```

Implementation: [`ArchiveEligibleTickers.php`](../app/Console/Commands/ArchiveEligibleTickers.php).

## trademinator:archive-verify

Signature: `trademinator:archive-verify {manifest? : Relative manifest path}`

Description: Verify one or all archive shards, including checksum, row count and boundary keys.

Verify the compressed data checksum/size, row count and boundary keys. With no path, scan all manifests. Does not restore candles, but **does update verification metadata and the archive catalog**; it is not strictly read-only. Use the real manifest path returned by `archive-tickers`.

| Parameter | Default / requirement | Explanation |
| --- | --- | --- |
| `manifest` | All manifests | Optional manifest path relative to `ARCHIVE_PATH`; symbols in archive paths are URL-encoded. |

```bash
php artisan trademinator:archive-verify
php artisan trademinator:archive-verify 'tickers/bitso/ATOM%2FUSD/15m/2026-08.manifest.json'
```

Implementation: [`VerifyArchives.php`](../app/Console/Commands/VerifyArchives.php).

## trademinator:archive-restore

Signature: `trademinator:archive-restore {manifest : Relative manifest path} {--from= : Inclusive millisecond timestamp} {--to= : Inclusive millisecond timestamp} {--validate-only}`

Description: Validate or restore one verified archive shard into hot storage without overwriting conflicts.

Verify one archive and restore missing rows to hot storage, optionally restricted to an interval. Conflicting existing data is not silently overwritten. **Run validation first and back up the target database before restoration.** Verification can update archive metadata even in validation-only mode. Bounds must be numeric Unix **milliseconds**, not seconds, ISO dates or relative dates.

| Parameter | Default / requirement | Explanation |
| --- | --- | --- |
| `manifest` | Required | Existing manifest path relative to `ARCHIVE_PATH`; use the path returned when archiving. |
| `--from` | No lower bound | Inclusive candle timestamp in Unix milliseconds. |
| `--to` | No upper bound | Inclusive candle timestamp in Unix milliseconds. |
| `--validate-only` | Off | Verify and check without inserting restored ticker rows. |

```bash
php artisan trademinator:archive-restore 'tickers/bitso/ATOM%2FUSD/15m/2026-08.manifest.json' --validate-only
php artisan trademinator:archive-restore 'tickers/bitso/ATOM%2FUSD/15m/2026-08.manifest.json'
```

Implementation: [`RestoreArchive.php`](../app/Console/Commands/RestoreArchive.php).

## trademinator:archive-rebuild-catalog

Signature: `trademinator:archive-rebuild-catalog`

Description: Rebuild the active archive catalog by scanning and verifying filesystem manifests.

Reconstruct the active archive catalog from filesystem manifests, verifying shards as it goes. Updates catalog/verification metadata; it neither fetches exchange data nor restores candles into the hot ticker table. Useful after recovering archive files or catalog state.

Parameters: none beyond standard Artisan options.

```bash
php artisan trademinator:archive-rebuild-catalog
```

Implementation: [`RebuildArchiveCatalog.php`](../app/Console/Commands/RebuildArchiveCatalog.php).

## trademinator:portable-export

Signature: `trademinator:portable-export {path} {--dataset=* : Logical datasets; currently tickers}`

Description: Create a database-independent gzip JSONL export. Secrets are excluded.

Write a **single gzip JSONL file** immediately from hot database tickers and print its checksum/size/row counts. This CLI command is distinct from the owner page's multipart export workflow. It is not a full application backup: accounts, credentials, models and cold-only archive rows are not included.

Use a new private output path: publishing the export can replace an existing file at that path. Missing parent directories are created.

| Parameter | Default / requirement | Explanation |
| --- | --- | --- |
| `path` | Required | Destination gzip JSONL filename; absolute or relative to the current working directory. |
| `--dataset` | `tickers` | Repeatable option, but currently only the logical dataset `tickers` is supported. Not a research dataset UUID. |

```bash
php artisan trademinator:portable-export storage/app/private/exports/tickers-2026-10-05.jsonl.gz --dataset=tickers
```

Implementation: [`PortableExport.php`](../app/Console/Commands/PortableExport.php).

## trademinator:portable-import

Signature: `trademinator:portable-import {path} {--validate-only}`

Description: Validate or import a portable gzip JSONL package without silently overwriting conflicts.

Validate or import a compatible single-file CLI portable package immediately. Identical existing candles are skipped; conflicting rows fail instead of being overwritten. This is not the multipart owner-upload interface. **Validate first and back up the target database**; do not treat an import as an all-or-nothing database replacement.

| Parameter | Default / requirement | Explanation |
| --- | --- | --- |
| `path` | Required | Existing compatible gzip JSONL package file. |
| `--validate-only` | Off | Read/check the package and existing-data conflicts without inserting rows. |

```bash
php artisan trademinator:portable-import storage/app/private/exports/tickers-2026-10-05.jsonl.gz --validate-only
php artisan trademinator:portable-import storage/app/private/exports/tickers-2026-10-05.jsonl.gz
```

Implementation: [`PortableImport.php`](../app/Console/Commands/PortableImport.php).

## trademinator:prune-portable-archives

Signature: `trademinator:prune-portable-archives`

Description: Delete expired temporary multipart portable archive transfers and files

Delete expired **staged multipart** import/export directories under `ARCHIVE_PORTABLE_PATH` and their `portable_archive_transfers` metadata (including related part metadata). Expiration is controlled by `ARCHIVE_PORTABLE_RETENTION_HOURS` (default 24). Deletion is immediate; this command does **not** delete permanent cold-history archives or hot ticker rows. The scan is performed in bounded batches, and the command reports how many transfers were pruned.

Parameters: none beyond standard Artisan options.

```bash
php artisan trademinator:prune-portable-archives
```

Scheduled daily at 04:40 (application timezone), on one server with an overlap lock and background execution. No archive queue worker is required for this cleanup.

Implementation: [`PrunePortableArchives.php`](../app/Console/Commands/PrunePortableArchives.php).

## trademinator:mailgun-check

Signature: `trademinator:mailgun-check {--to= : Send a test email to this address after validating configuration}`

Description: Check Mailgun settings and optionally send a test message

Check the Mailgun mailer, domain, secret, endpoint and sender configuration. Without `--to`, no email is sent and live delivery is not tested. With `--to`, submit a real test message immediately. Success means Mailgun submission, not confirmed inbox delivery.

| Parameter | Default / requirement | Explanation |
| --- | --- | --- |
| `--to` | Unset; no email | Valid recipient address for a real test message after configuration checks succeed. |

```bash
php artisan trademinator:mailgun-check
php artisan trademinator:mailgun-check --to=you@example.com
```

Implementation: [`CheckMailgun.php`](../app/Console/Commands/CheckMailgun.php).

## trademinator:prune-access-statistics

Signature: `trademinator:prune-access-statistics`

Description: Delete access statistics older than the configured retention period

Delete expired access aggregates and daily visitor hashes in bounded batches. Retention comes from `ACCESS_STATISTICS_RETENTION_DAYS` (default 90); keep today and the preceding N−1 UTC dates. **Deletion is immediate**, but does not remove users, subscriptions, candles, models or OS logs. There is no command-specific dry-run option.

Parameters: none beyond standard Artisan options.

```bash
php artisan trademinator:prune-access-statistics
```

Implementation: [`PruneAccessStatistics.php`](../app/Console/Commands/PruneAccessStatistics.php).

## Other repository commands

### inspire

Print the framework's inspirational quote. This local closure is registered in `routes/console.php`; it has no command-specific arguments/options and performs no market operation.

```bash
php artisan inspire
```

### Offline exchange metadata

Invocation: `php scripts/build-exchange-metadata.php [--runtime|EXCHANGE_ID]`

Requires installed Composer dependencies, CLI PHP/process creation, and writable metadata/lock directories. Runs offline without booting Laravel or connecting to the database/exchanges. Supply at most one argument; this is not an Artisan command.

| Argument | Effect |
| --- | --- |
| None | Rebuild the release bundle `resources/data/ccxt-exchanges.json`. |
| `--runtime` | Rebuild the local runtime bundle `storage/app/private/ccxt-exchanges.json`. |
| `EXCHANGE_ID` | Inspect one installed adapter and print JSON; internal inspection mode, not approval of the adapter. |

```bash
php scripts/build-exchange-metadata.php
php scripts/build-exchange-metadata.php --runtime
php scripts/build-exchange-metadata.php kraken
```

Changed/unreviewed adapters still require review. To refresh metadata **and** reconcile missing database entries, use `trademinator:refresh-exchanges`.

## Scheduler and workers

The scheduler and workers are different processes. `schedule:run` dispatches due scheduled work; it does not drain every queue. Scheduling source is [`routes/console.php`](../routes/console.php); installation-specific cron/flock details belong in [CRONTABS.md](CRONTABS.md).

| Command | Purpose / parameters | Simple example |
| --- | --- | --- |
| `schedule:list` | Show the application schedule; no project-specific parameters. | `php artisan schedule:list` |
| `schedule:run` | Run currently due scheduled tasks once. | `php artisan schedule:run` |
| `queue:work [connection]` | Process the selected connection (omit for configured default) and named queues. See options below. | See worker examples below. |
| `queue:failed` | List stored failed jobs; not the pending queue backlog. | `php artisan queue:failed` |
| `queue:retry ID` | Requeue the selected failed-job ID/UUID after fixing the cause. `all` retries all stored failures. | `php artisan queue:retry FAILED_JOB_ID` |
| `queue:forget ID` | Permanently remove one stored failure record; does not undo work already performed. | `php artisan queue:forget FAILED_JOB_ID` |
| `queue:restart` | Request graceful worker restart after the current job; not a force kill. | `php artisan queue:restart` |

Use the identifier printed by `queue:failed`, not a model/dataset UUID. Avoid bulk retry/deletion until the cause is understood. There is no project CLI `queue-backlog` command; the owner page provides queue-backlog inspection and failed-job controls.

### Worker parameters

| Option / setting | Meaning |
| --- | --- |
| `--queue=NAME[,NAME...]` | Queue names to drain, in priority order. Names are not connection/backend names. Match the configured names. |
| `--stop-when-empty` | Exit when there is no immediately available work; delayed/released/continued jobs may require a later pass. |
| `--max-time=50` | Recycle the worker after the duration, checked between jobs; not a 50-second deadline for each job. |
| `--timeout=SECONDS` | Worker per-job timeout. A job's own timeout can override it. It does not replace cooperative time limits inside feature/model code. |
| `--memory=MB` | Worker memory-recycling threshold checked between jobs. |
| `php -d memory_limit=512M` | PHP's separate hard in-process memory ceiling. This setting precedes `artisan`. |
| `--tries=N` | Worker attempt limit, subject to job-specific retry policy. |

These examples drain the current default queues without starting a permanent daemon. Run only the workers assigned to that host and replace customized queue names. Resource values are starting settings, not capacity guarantees.

```bash
# Live feeds and manually queued candle pages.
php -d memory_limit=512M artisan queue:work --queue=default --stop-when-empty --max-time=50 --timeout=600 --memory=384 --tries=5

# M2 feature jobs have their own queue; do not omit it.
php -d memory_limit=512M artisan queue:work --queue=features --stop-when-empty --max-time=50 --timeout=600 --memory=384 --tries=3

# Older-history collection and missing-candle repair.
php -d memory_limit=256M artisan queue:work --queue=history --stop-when-empty --max-time=50 --timeout=120 --memory=192 --tries=1

# Model builds, shared signal recording, and backfill rebuild stages.
php -d memory_limit=512M artisan queue:work --queue=intelligence --stop-when-empty --max-time=50 --timeout=2200 --memory=384 --tries=3
```

`FEATURES_QUEUE`, `HISTORY_BACKFILL_QUEUE` and `INTELLIGENCE_QUEUE` can override the names. A scheduler success or an empty database `jobs` table does not prove Redis/other backend queues are empty. Use the actual configured connection and owner monitoring.

Keep queue reservation/retry timing longer than the longest job timeout; the documented database/Redis configuration uses at least 1080 seconds for 900-second intelligence jobs. Configure SQS visibility separately when used. Multiple scheduler nodes need a shared lock-capable cache. Queued feature continuations require subsequent worker passes; increasing only `--timeout` does not remove internal replay limits.

## Common workflows

### Inspect and repair a candle gap

```bash
php artisan trademinator:candle-gaps --exchange=bitso --symbol='ATOM/USD' --period=15m
# Run the exact sync-ohlcv command printed for the hole. Example only:
php artisan trademinator:sync-ohlcv bitso 'ATOM/USD' 15m --from='2024-02-28T20:45:00Z' --to='2024-02-28T21:15:00Z' --repair-gaps
php artisan trademinator:candle-gaps --exchange=bitso --symbol='ATOM/USD' --period=15m
```

For an isolated missing candle, keep the suggested one-candle padding before/after it; do not set the initial `--from` and `--to` to the same value.

### Inspect backfill and rebuild progress

```bash
php artisan trademinator:backfill-ohlcv --exchange=bitso --symbol='ATOM/USD' --period=15m --status
php artisan queue:failed
```

Read `status`, `reason`, `last_error`, `next_attempt_at`, `history_revision`, `trained_revision`, `build_stage`, `build_revision` and `build_error`. Matching history/trained revisions mean the recorded history generation was included in a completed rebuild; they do **not** prove model validation or sufficient human labels. Resume only after reviewing why a cursor paused.

### M4 intelligence workflow and upgrade

For one market with existing candles, rebuild features before the model, then inspect its signal. These commands run sequentially, not in parallel:

```bash
php artisan trademinator:build-features bitso 'ATOM/USD' 15m
php -d memory_limit=512M artisan trademinator:knn-build bitso 'ATOM/USD' 15m --schema=core
php artisan trademinator:signal bitso 'ATOM/USD' 15m
```

For routine bulk work on active subscriptions:

```bash
php artisan trademinator:dispatch-market-features
# Drain features and confirm catch-up before relying on new inputs.
php -d memory_limit=512M artisan queue:work --queue=features --stop-when-empty --max-time=50 --timeout=600 --memory=384 --tries=3
php artisan trademinator:dispatch-market-intelligence
php -d memory_limit=512M artisan queue:work --queue=intelligence --stop-when-empty --max-time=50 --timeout=2200 --memory=384 --tries=3
```

A single bounded worker pass may not finish all continuations. The weekly dispatcher does not force already completed weekly generations to rebuild and has no `--schema` argument; queued builds use configured intelligence settings. Direct `knn-build --schema=full` is a one-market operation.

### Choosing a feature schema

| Schema | Use / prerequisite |
| --- | --- |
| `core` | Technical inputs without the long elapsed-return features; CLI default for dataset/model builds. Still needs closed history and indicator warm-up. |
| `technical` | Adds longer elapsed returns; needs enough historical span. |
| `full` | Technical inputs plus genuinely observed CoinGecko context; missing historical context can exclude rows. |
| `custom` | Research dataset builds only: provide an ordered `--features` list of known keys. |

Fetching today's CoinGecko data cannot reconstruct yesterday's context. Do not apply a current snapshot to historical candles merely to make `full` pass.

### Reading intelligence readiness

A finished build is not the same as a validated model. Read the reported status, validation evidence, freshness and abstention reasons. `INTELLIGENCE_MAX_MODEL_AGE_DAYS` is a history/expiry window, **not a mandatory waiting period**. An insufficient-human-label reason requires eligible submitted candle labels; collecting more automatic candles alone does not create them. Unsubmitted browser labels are not training rows.

`optional budget exhausted` identifies an optional training-stage budget limit, not a missing-candle count. Gap repair, more labels and more execution time address different causes. Current intelligence jobs use a 2200-second timeout with a separate Human Action Training allowance; see [worker budgets and logging](CRONTABS.md#m4-intelligence-workers). Explicit command success does not override any validation gate.

### Where the KNN inputs are prepared

The feature builder combines stored technical/context features in `market_features`. Dataset builders freeze ordered vectors and separate labels; model builds consume compatible frozen knowledge and publish reports/artifacts. `build-features`, `build-dataset`, and `knn-build` are different stages, not interchangeable aliases. Preserve the private research/intelligence files together with their database records.

## Deployment and documentation checks

Run only the steps appropriate to your deployment; these are supporting Laravel/Composer commands, not new Trademinator commands.

| Command | Purpose / parameter explanation | Example |
| --- | --- | --- |
| `composer install` | Install locked dependencies; `--no-dev` excludes development dependencies, `--prefer-dist` prefers distributions, `--optimize-autoloader` optimizes autoloading. | `composer install --no-dev --prefer-dist --optimize-autoloader` |
| `migrate --force` | Apply pending migrations; `--force` bypasses Laravel's production confirmation, not a data-safety check. | `php artisan migrate --force` |
| `optimize:clear` | Clear generated optimization caches; can also affect cached application data. | `php artisan optimize:clear` |
| `config:cache` | Rebuild cached configuration after `.env` changes. | `php artisan config:cache` |
| `view:cache` | Precompile Blade views. | `php artisan view:cache` |
| `test --compact PATH` | Run the named test file with compact output; requires development/test dependencies and an isolated test configuration. | `php artisan test --compact tests/Feature/TrademinatorCliDocumentationTest.php` |

Preserve production `.env`, `APP_KEY`, database and private storage. Do not use `migrate:fresh` as an upgrade/recovery command. Restart long-lived workers after code/configuration changes; cron-drained workers load the updated application on their next invocation.

## Owner administration, syslog and local GeoIP

Retained operational notes for existing links from cron/deployment documentation. `OWNER_UUID` is an existing account's `users.user_id`, not an API key or a replacement for normal sign-in/email verification. Owner configuration belongs in the environment, not request parameters. Keep `TRUSTED_PROXIES` restricted to actual trusted proxy addresses; do not use an unrestricted wildcard on a public server.

After changing environment settings, rebuild configuration. For structured action logs, enable `ACTION_SYSLOG_ENABLED` and use `ACTION_SYSLOG_IDENT` (default `trademinator`):

```bash
php artisan config:cache
journalctl -t trademinator --since '1 hour ago' -o cat
journalctl -t trademinator -f -o cat
```

These are operating-system `journalctl` commands: `-t` selects the syslog identifier, `--since` limits history, `-f` follows new entries, and `-o cat` prints message bodies. They require a working local syslog/journald setup; normal Laravel logs remain relevant for detailed failures.

### Syslog and trace IDs

Use `trace_id` and `parent_trace_id` to correlate structured command/job events. Command completion is not proof that every downstream job completed. For Human Action Training, inspect `intelligence.human_training.*` progress/timeout events alongside `job.timeout`; a blocked call cannot emit progress while it is blocked. Do not copy secrets or sensitive diagnostic payloads into shared reports.

### Local GeoIP database

Set `GEOIP_DATABASE_PATH` to a readable private/local City MMDB file obtained under your own MaxMind account/license. The application reads it locally; it does not download databases or send visitor IPs to a remote geolocation service. Missing/unreadable files yield unknown geography while request counting can continue. Keep OS-managed updates and licensing outside application CLI arguments; see [CRONTABS.md](CRONTABS.md) for the existing update/retention guidance.

## Keeping this reference complete

When adding, renaming or changing a command, update its index entry, exact signature/description, every parameter/default, side-effect notes and example. Keep runtime-added options such as `fetch-ohlcv --isolated` documented too. Update [CRONTABS.md](CRONTABS.md) separately whenever schedules or worker requirements change.

```bash
php artisan list trademinator
php artisan test --compact tests/Feature/TrademinatorCliDocumentationTest.php
```

The automated documentation test checks command coverage, normalized signatures/descriptions and lowercase names. It does not prove the prose or examples are correct; verify those against the command implementation and any delegated service when behavior changes.


### Current Outcome + Action KNN contract

trademinator:knn-build builds independently tuned Outcome and Action KNNs. H is the frequency-weighted mean spacing of opposite Action pivots. Outcome uses M=tanh(beta*sqrt(H)/V), V=ATR_t/Close_t, and hard boundaries -0.60/-0.20/0.20/0.60. K candidates begin at 4. Human source weight is min(0.60,0.60*sqrt(N_H/750)). Decision matrix v3 allows Action BUY with Neutral/Bull/Super Bull, keeps the Bear/Super Bear BUY veto, and preserves previous SELL rules. The default build budget is INTELLIGENCE_MAX_SECONDS=1800; intelligence workers use --timeout=2200.

### Degraded KNN availability

When Outcome is unavailable, supported Action KNN BUY/HOLD/SELL predictions are retained with `degraded_action_only`, preserving Action confidence and evidence metadata; the Client still applies its normal safeguards. Outcome-only states return HOLD. An abstaining Action KNN cannot initiate a trade. Old recorded signals remain immutable; new recordings use `outcome-action-matrix-v3`. A model rebuild is not required for this inference-only policy change.
