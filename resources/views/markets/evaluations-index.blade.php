<x-layouts.app title="Model evaluations">
    @include('markets.guide-styles')
    <section class="pair-guide pair-review">
        <header class="guide-hero"><h1>Model evaluations</h1>
            <p>Current published models · retrospective chronological holdouts, not live trades or profitability.</p>
        </header>
        <div class="guide-panel">
            <div class="review-table-wrap"><table>
                <thead><tr><th>Market</th><th>Engine</th><th>Outcome macro F1</th><th>Action directional precision</th><th>Evaluation</th></tr></thead>
                <tbody>
                    @forelse($models as $item)
                        @php($r = $item['report'])
                        <tr>
                            <td>{{ $r['exchange'] ?? '—' }} · {{ $r['symbol'] ?? '—' }} · {{ $r['period'] ?? '—' }}</td>
                            <td>{{ $r['knn_engine'] ?? 'Legacy / unspecified' }}</td>
                            <td>{{ isset($r['outcome']['algorithmic']['holdout']['macro_f1']) ? number_format(100 * $r['outcome']['algorithmic']['holdout']['macro_f1'], 1).'%' : '—' }}</td>
                            <td>{{ isset($r['action']['algorithmic']['holdout']['semantic_precision']) ? number_format(100 * $r['action']['algorithmic']['holdout']['semantic_precision'], 1).'%' : '—' }}</td>
                            <td><a href="{{ route($ownerRoute ? 'owner.evaluations.show' : 'human-training.evaluations.show', $item['id']) }}">Open chart</a></td>
                        </tr>
                    @empty <tr><td colspan="5">No accessible current models.</td></tr> @endforelse
                </tbody>
            </table></div>
        </div>
    </section>
</x-layouts.app>
