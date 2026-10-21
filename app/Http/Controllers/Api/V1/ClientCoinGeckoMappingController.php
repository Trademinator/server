<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CoinGeckoMarketMapping;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ClientCoinGeckoMappingController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $input = $request->validate(['after' => ['sometimes', 'required', 'uuid']]);
        $rows = CoinGeckoMarketMapping::query()
            ->with(['market:market_id,exchange_id,symbol', 'market.exchange:exchange_id,class'])
            ->whereHas('market.exchange')
            ->when(isset($input['after']), fn ($query) => $query->where('coin_gecko_market_mapping_id', '>', $input['after']))
            ->orderBy('coin_gecko_market_mapping_id')->limit(251)->get();
        $page = $rows->take(250);

        return response()->json([
            'api_version' => 1,
            'mappings' => $page->map(fn (CoinGeckoMarketMapping $mapping): array => [
                'mapping_id' => $mapping->getKey(),
                'exchange' => $mapping->market->exchange->class,
                'symbol' => $mapping->market->symbol,
                'base_symbol' => $mapping->base_symbol,
                'vs_currency' => $mapping->vs_currency,
                'status' => $mapping->status,
                'coin_id' => $mapping->status === 'resolved' ? $mapping->coin_id : null,
                'manually_mapped' => $mapping->manually_mapped,
                'updated_at' => $mapping->updated_at?->toISOString(),
            ])->values(),
            'next_cursor' => $rows->count() > 250 ? $page->last()->getKey() : null,
        ]);
    }
}
