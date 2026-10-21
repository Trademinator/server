# Trademinator Server Client API

This document describes the current authenticated API exposed by Trademinator Server for Trademinator Client and other authorized integrations.

The Client API is versioned under `/api/v1/client`.

> **Live trading state stays on the Client.** The Server exposes intelligence, configuration, questionnaire-derived risk information, optional execution reporting, and an independent synthetic paper simulator. It does not receive live wallet balances or positions for intelligence retrieval, does not calculate live order eligibility or sizing, and does not place exchange orders.

The former live `/decision` endpoint has been removed.

---

## Base URL

All Client API v1 routes are under:

```text
/api/v1/client
```

Staging:

```text
https://server.trademinator.com/api/v1/client
```

Use HTTPS outside local development.

## Current endpoints

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/coingecko-mappings` | Read shared CoinGecko identities by exchange and market. |
| `GET` | `/risk-factor` | Return the authenticated user's questionnaire-derived Risk Factor `R`. |
| `GET` | `/markets` | List the authenticated user's market subscriptions. |
| `GET` | `/markets/{subscription}` | Return one subscription, Client configuration, and the latest Server intelligence signal. |
| `PUT` | `/markets/{subscription}/settings` | Store Client configuration/preferences for one subscription. |
| `GET` | `/markets/{subscription}/reports` | Return the latest Client execution reports for one subscription. |
| `POST` | `/markets/{subscription}/reports` | Append an immutable Client execution/audit report. |
| `GET` | `/markets/{subscription}/paper` | Return the Server-owned synthetic paper account. |
| `POST` | `/markets/{subscription}/paper` | Evaluate and simulate the current signal using the Server-owned paper account. |

The following route is retired and is not registered:

```text
POST /api/v1/client/markets/{subscription}/decision
```

It returns HTTP `404`.

Do not send live wallet balances, positions, managed quantities, live order-book snapshots, account fee tiers, or exchange constraints to a `/decision` route. Live execution checks are Client-local.

---

# Authentication

## Client API keys

Create keys in the Server web interface under:

**Settings → Client API keys**

New keys begin with:

```text
tmk_
```

The full secret is shown only once. The Server stores only a SHA-256 digest plus a short identifying prefix.

Keys may be labelled, expired, and revoked independently. By default a user may have up to:

```dotenv
CLIENT_API_MAX_KEYS=10
```

active keys.

Migrated legacy UUID bearer keys remain accepted until revoked or expired. New integrations should use `tmk_` keys.

## Request headers

```http
Authorization: Bearer tmk_...
Accept: application/json
```

For JSON bodies:

```http
Content-Type: application/json
```

Example shell setup:

```bash
export TRADEMINATOR_URL='https://server.trademinator.com'
export TRADEMINATOR_API_KEY='tmk_REPLACE_WITH_REAL_KEY'
```

Example request:

```bash
curl --fail-with-body --silent --show-error \
  "$TRADEMINATOR_URL/api/v1/client/markets" \
  -H "Authorization: Bearer $TRADEMINATOR_API_KEY" \
  -H 'Accept: application/json'
```

The bearer key determines the Trademinator user. Client API calls do not accept a caller-supplied `user_id`.

The key must belong to an existing, non-suspended user with a verified email address. The key itself must be active, unexpired, and not revoked.

Successful authenticated responses are private/non-cacheable and include:

```text
Cache-Control: private, no-store
X-Trademinator-API-Version: 1
```

Most successful response bodies also contain:

```json
{
  "api_version": 1
}
```

---

# Rate limits

The Client API group has a general authenticated limit of `120` requests per minute.

| Endpoint | Effective limit |
| --- | ---: |
| `GET /risk-factor` | 120/minute |
| `GET /markets` | 120/minute |
| `GET /markets/{subscription}` | 120/minute |
| `PUT /markets/{subscription}/settings` | 30/minute |
| `GET /markets/{subscription}/reports` | 120/minute |
| `POST /markets/{subscription}/reports` | 120/minute |
| `GET /markets/{subscription}/paper` | 120/minute |
| `POST /markets/{subscription}/paper` | 60/minute |

Failed authentication attempts are separately limited by source IP to:

- 30 per minute;
- 300 per hour.

Authentication rate-limit responses include `Retry-After`.

---

# Trust and privacy boundary

## Live intelligence

The live intelligence endpoint is:

```text
GET /api/v1/client/markets/{subscription}
```

It accepts no wallet, position, live-order, account-fee-tier, or exchange-constraint payload.

The Server returns recorded intelligence and provenance. The Client decides whether that intelligence can be acted upon.

The Client remains responsible for:

- actual exchange balances;
- pending and open orders;
- one-open-order-per-market enforcement;
- Client-local market lifecycle state;
- Risk Factor application;
- Comptability;
- BUY sizing;
- SELL profitability;
- fees and spread;
- exchange minimum amount/cost and precision;
- amount steps;
- dust and dedusting;
- reconciliation with the exchange;
- stale local state;
- all live order placement and cancellation.

## Execution reports

Execution reports are optional audit facts sent after Client decisions or exchange activity. They can contain quantities, fill prices, fees, and exchange IDs, but do not make the Server authoritative for the exchange account.

## Paper trading

The paper API is a separate Server-owned simulation. It uses synthetic balances owned by the Server paper account together with Client-supplied top-of-book prices and a fee assumption. It does not use the Client's real wallet balances and does not authorize live exchange orders.

---

# Recommended live Client flow

A live Client can use the Server approximately as follows:

1. Authenticate with a Client API key.
2. Fetch `GET /risk-factor`.
3. Fetch `GET /markets`.
4. Fetch `GET /markets/{subscription}` for each market being managed.
5. Inspect the subscription `active` flag and current `signal`.
6. Reject absent, stale, superseded, or otherwise unacceptable signals locally.
7. Apply all Client-side risk, Comptability, exchange, balance, fee, order, and reconciliation rules.
8. Interact with the exchange locally.
9. Optionally report execution/audit events through `/reports`.

There is no live Server eligibility verdict. A Server signal is advice/recorded intelligence, not an order authorization.

---

# `GET /risk-factor`

Returns the authenticated user's questionnaire-derived capital-allocation multiplier `R`.

No subscription or request body is required.

```bash
curl --fail-with-body --silent --show-error \
  "$TRADEMINATOR_URL/api/v1/client/risk-factor" \
  -H "Authorization: Bearer $TRADEMINATOR_API_KEY" \
  -H 'Accept: application/json'
```

When no saved questionnaire exists, the default is exactly `0.25`:

```json
{
  "api_version": 1,
  "risk_factor": "0.2500000000000000",
  "algorithm_version": "questionnaire-risk-v1",
  "source": "default",
  "restraint_score": null,
  "components": [],
  "unknown_fields": [],
  "questionnaire_updated_at": null,
  "calculated_at": "2026-10-16T06:00:00.000000Z"
}
```

Important rules:

- `risk_factor` is a decimal string;
- valid output is in `[0.01, 1]`;
- no saved questionnaire produces exactly `0.25`;
- network, HTTP, JSON, encryption, or calculation failures must **not** be converted into an implicit `0.25` success;
- the endpoint reads the currently saved questionnaire on each request;
- it does not accept an `R` override or questionnaire answers from the Client.

The agreed Client sizing relationship remains:

```text
Qrisk = Qdistributed_capital × R
Qbuy  = min(Q3bar, Qrisk)
```

For the complete risk algorithm, see `docs/CLIENT-RISK-FACTOR.md`.

---

# `GET /markets`

Lists all subscriptions owned by the authenticated user, including inactive subscriptions.

```bash
curl --fail-with-body --silent --show-error \
  "$TRADEMINATOR_URL/api/v1/client/markets" \
  -H "Authorization: Bearer $TRADEMINATOR_API_KEY" \
  -H 'Accept: application/json'
```

Representative response:

```json
{
  "api_version": 1,
  "markets": [
    {
      "subscription_id": "01a00000-0000-7000-8000-000000000001",
      "active": true,
      "exchange": {
        "class": "bitso",
        "name": "Bitso"
      },
      "symbol": "ATOM/USD",
      "period": "1h",
      "latest_signal_id": "01a00000-0000-7000-8000-000000000002"
    }
  ]
}
```

| Field | Meaning |
| --- | --- |
| `subscription_id` | UUID used by the market-specific Client API routes. |
| `active` | Whether the Server subscription is active. An inactive subscription must not initiate new Client trades. |
| `exchange.class` | Internal exchange/CCXT-style identifier. |
| `exchange.name` | Human-readable exchange name. |
| `symbol` | Exact market symbol. |
| `period` | Selected candle period or `null`. |
| `latest_signal_id` | Latest recorded signal UUID or `null`. |

A subscription is exchange + market specific. Do not identify a market only by symbol.

The Server does not expose Client-local `trading_enabled` or `paper_enabled` switches.

---

# `GET /markets/{subscription}`

Returns the owned market subscription, selected candle period, stored Client configuration, and latest recorded Server intelligence.

```bash
export SUBSCRIPTION_ID='01a00000-0000-7000-8000-000000000001'

curl --fail-with-body --silent --show-error \
  "$TRADEMINATOR_URL/api/v1/client/markets/$SUBSCRIPTION_ID" \
  -H "Authorization: Bearer $TRADEMINATOR_API_KEY" \
  -H 'Accept: application/json'
```

Representative response:

```json
{
  "api_version": 1,
  "market": {
    "subscription_id": "01a00000-0000-7000-8000-000000000001",
    "active": true,
    "exchange": {
      "class": "bitso",
      "name": "Bitso"
    },
    "symbol": "ATOM/USD",
    "tick_size": "0.001",
    "period": "1h",
    "settings": {
      "max_order_quote": "100.000000000000000000",
      "max_position_quote": null,
      "reserve_quote": "0.000000000000000000",
      "max_spread_bps": "100.00000000",
      "max_taker_fee_bps": "100.00000000",
      "max_signal_drift_bps": "100",
      "min_signal_confidence": "0.60000000",
      "block_conflicting_exposure": true,
      "paper_initial_quote": "10000.000000000000000000",
      "paper_slippage_bps": "10",
      "alerts": {
        "signal_change": false,
        "execution_failure": false,
        "digest_frequency": "off"
      }
    },
    "signal": {
      "id": "01a00000-0000-7000-8000-000000000002",
      "action": "buy",
      "action_meaning": "supported_buy_by_outcome_action_matrix",
      "reason": "supported",
      "evidence_status": "supported",
      "period": "1h",
      "model_id": "01a00000-0000-7000-8000-000000000003",
      "is_current_model": true,
      "is_fresh": true,
      "expires_at_ms": 1792137600000,
      "confidence": 0.82,
      "recorded_at_ms": 1792130400000,
      "decision_at_ms": 1792128600000,
      "reference_price": "2.08",
      "reference_price_source": "closed_candle_close",
      "prediction_input_basis": "completed_candle",
      "scoring": {
        "version": "outcome-action-matrix-v3"
      }
    }
  }
}
```

`signal` is `null` when no Server signal has been recorded.

## Signal fields

| Field | Meaning |
| --- | --- |
| `id` | Immutable Server signal UUID. |
| `action` | Client-facing `buy`, `sell`, or `hold`. |
| `action_meaning` | Optional machine-readable explanation of the directional result. |
| `reason` | Original Server reason. Preserve this field. |
| `evidence_status` | `supported`, `degraded`, or `abstaining`. |
| `period` | Candle period used by the recorded signal. |
| `model_id` | Model that produced the signal. |
| `is_current_model` | Whether that model is still the current published model for the selected period. |
| `is_fresh` | Whether the Server considers this signal fresh for the selected period. |
| `expires_at_ms` | Evidence-expiry timestamp in Unix milliseconds or `null`. |
| `confidence` | Confidence for `supported` and `degraded_action_only`; otherwise `null`. |
| `recorded_at_ms` | When the Server recorded the immutable signal. |
| `decision_at_ms` | Candle decision timestamp. |
| `reference_price` | Stored signal reference price, when available. |
| `reference_price_source` | Provenance of the reference price. |
| `prediction_input_basis` | Provenance of the feature/input basis. |
| `scoring` | Optional versioned model/scoring provenance. |

## Freshness

Live signal expiry comes from intelligence freshness, not the retired live decision-response TTL.

Default controls are:

```dotenv
INTELLIGENCE_MAX_SIGNAL_AGE_PERIODS=2
INTELLIGENCE_MAX_SIGNAL_AGE_SECONDS=86400
```

The Server calculates both a candle-period expiry and a wall-clock expiry. The earlier value wins.

If the currently selected period no longer matches the recorded signal period, `is_fresh` becomes `false`.

The Client should still compare its local clock with `expires_at_ms` before acting.

## Current model

`is_current_model=true` means the signal's `model_id` is still the current intelligence head for the exact:

```text
exchange + symbol + selected period
```

Recorded signals remain immutable when a newer model is later published.

## HOLD, abstention, and degraded evidence

The Client API deliberately separates `action` from evidence quality.

| Original evidence | API action | `evidence_status` |
| --- | --- | --- |
| `reason=supported`, BUY | `buy` | `supported` |
| `reason=supported`, SELL | `sell` | `supported` |
| `reason=supported`, HODL | `hold` | `supported` |
| `reason=degraded_action_only`, BUY | `buy` | `degraded` |
| `reason=degraded_action_only`, SELL | `sell` | `degraded` |
| `reason=degraded_action_only`, HODL | `hold` | `degraded` |
| Any other abstention reason | `hold` | `abstaining` |

Therefore `action=hold` alone does not explain why there is no directional action. Always inspect `reason` and `evidence_status`.

A degraded Action-only directional signal may look like:

```json
{
  "action": "buy",
  "reason": "degraded_action_only",
  "evidence_status": "degraded",
  "confidence": 0.82
}
```

Outcome unavailability does not automatically overwrite supported Action KNN BUY/SELL evidence with HOLD.

This is still not a live order authorization.

## Suggested Client-side directional gate

At minimum, a live Client should normally require:

```text
market.active == true
signal != null
signal.action in {buy, sell}
signal.evidence_status in {supported, degraded}
signal.is_fresh == true
signal.is_current_model == true
now_ms < signal.expires_at_ms
```

Then apply all Client-local Comptability, risk, fee, balance, open-order, exchange-minimum, and reconciliation rules.

This is a Client policy example, not a Server eligibility endpoint.

---

# `PUT /markets/{subscription}/settings`

Stores configuration/preferences for one owned subscription. These values may be consumed by the Client and by the independent paper simulator, but they do not authorize live trading.

Settings may be changed while the subscription is inactive; doing so does not activate it.

## Required fields

| Field | Validation |
| --- | --- |
| `max_order_quote` | number `> 0` |
| `max_position_quote` | `null` or number `> 0` |
| `reserve_quote` | number `>= 0` |
| `max_spread_bps` | number `> 0`, `<= 10000` |
| `max_taker_fee_bps` | number `>= 0`, `<= 10000` |
| `min_signal_confidence` | number in `[0,1]` |
| `block_conflicting_exposure` | boolean |
| `paper_initial_quote` | number `> 0` |
| `alert_on_signal_change` | boolean |
| `alert_on_execution_failure` | boolean |
| `digest_frequency` | `off`, `daily`, or `weekly` |

Optional:

| Field | Validation |
| --- | --- |
| `max_signal_drift_bps` | number `> 0`, `<= 10000` |
| `paper_slippage_bps` | number `>= 0`, `< 10000` |

Example:

```bash
curl --fail-with-body --silent --show-error \
  -X PUT "$TRADEMINATOR_URL/api/v1/client/markets/$SUBSCRIPTION_ID/settings" \
  -H "Authorization: Bearer $TRADEMINATOR_API_KEY" \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  --data '{
    "max_order_quote": 100,
    "max_position_quote": 500,
    "reserve_quote": 25,
    "max_spread_bps": 50,
    "max_taker_fee_bps": 50,
    "max_signal_drift_bps": 100,
    "min_signal_confidence": 0.60,
    "block_conflicting_exposure": true,
    "paper_initial_quote": 10000,
    "paper_slippage_bps": 10,
    "alert_on_signal_change": false,
    "alert_on_execution_failure": true,
    "digest_frequency": "off"
  }'
```

The response contains `api_version` and the normalized `settings` object.

---

# Retired live `POST /decision`

The former endpoint:

```text
POST /api/v1/client/markets/{subscription}/decision
```

has been removed and returns `404`.

The live Client must not send this route:

- wallet balances;
- managed wallet quantities;
- live position value;
- order-book snapshots for authorization;
- account fee tier for authorization;
- exchange minimums for authorization;
- requested live order sizing.

`GET /markets/{subscription}` now provides the read-only intelligence that the live Client needs. Live sizing and execution eligibility stay entirely Client-local.

---

# `GET /markets/{subscription}/reports`

Returns the latest `100` Client execution reports for the authenticated user and exact subscription, ordered by `occurred_at_ms` descending.

```bash
curl --fail-with-body --silent --show-error \
  "$TRADEMINATOR_URL/api/v1/client/markets/$SUBSCRIPTION_ID/reports" \
  -H "Authorization: Bearer $TRADEMINATOR_API_KEY" \
  -H 'Accept: application/json'
```

Representative report:

```json
{
  "id": "01a00000-0000-7000-8000-000000000010",
  "signal_id": "01a00000-0000-7000-8000-000000000002",
  "event": "fill",
  "side": "buy",
  "reason": null,
  "protective": false,
  "quantity": "4.800000000000000000",
  "price": "2.081000000000000000",
  "fee": "0.006500000000000000",
  "fee_currency": "USD",
  "exchange_order_id": "exchange-order-123",
  "exchange_trade_id": "exchange-trade-456",
  "occurred_at_ms": 1792130400000,
  "recorded_at_ms": 1792130400123
}
```

These are Client-reported execution facts. The Server validates shape and provenance but does not independently verify them against the exchange.

No report means the Server does not know what the Client or exchange did.

---

# `POST /markets/{subscription}/reports`

Appends an immutable Client execution/audit event linked to an existing Server signal from the same market.

Supported event values:

```text
pending
acted
skipped
rejected
failed
fill
```

## Request fields

| Field | Requirement |
| --- | --- |
| `idempotency_key` | Required string, 8–96 characters. |
| `signal_id` | Required UUID from this market. |
| `event` | Required supported event. |
| `side` | Optional `buy`/`sell`; required for `acted` and `fill`. |
| `reason` | Optional machine-readable string; required for `skipped`, `rejected`, and `failed`. |
| `protective` | Optional boolean; defaults to `false`. |
| `quantity` | Positive number; required for `fill`. |
| `price` | Positive number; required for `fill`. |
| `fee` | Optional number `>= 0`. |
| `fee_currency` | Optional uppercase asset/currency identifier, maximum 16 characters. |
| `exchange_order_id` | Optional string, maximum 128 characters. |
| `exchange_trade_id` | Optional string, maximum 128 characters. |
| `occurred_at_ms` | Required positive Unix-millisecond timestamp. |

Example:

```bash
NOW_MS=$(date +%s%3N)
SIGNAL_ID='01a00000-0000-7000-8000-000000000002'

curl --fail-with-body --silent --show-error \
  -X POST "$TRADEMINATOR_URL/api/v1/client/markets/$SUBSCRIPTION_ID/reports" \
  -H "Authorization: Bearer $TRADEMINATOR_API_KEY" \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  --data "{
    \"idempotency_key\": \"fill-$NOW_MS\",
    \"signal_id\": \"$SIGNAL_ID\",
    \"event\": \"fill\",
    \"side\": \"buy\",
    \"quantity\": 4.8,
    \"price\": 2.081,
    \"fee\": 0.0065,
    \"fee_currency\": \"USD\",
    \"exchange_order_id\": \"exchange-order-123\",
    \"exchange_trade_id\": \"exchange-trade-456\",
    \"occurred_at_ms\": $NOW_MS
  }"
```

Successful reports return HTTP `201` with `api_version` and `report`.

## Validation rules

For `acted` and `fill`, `side` is required.

For `skipped`, `rejected`, and `failed`, `reason` is required.

For `fill`, both `quantity` and `price` are required.

The referenced `signal_id` must belong to this market.

## Non-protective directional reports

For a non-protective `acted` or `fill`, the current controller requires:

```text
signal.reason == supported
signal.action in {buy, sell}
reported side == signal.action
```

Otherwise it returns `signal_action_mismatch`.

### Current degraded-signal reporting limitation

The live intelligence API can expose `degraded_action_only` BUY/SELL signals. However, the current report controller accepts non-protective `acted`/`fill` only when the original signal reason is exactly `supported`.

Therefore a non-protective report for a `degraded_action_only` signal currently fails with `signal_action_mismatch`.

This is a **reporting constraint in the current implementation**, not a conversion of the live degraded signal to HOLD.

## Inactive subscriptions and protective reports

When a subscription is inactive, ordinary new trade-decision reports are rejected.

Explicitly protective reports are still accepted for:

- `fill`;
- `failed`;
- `rejected`.

This allows an already-open protective exit/failure path to be audited.

Use:

```json
{
  "protective": true
}
```

only for that purpose.

## Idempotency

The caller supplies `idempotency_key`.

- Same key + same canonical payload: the existing report is returned again.
- Same key + different payload: HTTP `409`.
- The same non-null `exchange_trade_id` cannot be reported twice for the same user and subscription, even with another idempotency key.

Duplicate exchange trades return `duplicate_exchange_trade_id`.

---

# `GET /markets/{subscription}/paper`

Returns the current Server-owned synthetic paper account.

If no paper account exists:

```json
{
  "api_version": 1,
  "paper": null
}
```

An optional `best_bid` query parameter may be used for current valuation:

```bash
curl --fail-with-body --silent --show-error \
  "$TRADEMINATOR_URL/api/v1/client/markets/$SUBSCRIPTION_ID/paper?best_bid=2.08" \
  -H "Authorization: Bearer $TRADEMINATOR_API_KEY" \
  -H 'Accept: application/json'
```

The paper summary contains Server-owned synthetic quote/base balances, equity, fees, return, peak equity, drawdown, valuation provenance, passive buy-and-hold benchmark data, sample size, and the observation window.

This endpoint reads synthetic paper state only; it does not read the Client's real exchange account.

---

# `POST /markets/{subscription}/paper`

Evaluates the current Server signal using the Server-owned synthetic paper account and, when eligible, simulates a fill.

This is **not** the retired live `/decision` endpoint and does not authorize or place a live order.

The subscription must be active.

## Required fields

| Field | Validation |
| --- | --- |
| `idempotency_key` | string, 8–96 characters |
| `reported_at_ms` | positive integer |
| `best_bid` | number `> 0` |
| `best_ask` | number `> 0` and `>= best_bid` |
| `taker_fee_bps` | number in `[0,10000]` |

Optional:

| Field | Validation | Meaning |
| --- | --- | --- |
| `fee_asset` | `quote` or `base` | Synthetic fee asset; defaults to `quote`. |
| `requested_quote` | number `> 0` | Optional paper sizing cap. |
| `minimum_amount` | number `> 0` | Simulated exchange minimum amount. |
| `minimum_cost` | number `> 0` | Simulated exchange minimum notional/cost. |
| `amount_step` | number `> 0` | Simulated amount step. |

Example:

```bash
NOW_MS=$(date +%s%3N)

curl --fail-with-body --silent --show-error \
  -X POST "$TRADEMINATOR_URL/api/v1/client/markets/$SUBSCRIPTION_ID/paper" \
  -H "Authorization: Bearer $TRADEMINATOR_API_KEY" \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  --data "{
    \"idempotency_key\": \"paper-$NOW_MS\",
    \"reported_at_ms\": $NOW_MS,
    \"best_bid\": 2.08,
    \"best_ask\": 2.081,
    \"taker_fee_bps\": 65,
    \"fee_asset\": \"quote\",
    \"minimum_amount\": 0.001,
    \"minimum_cost\": 1,
    \"amount_step\": 0.001
  }"
```

The paper engine internally supplies its own synthetic `quote_balance`, `base_balance`, and position value. The request does not accept the Client's real wallet balances.

A successful evaluation returns HTTP `200` with top-level `event` normally equal to `executed` or `skipped`.

An executed response contains:

- signal ID;
- side;
- synthetic quantity and price;
- simulated fees;
- execution assumptions;
- nested paper-decision diagnostics;
- updated paper-account summary.

## Paper-only eligibility

The nested paper decision service checks items such as:

- active subscription;
- configured market settings;
- selected candle period;
- recorded signal;
- signal freshness;
- current model;
- supported/degraded Action-only evidence;
- confidence threshold;
- age of `reported_at_ms`;
- spread;
- reference-price drift;
- taker fee;
- synthetic balance/reserve/position limits;
- optional minimum amount/cost/step.

Those checks belong only to the Server-owned paper simulator. They are not the live Client execution contract.

## Paper idempotency

- Same idempotency key + identical input replays the original result.
- Reusing the key with different input returns `409`.
- One immutable Server signal can execute at most once for one paper account.
- A later evaluation of an already-executed signal returns `event=skipped` and `reason=signal_already_acted`.
- A skipped paper evaluation does not consume the signal and may be reevaluated while it remains current.

---

# Error handling

Clients should branch primarily on HTTP status. Do not assume every error uses the same JSON structure.

Explicit API errors commonly use:

```json
{
  "error": {
    "code": "machine_readable_code",
    "message": "Human-readable explanation."
  }
}
```

Laravel validation and generic HTTP exceptions may instead use Laravel's normal `message` / `errors` structure.

| Status | Typical meaning |
| ---: | --- |
| `200` | Successful read/update/paper evaluation. |
| `201` | Execution report accepted or replayed idempotently. |
| `401` | Missing, malformed, invalid, expired, or revoked API key. |
| `403` | Account unavailable, suspended, or unverified. |
| `404` | Unknown/unowned subscription, or an unregistered retired route such as live `/decision`. |
| `409` | Inactive-subscription operation, idempotency conflict, duplicate trade, or paper conflict. |
| `422` | Validation/provenance failure, signal mismatch, invalid order book, missing fill fields, etc. |
| `429` | Rate limit reached. |
| `503` | Client API disabled or another fail-closed profile/service condition. |

Explicit error codes currently include examples such as:

```text
client_api_disabled
unauthenticated
authentication_rate_limited
account_unavailable
risk_profile_unavailable
invalid_order_book
signal_not_found
subscription_inactive
side_required
reason_required
fill_details_required
signal_action_mismatch
duplicate_exchange_trade_id
```

Fail closed on transport errors, TLS errors, JSON parse failures, unexpected API versions, malformed required fields, stale/missing signal provenance, or unknown response structures that affect trading safety.

---

# Decimal precision

Many financial values are returned as decimal strings, for example:

```json
{
  "quantity": "48.053820278712157616",
  "price": "2.081000000000000000"
}
```

Use arbitrary-precision decimal arithmetic when preserving money and quantity values. Do not unnecessarily round-trip them through binary floating point.

Some diagnostic values such as confidence and percentages may be JSON numbers.

---

# Timestamps

Fields ending in `_at_ms` are Unix timestamps in milliseconds, including:

```text
decision_at_ms
recorded_at_ms
expires_at_ms
occurred_at_ms
reported_at_ms
```

The Risk Factor endpoint additionally returns ISO-8601 UTC timestamps such as `questionnaire_updated_at` and `calculated_at`.

---

# Relevant Server configuration

Client API configuration:

```dotenv
CLIENT_API_ENABLED=true
CLIENT_API_RESPONSE_TTL_SECONDS=30
CLIENT_API_STATE_MAX_AGE_SECONDS=30
CLIENT_API_MAX_KEYS=10
CLIENT_PAPER_INITIAL_QUOTE=10000
CLIENT_DEFAULT_PAPER_SLIPPAGE_BPS=10
CLIENT_DEFAULT_MAX_SIGNAL_DRIFT_BPS=100
```

Important distinction:

- `CLIENT_API_RESPONSE_TTL_SECONDS` currently applies to the independent paper decision response;
- `CLIENT_API_STATE_MAX_AGE_SECONDS` currently applies to paper request state freshness;
- live `GET /markets/{subscription}` signal freshness comes from:

```dotenv
INTELLIGENCE_MAX_SIGNAL_AGE_PERIODS=2
INTELLIGENCE_MAX_SIGNAL_AGE_SECONDS=86400
```

Verify installed Client routes with:

```bash
php artisan route:list --path=api/v1/client
```

---

# Security recommendations

- Keep Client API keys out of source code.
- Never put a bearer token in a URL or query parameter.
- Avoid logging full bearer tokens.
- Prefer one revocable key per Client installation.
- Revoke a key when an installation is retired or a secret may have leaked.
- Require HTTPS outside local development.
- Treat `404` from the retired `/decision` route as a modern-Client programming/configuration error.
- Do not send real wallet balances to the Server for intelligence retrieval.
- Do not treat a Server signal as proof that an order is safe or profitable.
- Keep exchange credentials exclusively on the Client.
- Treat the exchange as authoritative for actual balances, orders, trades, and fills.

---

# What the Server Client API does not do

The current live Client API does **not**:

- store or manage Client exchange API credentials;
- connect to a user's private exchange account for live decision making;
- receive live wallet balances for signal retrieval;
- receive live positions for signal retrieval;
- calculate live order eligibility;
- calculate authoritative live BUY/SELL sizing;
- select live versus paper mode for the Client;
- place or cancel live exchange orders;
- reconcile live exchange state;
- implement Client Comptability;
- implement Client-local market lifecycle state;
- replace exchange minimum/precision checks;
- replace Client-side risk controls.

The Server's live responsibility is to publish authenticated, subscription-scoped intelligence and configuration.

The Client remains authoritative for local execution logic.

The exchange remains authoritative for actual exchange state.

---

# Related documentation

See also:

```text
docs/CLIENT-RISK-FACTOR.md
docs/M5-README.md
docs/ACTION-KNN-PHASE-1-CONTRACT.md
```

`docs/API.md` should remain the integration-oriented reference. Detailed internal algorithm documentation should remain in subsystem-specific documents rather than being required to consume the API.


## Shared CoinGecko mappings

`GET /api/v1/client/coingecko-mappings` uses the existing Client bearer key,
account checks and 120-per-minute throttle. It returns public market metadata
from `coin_gecko_market_mappings`, including owner corrections. The catalogue
is shared across authenticated users and includes unsubscribed markets because
Client accounting may need to value deposits outside active subscriptions.
No user subscriptions, exchange configuration, provider credentials or diagnostic
error text are returned. Reading the endpoint does not resolve mappings or call
CoinGecko.

```json
{
  "api_version": 1,
  "mappings": [
    {
      "mapping_id": "00000000-0000-4000-8000-000000000001",
      "exchange": "bitso",
      "symbol": "ATOM/USD",
      "base_symbol": "ATOM",
      "vs_currency": "usd",
      "status": "resolved",
      "coin_id": "cosmos",
      "manually_mapped": true,
      "updated_at": "2026-10-21T00:00:00.000000Z"
    }
  ],
  "next_cursor": null
}
```

Pages contain at most 250 rows ordered by mapping UUID. If `next_cursor` is not
null, request `?after=<next_cursor>` until it is null. Invalid cursors return 422.
An empty catalogue is a successful response. Each page reads current data; pages
are not a database snapshot across requests. Repeated refreshes pick up edits.

Only `status: resolved` can supply an ID. Pending, ambiguous, unmapped and
unsupported rows return `coin_id: null`, even if an older ID remains in storage.
The Client must match the exact exchange and spot pair and replace its catalogue
after a complete refresh, never merge it with local ticker overrides. Responses
have `Cache-Control: private, no-store`. The Client refreshes its short-lived
in-memory catalogue every five minutes and discards it on failure or expiry.
No new database migration or configuration key is needed for this endpoint.
