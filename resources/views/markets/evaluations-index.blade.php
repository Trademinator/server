<x-layouts.app title="Model evaluations">
    @include('markets.guide-styles')
    <style>
        /* Scoped to the evaluations listing: do not change other market tables. */
        .evaluation-list .review-table-wrap { width: 100%; }
        .evaluation-list .evaluation-table {
            width: 100%;
            table-layout: fixed;
            border-collapse: collapse;
        }
        .evaluation-list .evaluation-table th,
        .evaluation-list .evaluation-table td {
            padding: 12px 14px;
            vertical-align: middle;
            text-align: left;
            overflow-wrap: anywhere;
        }
        .evaluation-list .evaluation-table tbody tr + tr {
            border-top: 1px solid #d0d9e5;
        }
        .dark .evaluation-list .evaluation-table tbody tr + tr {
            border-top-color: #354357;
        }
        .evaluation-list .evaluation-table .evaluation-metric {
            text-align: center;
            font-variant-numeric: tabular-nums;
        }
        .evaluation-list .evaluation-table .evaluation-action {
            text-align: center;
        }
        @media (max-width: 767px) {
            .evaluation-list .review-table-wrap { overflow-x: visible; }
            .evaluation-list .evaluation-table,
            .evaluation-list .evaluation-table tbody,
            .evaluation-list .evaluation-table tr,
            .evaluation-list .evaluation-table td { display: block; width: 100%; }
            .evaluation-list .evaluation-table thead { display: none; }
            .evaluation-list .evaluation-table tbody tr {
                padding: 12px 0;
                border-bottom: 1px solid #d0d9e5;
            }
            .dark .evaluation-list .evaluation-table tbody tr { border-bottom-color: #354357; }
            .evaluation-list .evaluation-table tbody tr + tr { border-top: 0; }
            .evaluation-list .evaluation-table td {
                display: grid;
                grid-template-columns: minmax(0, 44%) minmax(0, 56%);
                gap: 12px;
                padding: 6px 2px;
                text-align: left;
            }
            .evaluation-list .evaluation-table td::before {
                content: attr(data-label);
                font-weight: 700;
            }
            .evaluation-list .evaluation-table td.evaluation-metric,
            .evaluation-list .evaluation-table td.evaluation-action { text-align: left; }
            .evaluation-list .evaluation-table td.evaluation-metric .evaluation-value {
                display: block;
                text-align: center;
            }
            .evaluation-list .evaluation-table .evaluation-empty { display: block; }
            .evaluation-list .evaluation-table .evaluation-empty::before { content: none; }
        }
    </style>
    <section class="pair-guide pair-review evaluation-list">
        <header class="guide-hero"><h1>Model evaluations</h1>
            <p>Current published models · retrospective chronological holdouts, not live trades or profitability.</p>
        </header>
        <div class="guide-panel">
            <div class="review-table-wrap"><table class="evaluation-table">
                <colgroup>
                    <col style="width: 26%">
                    <col style="width: 22%">
                    <col style="width: 17%">
                    <col style="width: 21%">
                    <col style="width: 14%">
                </colgroup>
                <thead><tr><th>Market</th><th>Engine</th><th class="evaluation-metric">Outcome macro F1</th><th class="evaluation-metric">Action directional precision</th><th class="evaluation-action">Evaluation</th></tr></thead>
                <tbody>
                    @forelse($models as $item)
                        @php($r = $item['report'])
                        <tr>
                            <td data-label="Market">{{ $r['exchange'] ?? '—' }} · {{ $r['symbol'] ?? '—' }} · {{ $r['period'] ?? '—' }}</td>
                            <td data-label="Engine">{{ $r['knn_engine'] ?? 'Legacy / unspecified' }}</td>
                            <td class="evaluation-metric" data-label="Outcome macro F1"><span class="evaluation-value">{{ isset($r['outcome']['algorithmic']['holdout']['macro_f1']) ? number_format(100 * $r['outcome']['algorithmic']['holdout']['macro_f1'], 1).'%' : '—' }}</span></td>
                            <td class="evaluation-metric" data-label="Action directional precision"><span class="evaluation-value">{{ isset($r['action']['algorithmic']['holdout']['semantic_precision']) ? number_format(100 * $r['action']['algorithmic']['holdout']['semantic_precision'], 1).'%' : '—' }}</span></td>
                            <td class="evaluation-action" data-label="Evaluation"><a href="{{ route($ownerRoute ? 'owner.evaluations.show' : 'human-training.evaluations.show', $item['id']) }}">Open chart</a></td>
                        </tr>
                    @empty <tr><td class="evaluation-empty" colspan="5">No accessible current models.</td></tr> @endforelse
                </tbody>
            </table></div>
        </div>
    </section>
</x-layouts.app>
