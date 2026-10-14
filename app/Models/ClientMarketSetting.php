<?php

namespace App\Models;

use App\Traits\HasUniqueIdentifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientMarketSetting extends Model
{
    use HasUniqueIdentifier;

    protected $primaryKey = 'client_market_setting_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id', 'market_subscription_id', 'max_order_quote', 'max_position_quote', 'reserve_quote',
        'max_spread_bps', 'max_taker_fee_bps', 'max_signal_drift_bps',
        'min_signal_confidence', 'block_conflicting_exposure', 'paper_initial_quote', 'paper_slippage_bps',
        'alert_on_signal_change', 'alert_on_execution_failure', 'digest_frequency',
    ];

    protected function casts(): array
    {
        return [
            'block_conflicting_exposure' => 'boolean', 'alert_on_signal_change' => 'boolean',
            'alert_on_execution_failure' => 'boolean',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(MarketSubscription::class, 'market_subscription_id', 'market_subscription_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}
