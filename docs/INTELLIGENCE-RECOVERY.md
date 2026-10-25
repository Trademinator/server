# Outcome + Action intelligence recovery

Current contract (2026-10-07): rebuild old-version models and frozen Outcome datasets. Outcome KNN and Action KNN use independent K values, dynamic Human Training source weights, and the fixed Outcome × Action decision matrix.

This document describes the Outcome + Action redesign prepared from GitHub `main`
at commit `194ee23bc33a48196c84cdc486e1742a828bed7d` (2026-10-07).

## Deploy

Apply the patch to a clean checkout, then:

```sh
php artisan optimize:clear
php artisan queue:restart
```

No new migration, Composer dependency or frontend JavaScript asset changes are
required. Keep existing datasets, models and annotations. Sites that cache
configuration should regenerate that cache after editing `.env`. The existing
history/intelligence workers and scheduler remain unchanged.

Existing stacked models must be rebuilt for validation version `m6-outcome-action-knn-v1`;
prediction abstains on an old version. Rebuild each market using its actual
exchange, symbol, selected period and desired schema, for example:

```sh
php artisan trademinator:knn-build kraken BTC/USD 1h --schema=full
```

`full` still requires complete CoinGecko context for the algorithmic Outcome source. Existing
technical annotation datasets can supply Human Action Training without requiring CoinGecko
context. The recovery action also forces a rebuild; redispatching
an already completed weekly generation does not. See [CLI.md](CLI.md#trademinatorknn-build).

## Two independent KNNs

Outcome KNN and Action KNN answer different questions and tune independent K values.

Outcome KNN predicts SUPER BEAR, BEAR, NEUTRAL, BULL or SUPER BULL. Its horizon H is frozen into each dataset from the frequency-weighted spacing of consecutive opposite BUY/SELL Action pivots. Outcome targets use a forward log-CLOSE regression slope beta, volatility V = ATR_t / Close_t, and M = tanh(beta * sqrt(H) / V). Hard class boundaries are -0.60, -0.20, 0.20 and 0.60.

Action KNN predicts BUY, HOLD or SELL. Its algorithmic targets come from the same fee-aware retrospective Action auto-labeler used by the Human Action Training page. The first and last source candles remain context-only and cannot receive Action labels.

Each KNN has an algorithmic source and an optional Human Training source. Human Outcome Training contributes only to Outcome KNN. Human Action Training contributes only to Action KNN. Human Training never overwrites algorithmic labels. For either KNN, W_H = min(0.60, 0.60 * sqrt(N_H / 750)) and W_A = 1 - W_H. When one source abstains, the supported source receives 100% effective weight for that inference.

The final Server action is not a weighted average of Outcome and Action classes. It comes from the fixed decision matrix: SELL with SUPER BEAR/BEAR/NEUTRAL becomes SELL; SELL with BULL/SUPER BULL becomes HOLD. HOLD always remains HOLD. BUY with SUPER BEAR/BEAR/NEUTRAL becomes HOLD; BUY with BULL/SUPER BULL becomes BUY.

K candidates below 4 are skipped. Old published model versions and old frozen semantic datasets are not reinterpreted and must be rebuilt.

## Retain HOLDs; weight votes instead of deleting examples

Both models retain every eligible distinct example from all available history,
without a history-age or retained-example row cap. `INTELLIGENCE_MAX_MODEL_AGE_DAYS`
controls model expiration only, with a default of 14 days. An hourly expiration
check queues relearning for subscribed selected-period models. Human training checks source integrity, current trainer
authorization, consensus, annotation cutoff and horizon compatibility. Different
technical/full schemas can join after projecting the selected technical keys from
the verified original snapshot; missing technical features exclude that snapshot.

Two human class-weight policies compete during chronological tuning:

* **Natural:** no class-frequency adjustment.
* **Target priors:** training-only class weights `q_c / p_c`, default targets
  BUY 0.25, HOLD 0.50, SELL 0.25.

Each weight multiplies a real neighbor's distance-weighted vote. It does not
fabricate absent classes, duplicate rare examples, or discard HOLDs. These class-prior weights are separate from the dynamic Human Training source weight.
Human Action Training is validated against human annotations; it does not have to
agree with the algorithmic Outcome source.

The first 60% trains policy candidates, the next 20% selects the policy, and the
last 20% is a separate holdout. Outcome horizons are purged at both boundaries.
Natural weighting wins exact tuning ties. Only the selected policy is evaluated on
the holdout; a failure does not promote the runner-up. Class frequencies used for
evaluation come only from its earlier training partition. Publication then refits
that fixed policy on all eligible annotations. Annotations made today make this
retrospective research, not simulated historical live performance. Inference must
occur after the contributing annotations and source outcomes were available.

The report records `samples`, `class_counts`, `knowledge_rows`, `input_keys`,
`weight_candidates`, `weight_policy`, `class_weights`, chronology and provenance.
Human holdout precision is named `directional_annotation_agreement`, not future
return accuracy. Outcome validation stays in top-level `selection` and `holdout`;
no combined holdout performance is claimed. Signal/API `scoring` exposes individual
predictions and configured/effective weights without training vectors. Action Training's UI milestones count each trainer/candle once across revisions, rather
than current model eligibility; historical labels remain stored.

## Deduplication and input revisions

Before constructing KNN knowledge, the trainer audits candle identities. Exact
repeated rows at one decision timestamp are consolidated; conflicting rows at the
same timestamp fail closed. Identical feature vectors or repeated HOLD actions at
different timestamps remain distinct observations. Human Action Training selects
one latest compatible consensus snapshot per decision time. A submitted trainer opinion still has one database
record per snapshot/trainer. Auto-label consecutive-action cleanup is unchanged;
it is not applied as a blanket rewrite of manually submitted labels.

Snapshot compatibility checks stream stored charts in batches of 25; new snapshot
payloads are constructed in batches of 50. Milestone counts remain database-side
aggregations and do not load historical charts into memory.

New snapshot identity hashes the reviewed inputs: chart, feature schema/version,
vector, source digest, horizon and partial-pattern metadata. Later model observations
and revision metadata are excluded from this identity. The independent full-snapshot
checksum still covers the entire stored payload.

Unchanged legacy snapshots are reused, preserving their labels. Changed inputs
create a new immutable snapshot with a predecessor reference and review-required
metadata. No label is copied onto changed inputs. Superseded snapshots and labels
remain available as historical records. A single-candle deletion targets only the
current snapshot; the explicit existing Delete all training action still deletes
that trainer's labels for the market, including historical revisions.

Real provenance-bearing snapshots are checked against current source features and
chart inputs before being used/displayed in Action Training. Source-less legacy
research records retain their prior feature/schema compatibility checks until they
have a known history revision; they are not represented as newly verified source
history. Corrupt snapshot checksums remain fatal.

A repaired chart cannot be combined with a stale dataset feature digest. Open a
fresh dataset after recovery. The previous 422 recovery guidance remains in place
when the old dataset is genuinely incompatible; input revisions are not a bypass
for source validation.

## Repair history and rebuild

On the market intelligence readiness panel, the owner has a **Repair history and
rebuild** link. It opens:

```text
/owner/history-recovery/{market_uuid}
```

The POST action requires an authenticated, verified server owner, CSRF protection,
recent password confirmation and request throttling. Duplicate recent requests are
coalesced under a database row lock. Ordinary users cannot access this operation.
The model's current core/technical/full schema is retained for the requested rebuild.
Custom-schema models are not silently replaced: use their original CLI workflow.

Recovery scans verified hot+cold history using the existing repository reader,
queues bounded exchange repair pages, and uses the existing feature/model pipeline.
No exchange requests or model training run inside the web request. The page shows
tracked source intervals, feature replay stage, history/trained revisions, dataset
replacement, unreviewed snapshot revisions, and the last model validation separately.
Refresh to see updated state; no background browser polling is added.

A successful source insertion records the exact minimum/maximum recovered timestamps
in a transactionally versioned change ledger. Existing closed candles returned as
padding are not silently overwritten by a missing-candle repair. The first dirty
change starts a fixed 30-second debounce, picked up by the existing minute scheduler;
subsequent pages do not continually extend that deadline.

If every revision since the last trained revision has known bounds, feature replay
starts from the earliest actual change using a checkpoint strictly before it. It
replays forward because recursive indicators depend on earlier state. Dataset
rebuilding recomputes outcome windows as well; it is not limited to inserting a
single feature row at the missing timestamp.

Legacy imports/backfills without complete range metadata and explicit rebuilds with
no known changed interval use the full hot+cold history replay. Existing split-job
resumption is retained. A newer revision invalidates an older split chain's lease;
its remaining children cannot publish an obsolete revision as current. Repair and
replay share a per-market history lock; the KNN publication stage also holds the
feature lock. Only the completed revision becomes `trained_revision`.

The repair action can explicitly retry paused/unavailable intervals after the owner
fixes access or restores history. It does not automatically loop forever on those
states. A successful response with no candle is not proof of a zero-trade period.
Existing failure/retry reason codes remain compatible. Verified same-exchange archive
imports/restoration use the existing archive tools; no third-party price substitution,
trade reconstruction provider, or synthetic flat candles are introduced.

Kraken's official OHLC documentation limits the endpoint to its most recent 720
entries and states that older entries cannot be recovered through `since`:
https://docs.kraken.com/api-reference/market-data/get-ohlc-data
The UI explains this limitation; the code does not guess a retention timestamp by
subtracting 720 candle periods, because sparse entries need not be consecutive.

A market with no stored candles needs the normal collector first. A completed repair
may still produce an abstaining model. The report, not the successful job exit code,
is authoritative for validation. Missing human reviews never substitute for failed
model validation or missing source candles.

## CLI and verification

Existing CLI commands remain unchanged:

```sh
php artisan trademinator:candle-gaps
php artisan trademinator:backfill-ohlcv --status
php artisan queue:failed
# Use the actual feed identity and preserve its schema:
php -d memory_limit=512M artisan trademinator:knn-build EXCHANGE SYMBOL PERIOD --schema=SCHEMA
```

Run the regression checks in the application environment:

```sh
php tests/Support/intelligence-recovery-checks.php
php artisan test --filter='IntelligenceRecovery|CandleGuidance|HumanGuidance|HumanTraining|CandleTraining|CandleGapRepair|Backfill'
php artisan test
```

The standalone script exercises 46 domain cases and requires PHP only. New feature
tests cover optional baseline training, actual HOLD retention, holdout independence,
revision range accounting, snapshot reuse/replacement/checksum failure, queue
idempotence, and owner authorization. Application integration tests require the
project's locked Composer dependencies and its PHP extensions.
## Degraded KNN availability

If Action KNN is supported while Outcome KNN is unavailable, its BUY/HOLD/SELL prediction remains unchanged. The Server reports `degraded_action_only`, retaining the Action confidence/evidence and explicitly marking the lack of Outcome confirmation. If only Outcome KNN is supported, the Server returns HOLD with `degraded_outcome_only`. With both KNNs supported, Action BUY + Outcome NEUTRAL is now BUY; BEAR/SUPER BEAR still veto BUY. Client execution still requires a fresh signal and all configured execution safeguards. New scoring uses `outcome-action-matrix-v3`; old recorded snapshots remain immutable.

## Weekly auto-label and horizon provenance

The Monday intelligence build runs Action auto-labeling once over all available history. Model expiration does not restrict this history. That single result has two consumers: finalized BUY/HOLD/SELL labels provide algorithmic Action KNN targets, and consecutive opposite BUY/SELL pivots provide `d` observations for Outcome KNN horizon H. HOLD candles count inside a distance but are never pivot endpoints. Missing candles split the history into independent contiguous runs, so no d crosses a gap.

H is valid only with at least 30 valid distances. Thirty is a minimum, not a sampling cap: every valid d in the available history contributes. The formula is `H = round(sum(d * frequency) / sum(frequency))`. If fewer than 30 distances exist, the build can still train Action KNN; Outcome KNN reports the horizon as unavailable.

Model reports persist the Action label counts, pivot count, d count/distribution/min/mean/median/max, continuity/gap counts, history window, H status/value, and independent Action/Outcome K selections. The OWNER intelligence page displays these diagnostics and formulas without recomputing history.
