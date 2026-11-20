<?php

namespace App\Domain\Intelligence;

use RuntimeException;

final class ModelUnavailable extends RuntimeException
{
    public function __construct(public readonly string $modelId, public readonly string $reason)
    {
        parent::__construct('Intelligence model '.$modelId.' is unavailable: '.$reason.'.');
    }
}
