<?php

namespace App\Domain\Intelligence;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

/** A committed continuation, not a failed build. */
final class BuildPaused extends RuntimeException implements ShouldntReport {}
