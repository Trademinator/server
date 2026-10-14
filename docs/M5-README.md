# M5 — Client decisions, paper trading and execution reporting

M5 introduces the first authenticated Client contract without moving exchange credentials or order placement onto the Server.

## Security and API keys

The Client API is versioned under `/api/v1/client`. It uses bearer tokens created at **Settings → Client API keys**. New keys are opaque `tmk_…` secrets, stored only as SHA-256 digests with a short prefix for identification. The full secret is shown once. Keys can be labelled, expired and revoked independently; owner suspension/revocation invalidates all active Client keys. Existing legacy UUID API keys are migrated into the hashed key table and continue to authenticate until revoked.

Every Client request re-resolves the authenticated user. Market-specific endpoints also resolve the exact market subscription. A subscription is exchange + symbol specific. New decision requests fail closed when the subscription is inactive. Responses are `Cache-Control: private, no-store`, carry `X-Trademinator-API-Version: 1`, and decision responses have an explicit expiry timestamp.

Environment controls:

```dotenv
CLIENT_API_ENABLED=true
CLIENT_API_RESPONSE_TTL_SECONDS=30
CLIENT_API_STATE_MAX_AGE_SECONDS=30
CLIENT_API_MAX_KEYS=10
CLIENT_PAPER_INITIAL_QUOTE=10000
```

## Questionnaire risk factor

`GET /api/v1/client/risk-factor` returns the authenticated user's capital-allocation
multiplier R on demand. It uses the existing saved questionnaire; no subscription
ID, request body or new database column is needed. No saved questionnaire returns
exactly `0.25`. The versioned multiplicative model naturally produces values in
`[0.01, 1]` without clipping. This endpoint does not enable trading or change the
existing decision/paper-trading services.

See [CLIENT-RISK-FACTOR.md](CLIENT-RISK-FACTOR.md) for the complete request/response
contract, answer mappings, unknown-answer behavior and Client integration rules.

## Market settings

Live/paper mode selection is Client-local. The Server does not enable or disable execution mode for an individual market. An authenticated Client may consume intelligence only for subscriptions owned by that user, and an inactive subscription remains non-actionable.

`PUT /api/v1/client/markets/{subscription}/settings` stores:

- maximum quote value per order
- optional maximum quote-value position exposure
- quote reserve that must remain unused
- maximum spread and taker fee in basis points
- minimum Server evidence confidence
- conflicting-exposure block
- paper starting quote balance
- signal/failure alert preferences and digest cadence

These are Server-side eligibility/risk parameters, not execution-mode switches. They may be edited while a subscription is inactive, but the decision service still rejects an inactive subscription.

## Decision endpoint

`POST /api/v1/client/markets/{subscription}/decision` accepts current Client-side balances and public execution state. Exchange credentials remain local to the Client.

The Server checks, in order:

1. active exact subscription;
2. Server-side Client market risk settings are configured;
3. selected candle period and a recorded Server signal;
4. supported, current-model, non-expired Server signal;
5. configured confidence threshold;
6. freshness of the Client state payload;
7. optional conflicting-exposure block;
8. spread and taker-fee ceilings;
9. available balance, quote reserve, order cap and position cap;
10. optional exchange minimum amount, minimum cost and amount-step constraints.

The response is an **eligibility context**, not an exchange order and not proof the Client acted. A Server HOLD is returned as `server_hold`, not as a Client skip. An abstention remains an abstention.

## Client reports

`POST /api/v1/client/markets/{subscription}/reports` appends immutable, idempotent Client events linked to the original immutable Server signal:

- `pending`
- `acted`
- `skipped`
- `rejected`
- `failed`
- `fill`

Each report has a caller-supplied idempotency key. Reusing that key with different data is rejected. Fill reports can record partial quantity, fill price, fee, fee currency, exchange order ID and exchange trade ID. A non-null exchange trade ID may appear only once for the same user and exact market subscription, even when a retry uses a different idempotency key; this protects the Server audit history from counting the same exchange fill twice without assuming trade IDs are globally unique across exchanges.

Bid/ask, account fee tier, fill quantity and fill price are Client-reported execution facts. The Server validates shape, subscription/signal provenance and directional consistency, but does not replace them with the last OHLCV candle or claim independent exchange verification. No report means **Unknown**. Reports are scoped to their owner and market subscription. Non-protective acted/fill reports must match a supported directional Server signal. When a subscription has become inactive, new trade decisions are blocked, but explicitly marked protective exit/failure reports remain accepted so an existing position can still be closed and audited.

## Paper trading

`POST /api/v1/client/markets/{subscription}/paper` applies the same evidence and risk gates to a Server-owned paper balance. It never changes live execution reports.

The paper account tracks:

- quote and base balance;
- realized quote-denominated fees;
- current equity;
- peak equity and drawdown;
- evaluation and fill sample size;
- observation window;
- a passive buy-and-hold benchmark started at the same first paper observation, including one entry taker fee.

Paper requests remain idempotent per caller-supplied key. The Server also permits at most one **executed** paper action per immutable Server signal and paper account: after a fill, a later evaluation of that same signal with a different idempotency key is recorded as `signal_already_acted` instead of filling again. A skipped evaluation does not consume the signal, so it may be reevaluated while still current if spread or other execution conditions improve. This per-signal execution dedupe is specific to Server-owned paper trading; it does not restrict live Client order retries, partial fills or execution reports.

## Dashboard provenance

The market chart can display three independent marker sets:

- Server observations;
- Action Training labels from the authenticated trainer;
- authenticated Client reports.

Client acted reports and actual fills are styled separately. Fill markers include the reported fill price. The signal journal shows the Client event history linked to each original Server signal; it never rewrites a Server signal because a later model or Client report changed.

## Deployment

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:list --path=api/v1/client
php artisan test --compact
node --test tests/Frontend/*.test.mjs
```

The migration preserves existing legacy API credentials by hashing them into `client_api_keys` before clearing the plaintext `users.api_key` values. No exchange credential is migrated or stored.
