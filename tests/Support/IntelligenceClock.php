<?php

namespace Tests\Support {

    use Closure;
    use LogicException;

    final class IntelligenceClock
    {
        private static ?float $time = null;

        private static ?Closure $clock = null;

        public static function freeze(float $time): void
        {
            self::$time = $time;
            self::$clock = null;
        }

        public static function advance(float $seconds): void
        {
            if (self::$time === null) {
                throw new LogicException('Freeze the intelligence clock before advancing it.');
            }

            self::$time += $seconds;
        }

        public static function useClock(Closure $clock): void
        {
            self::$clock = $clock;
            self::$time = null;
        }

        public static function reset(): void
        {
            self::$time = null;
            self::$clock = null;
        }

        public static function now(): float
        {
            return self::$clock !== null ? (self::$clock)() : (self::$time ?? \microtime(true));
        }

        public static function microtime(bool $asFloat = false): string|float
        {
            if (self::$clock === null && self::$time === null) {
                return \microtime($asFloat);
            }

            $time = self::now();

            return $asFloat ? $time : sprintf('%.8f %d', $time - floor($time), (int) floor($time));
        }
    }
}

namespace App\Domain\Intelligence {

    use Tests\Support\IntelligenceClock;

    function microtime(bool $asFloat = false): string|float
    {
        return IntelligenceClock::microtime($asFloat);
    }
}
