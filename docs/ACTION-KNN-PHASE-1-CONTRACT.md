# Action KNN phase 1: historical validation versus Client HOLD

Base: Trademinator Server GitHub `main` at `a561f688b4973ee9b5168d05b6059c4264becd95`.

## Two different inputs

- **Action KNN tuning / holdout** evaluates mature, chronological *historical* candles. It compares
  model predictions with a retrospective action label only after that label is available.
  It cannot evaluate an unlabeled, live, provisional soft-closed candle. The holdout report
  identifies its `evaluation_basis` as `finalized_historical_action_labels`.
- **Current Server signal prediction** still uses stored, finalized candle features.
  Its `prediction_input_basis` is `completed_candle`, and its reference price remains
  `closed_candle_close`. This patch **does not** introduce intraperiod ticker inputs or
  reconstruct soft-closed candle features in the Server. A Client-computed soft close
  must not be described as the Server KNN input until there is a separate verified
  feature-input contract and replayable data. Client-local bid/ask data affect only Client execution checks; they do not replace Server KNN feature vectors.

## HOLD and abstention

The Server keeps `reason: supported` for an evidence-backed `hodl` and a diagnostic
reason such as `weak_consensus` or `no_similar_history` for an abstention. Historical
Action holdout reports count these separately:

- `supported_holds`, `correct_holds` and a supported-only `confusion` matrix;
- `abstained` and `abstentions_by_label` (excluded from the supported confusion);
- `classification_errors` for each mistaken historical BUY/SELL/HOLD pairing;
- `opposite_action_predictions` and `directional_predictions_on_hold`, which are
  **historical label disagreements, not proven financial losses**.

The existing `contradiction_rate` gate is deliberately unchanged: it counts semantic
opposite-pivot predictions only. BUY/SELL predictions on historical HOLD are separately
reported, **not** folded into the 5% contradiction threshold. Phase 2 will recalibrate
rare-action gates from observed holdouts instead of silently lowering thresholds here.

The **Client API always represents abstention as `action: hold`**, with
`evidence_status: abstaining` and the original Server reason preserved. A supported
HOLD also has `action: hold`, but `evidence_status: supported`. The read-only GET /markets/{subscription} response preserves the original Server reason
in market.signal.reason; is_fresh, is_current_model, and expires_at_ms allow independent
Client verification. There is no live Server /decision endpoint or Server-side eligibility
verdict. The Client owns all execution checks.

## Verification

```bash
php -l app/Domain/Intelligence/KnnTuner.php
php artisan test tests/Unit/ActionKnnHoldoutAccountingTest.php \
    tests/Unit/KnnTunerTest.php \
    tests/Feature/M5SignalFreshnessTest.php \
    tests/Feature/IntelligenceWorkflowTest.php
```

No database migration or configuration change is required. Historical saved model
reports are not rewritten. Rebuild models to obtain the new accounting diagnostics;
threshold recalibration and a new global readiness version are later work.
