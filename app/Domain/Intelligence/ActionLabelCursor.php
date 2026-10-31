<?php

namespace App\Domain\Intelligence;

use Generator;

/** A pass cursor commits mutable candle pages and the surviving pivot together. */
final class ActionLabelCursor
{
    public array $state;

    public function __construct(private BuildCheckpoint $checkpoint)
    {
        $this->state = $checkpoint->state['action_pass_state'] ?? [];
    }

    public function &rows(array|ActionCandleBuffer &$rows): Generator
    {
        $start = (int) ($this->state['position'] ?? 0);
        // Save before acquiring the mutable row reference: exporting dirty pages can evict cache rows.
        $indices = $rows instanceof ActionCandleBuffer ? $this->indices($start, count($rows)) : array_keys($rows);
        foreach ($indices as $index) {
            if ($index < $start) {
                continue;
            }
            $this->state['position'] = $index;
            if ($this->checkpoint->due()) {
                $this->checkpoint->state['action_pass_state'] = $this->state;
                $this->checkpoint->save();
            }
            $row = &$rows[$index];
            yield $index => $row;
        }

    }

    private function indices(int $start, int $count): Generator
    {
        for ($index = $start; $index < $count; $index++) {
            yield $index;
        }
    }
}
