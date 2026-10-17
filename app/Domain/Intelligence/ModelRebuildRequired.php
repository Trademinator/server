<?php

namespace App\Domain\Intelligence;

use RuntimeException;

final class ModelRebuildRequired extends RuntimeException
{
    public function __construct(public readonly string $modelId)
    {
        parent::__construct('Model '.$modelId.' requires rebuilding for Rubix ML '.\Rubix\ML\VERSION
            .'. Run trademinator:dispatch-market-intelligence --force --all.');
    }
}
