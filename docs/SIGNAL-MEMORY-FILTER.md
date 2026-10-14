# Server signal memory filter

Base revision: `d0e8aa324d4245f04b28a397441a8e45aa69dfa4`.
Policy: `signal-memory-atr3-ema-v1`.

## Contract

This is a post-inference Server filter, after the existing Outcome/Action decision
matrix. It does not modify labels, model readiness, KNN votes, or fee/Client rules.
Only a supported BUY/SELL (including `degraded_action_only`) can release an action.
The filter does not submit exchange orders.

1. Compute directional close-to-close displacement from the immediately preceding
   completed candle. BUY = previous close minus current close. SELL = current close
   minus previous close. Emit when it is strictly greater than the PREVIOUS closed
   candle's ATR(3, EMA).
2. Otherwise, when there is no active same-direction reference, remember the current
   signal as t0/tN, save the CURRENT candle's ATR(3, EMA), and wait.
3. Otherwise update tN, keeping t0 and its ATR fixed. BUY accumulated displacement =
   close(t0) minus close(tN); SELL = close(tN) minus close(t0). Emit only when that
   displacement is strictly greater than the saved threshold; otherwise wait.
4. After either emission path, re-anchor t0/tN at the current signal and save its
   current ATR for the next accumulation. Previous observations remain immutable.

This makes the ATR timing explicit: the immediate path uses a pre-existing
volatility benchmark; the reference-saving boxes use current ATR as shown in the
approved diagram. All differences and ATR values use the same quote-price units.
Equality waits. Negative displacement cannot pass. Absolute travel is never summed.

There is deliberately no extra "better than the last emitted price" restriction on
step 1, no higher timeframe, and no arbitrary cap on consecutive signals. A sharp
move after a rebound can therefore pass step 1 even at a worse price than an earlier
same-direction emission. The Client still decides whether it can execute.

## Lifecycle and storage

The latest immutable `market_signals.payload.signal_memory_state` contains the
active reference, most recent processed source time, fingerprint, and recursive ATR
checkpoint. The existing shared-market recorder lock serializes transitions.
One journal insertion commits the signal and memory together: there is no second
state write that could survive a failed signal insertion. No migration is needed.

- An identical retry reuses the stored verdict without running the transition again.
- A changed proposal for an already processed candle waits for a new source candle,
  even if the earlier verdict was WAIT. Identical retries retain their old verdict.
- Older jobs cannot rewind the latest recorded observation or memory watermark.
- Activation does not re-emit a source candle already recorded without the filter.
- A supported HOLD never releases an old candidate. It keeps t0 while updating ATR
  continuity, but it does not count as another BUY/SELL tN.
- An opposite supported proposal cancels the old pending direction immediately,
  including when the new proposal must itself wait.
- An upstream abstention clears the pending reference. Degraded Action-only support
  is not treated as abstention.
- Market/period/policy changes reset scope. A new model resets the active reference;
  its same-period ATR checkpoint can continue because ATR is independent of KNN.
- Candle gaps, invalid data, insufficient ATR history, zero ATR, or a source-price
  mismatch wait/reset rather than inventing confirmation.
- There is no time-based candidate expiry in this version. Data continuity,
  abstentions, direction changes, model changes, and scope changes govern resets.

ATR is computed from canonical `TickerRepository::streamHistory()` OHLCV, using
`Trademinator\Indicators\Traits\Technical::atr(..., 3, 'ema')`. It is NOT derived
from the capped/normalized `volatility.atrp_3` KNN feature, whose default smoothing
is different. Cold initialization streams the configured model-age history window;
then a small calculated overlap preserves the exact recursive boundary. It retains
nine rows once mature and at most thirteen during initial seeding. Four contiguous
closed candles are required so both current and previous ATR are mature.

No current open candle, later endpoint, or reconstruction unavailable at the source
decision time is used. The ATR initialization window is fixed by the first cold
start; it is not reseeded from a sliding short window on every prediction.

## Evidence, UI, and Client compatibility

`scoring.signal_memory` exposes status, path, raw proposal action/reason/confidence,
immediate and accumulated displacement, applied threshold, current/previous ATR,
and the resulting reference. `raw_resolved_action` preserves the upstream matrix
result; `scoring.resolved_action` matches the final, post-filter Server action.
The recursive checkpoint is NOT included in the
existing Client API `scoring` payload.

Pending proposals become Server `hodl` / Client `hold`, with zero actionable
confidence and reason `awaiting_price_displacement`. The original confidence and
raw Action/Outcome evidence remain in scoring. Existing Client evidence-status
values remain unchanged; the new filter's explicit state is in
`scoring.signal_memory.status`.

Emitted proposals retain their original support reason, including
`degraded_action_only`. Raw blue Action KNN markers remain available independently
of filtered Server arrows. Existing recorded observations are not retroactively
filtered or backfilled. This is displacement gating, not proof of a price reversal.

## Deployment

The switch defaults to false for an explicit rollout. Apply the patch first, run
its tests, then add this line to the deployment `.env`:

```dotenv
SIGNAL_MEMORY_ENABLED=true
```

```bash
php artisan config:clear
php artisan queue:restart
```

The existing `trademinator:dispatch-market-signals` schedule processes the filter.
No new cron, queue, migration, frontend build, feature rebuild, or KNN rebuild is
required. The first cold ATR initialization may read more history; subsequent
recordings resume the saved checkpoint. Never run tests against a production
connection; the existing test bootstrap forces SQLite `:memory:`. It also forces
the rollout switch off for baseline tests; the new integration tests explicitly
opt in through configuration so deployment `.env` values cannot alter the suite.

```bash
php -d memory_limit=512M artisan test \
    tests/Unit/SignalMemoryPolicyTest.php \
    tests/Feature/SignalMemoryFilterTest.php \
    tests/Feature/DashboardSignalsTest.php \
    tests/Feature/M5SignalFreshnessTest.php
```

When enabled, `trademinator:signal` reads the recorded Server observation by default,
so inspecting it cannot consume or re-anchor a candidate. `--recorded` is also
available when the filter is disabled. `--raw` explicitly computes an unfiltered
proposal and never advances journal memory. The two flags are mutually exclusive.

Example, using 4h as the selected period:

```bash
php -d memory_limit=512M artisan trademinator:signal bitso 'ATOM/USD' 4h
php -d memory_limit=512M artisan trademinator:signal bitso 'ATOM/USD' 4h --raw
```

Recorded output includes source/recording times and `observation.is_fresh`. It is an
observation, not an execution approval; an old BUY remains an old observation, not
a current trade. With no journal record for the selected period, recorded mode
reports that fact instead of silently substituting a raw proposal.

To disable, set `SIGNAL_MEMORY_ENABLED=false`, clear config, and restart queue
workers. New recordings return to the unfiltered policy; past records remain intact.

## Validation status of this patch

The modified original files were matched byte-for-byte to the base revision.
PHP syntax checks and an offline policy/filter harness were run. The harness uses
a decimal oracle and framework/ATR-provider stubs; it is not a Laravel integration
run and does not verify the installed indicator implementation or database behavior.

The included Pest tests exercise those real dependencies, including recursive ATR
checkpoint parity across more than one retained slice, source-time isolation, gap
resets, JSON persistence, journal deduplication, and read-only CLI behavior. They
must be run on an environment with the project's locked Composer dependencies
before the filter is enabled. Remote writes/test setup were blocked during patch
preparation, so no passing Laravel/Pest result is claimed here.
