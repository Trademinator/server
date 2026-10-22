<?php

namespace App\Domain\Intelligence;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class KnowledgeRequestJournal
{
    public function request(string $exchange, string $symbol, string $period, string $stage, ?int $throughMs = null): string
    {
        return DB::transaction(function () use ($exchange, $symbol, $period, $stage, $throughMs): string {
            $scope = compact('exchange', 'symbol', 'period', 'stage');
            $pending = DB::table('knowledge_stage_requests')->where($scope)
                ->whereIn('status', ['queued', 'running', 'blocked'])
                ->orderBy('created_at')->lockForUpdate()->first();
            if ($pending !== null) {
                if ($throughMs !== null && $throughMs > (int) ($pending->required_through_ms ?? 0)) {
                    DB::table('knowledge_stage_requests')->where('request_id', $pending->request_id)
                        ->update(['required_through_ms' => $throughMs, 'updated_at' => now()]);
                }

                return (string) $pending->request_id;
            }

            $id = (string) Str::uuid7();
            DB::table('knowledge_stage_requests')->insert([
                'request_id' => $id, ...$scope, 'status' => 'queued',
                'required_through_ms' => $throughMs, 'created_at' => now(), 'updated_at' => now(),
            ]);

            return $id;
        });
    }

    public function markSatisfied(string $exchange, string $symbol, string $period, string $stage, int $completedMs): int
    {
        return DB::table('knowledge_stage_requests')
            ->where(compact('exchange', 'symbol', 'period', 'stage'))
            ->whereIn('status', ['queued', 'running', 'blocked'])
            ->where(function ($query) use ($completedMs): void {
                $query->whereNull('required_through_ms')->orWhere('required_through_ms', '<=', $completedMs);
            })
            ->update(['status' => 'completed', 'completed_through_ms' => $completedMs, 'updated_at' => now()]);
    }

    public function markRetained(string $exchange, string $symbol, string $period, string $stage, int $completedMs): int
    {
        return DB::table('knowledge_stage_requests')
            ->where(compact('exchange', 'symbol', 'period', 'stage'))
            ->whereIn('status', ['queued', 'running', 'blocked'])
            ->update(['status' => 'retained', 'completed_through_ms' => $completedMs,
                'error' => 'Valid candidate retained; the incumbent model was not replaced.',
                'updated_at' => now()]);
    }

    public function fail(string $exchange, string $symbol, string $period, string $stage, string $reason): void
    {
        DB::table('knowledge_stage_requests')->where(compact('exchange', 'symbol', 'period', 'stage'))
            ->whereIn('status', ['queued', 'running', 'blocked'])
            ->update(['status' => 'failed', 'error' => mb_substr($reason, 0, 2000), 'updated_at' => now()]);
    }

    public function markBlocked(string $exchange, string $symbol, string $period, string $stage): void
    {
        DB::table('knowledge_stage_requests')->where(compact('exchange', 'symbol', 'period', 'stage'))
            ->whereIn('status', ['queued', 'running', 'blocked'])
            ->update(['status' => 'blocked', 'updated_at' => now()]);
    }

    public function outstanding(string $exchange, string $symbol, string $period, string $stage): int
    {
        return DB::table('knowledge_stage_requests')
            ->where(compact('exchange', 'symbol', 'period', 'stage'))
            ->whereIn('status', ['queued', 'running', 'blocked'])->count();
    }
}
