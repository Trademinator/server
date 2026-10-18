# Rubix weighted KNN evaluation UI

Existing server KNN uses Rubix ML 3.1 `KNearestNeighbors` with `weighted=true` for voting and Rubix nearest-neighbor search with the reliability-weighted RMS distance kernel. This patch preserves existing training, abstention and cross-exchange missing-feature handling. It **does not** introduce a decision-tree or neural-network tournament, or an extra unvalidated classifier.

Owner pages: `/owner/evaluations` and `/owner/evaluations/{model}`. Trainer pages: `/human-training/evaluations` and `/human-training/evaluations/{model}`. Trainers only see subscribed, active pairs; owner access uses `server-owner` middleware. Model lists expose current published heads; historical model detail IDs require access authorization.

The final Outcome and Action algorithmic holdouts now save the latest 180 annotated predictions to the model report. Charts use the canonical OHLCV repository and existing Lightweight Charts 5; no additional JavaScript dependencies. The trace associates a prediction made at the close of a period with the preceding candle's opening timestamp. Existing model versions do not have these traces and display an empty chart until rebuilt. Trace markers are retrospective predictions, **not orders, fills, profits, or full-sample validation results**. HOLD and abstention are distinct; controls can hide abstentions.

## Deployment

In the production checkout, verify that the working tree is understood before applying the patch. Use `git apply --check /home/dieu/tmp/trademinator-rubix-evaluation-complete.patch` before `git apply`. No database migrations or Composer dependency changes are necessary; Rubix ML 3.1 and Lightweight Charts 5 are already declared in this checkout.

Run `php artisan optimize:clear`, build assets using `npm run build`, then run `php -d memory_limit=512M artisan test` (or the targeted tests first). Rebuild one market as a canary with `php -d memory_limit=512M artisan trademinator:knn-build bitso 'ATOM/USD' 15m --schema=core` after checking the selected schema and the available feature dataset. Evaluate its model and candlestick overlays before a forced rebuild of all markets.

## Known trade-offs

Rubix KNN retains its nearest-neighbor selection and class probability calculations. It may require extra transient memory when preparing historical examples; keeping 512 MB per process does not guarantee very large datasets fit. Holdout traces are capped at 180 rows, and the HTTP chart caps the candle series at 6,000 bars. This patch does not change the existing KNN inference implementation or add a new estimator candidate, so model-version invalidation is unnecessary.
