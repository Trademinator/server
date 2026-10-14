<?php

return [
    // Explicit rollout switch; disabling never changes old journal observations.
    'enabled' => (bool) env('SIGNAL_MEMORY_ENABLED', false),
];
