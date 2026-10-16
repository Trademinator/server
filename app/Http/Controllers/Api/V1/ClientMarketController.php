<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Client\ClientSignalPayload;
use App\Domain\Client\ClientMarketAccess;
use App\Http\Controllers\Controller;
use App\Models\ClientMarketSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ClientMarketController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = $request->user()->subscriptions()->with('market.exchange', 'market.feed', 'market.latestSignal', 'clientSetting')
            ->orderByDesc('active')->orderBy('created_at')->get()->map(fn ($subscription): array => [
                'subscription_id' => $subscription->getKey(),
                'active' => (bool) $subscription->active,
                'exchange' => ['class' => $subscription->market->exchange->class, 'name' => $subscription->market->exchange->name],
                'symbol' => $subscription->market->symbol,
                'period' => $subscription->market->feed?->selected_period,
                'latest_signal_id' => $subscription->market->latestSignal?->getKey(),
            ]);

        return response()->json(['api_version' => 1, 'markets' => $items]);
    }

    public function show(Request $request, string $subscription, ClientMarketAccess $access, ClientSignalPayload $signals): JsonResponse
    {
        $item = $access->owned($request->user(), $subscription);

        return response()->json(['api_version' => 1, 'market' => [
            'subscription_id' => $item->getKey(), 'active' => (bool) $item->active,
            'exchange' => ['class' => $item->market->exchange->class, 'name' => $item->market->exchange->name],
            'symbol' => $item->market->symbol, 'tick_size' => $item->market->tick_size,
            'period' => $item->market->feed?->selected_period,
            'settings' => $this->settingsPayload($item->clientSetting),
            'signal' => $signals->for($item),
        ]]);
    }

    public function updateSettings(Request $request, string $subscription, ClientMarketAccess $access): JsonResponse
    {
        $item = $access->owned($request->user(), $subscription);
        $data = $request->validate([
            'max_order_quote' => ['required', 'numeric', 'gt:0'],
            'max_position_quote' => ['nullable', 'numeric', 'gt:0'],
            'reserve_quote' => ['required', 'numeric', 'min:0'],
            'max_spread_bps' => ['required', 'numeric', 'gt:0', 'max:10000'],
            'max_taker_fee_bps' => ['required', 'numeric', 'min:0', 'max:10000'],
            'max_signal_drift_bps' => ['sometimes', 'numeric', 'gt:0', 'max:10000'],
            'min_signal_confidence' => ['required', 'numeric', 'between:0,1'],
            'block_conflicting_exposure' => ['required', 'boolean'],
            'paper_initial_quote' => ['required', 'numeric', 'gt:0'],
            'paper_slippage_bps' => ['sometimes', 'numeric', 'min:0', 'lt:10000'],
            'alert_on_signal_change' => ['required', 'boolean'],
            'alert_on_execution_failure' => ['required', 'boolean'],
            'digest_frequency' => ['required', Rule::in(['off', 'daily', 'weekly'])],
        ]);
        $data['max_signal_drift_bps'] ??= $item->clientSetting?->max_signal_drift_bps
            ?? config('client.default_max_signal_drift_bps');
        $data['paper_slippage_bps'] ??= $item->clientSetting?->paper_slippage_bps
            ?? config('client.default_paper_slippage_bps');

        $setting = ClientMarketSetting::query()->updateOrCreate([
            'user_id' => $request->user()->user_id, 'market_subscription_id' => $item->getKey(),
        ], $data);

        return response()->json(['api_version' => 1, 'settings' => $this->settingsPayload($setting)]);
    }

    private function settingsPayload(?ClientMarketSetting $setting): array
    {
        return [
            'max_order_quote' => $setting?->max_order_quote,
            'max_position_quote' => $setting?->max_position_quote,
            'reserve_quote' => $setting?->reserve_quote ?? '0',
            'max_spread_bps' => $setting?->max_spread_bps ?? '100',
            'max_taker_fee_bps' => $setting?->max_taker_fee_bps ?? '100',
            'max_signal_drift_bps' => $setting?->max_signal_drift_bps ?? (string) config('client.default_max_signal_drift_bps'),
            'min_signal_confidence' => $setting?->min_signal_confidence ?? '0.6',
            'block_conflicting_exposure' => (bool) ($setting?->block_conflicting_exposure ?? true),
            'paper_initial_quote' => $setting?->paper_initial_quote ?? (string) config('client.default_paper_quote'),
            'paper_slippage_bps' => $setting?->paper_slippage_bps ?? (string) config('client.default_paper_slippage_bps'),
            'alerts' => [
                'signal_change' => (bool) ($setting?->alert_on_signal_change ?? false),
                'execution_failure' => (bool) ($setting?->alert_on_execution_failure ?? false),
                'digest_frequency' => $setting?->digest_frequency ?? 'off',
            ],
        ];
    }
}
