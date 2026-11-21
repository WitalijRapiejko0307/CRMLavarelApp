<?php

return [
    // Public outbound IP of this server; shown to the call center when it must whitelist us.
    'outbound_ip' => env('SR_OUTBOUND_IP'),

    // Delay before a push job runs, so several quick edits collapse into one request.
    'push_delay_seconds' => 15,

    // Retry delays (seconds) for transient errors; attempts = count + 1.
    'backoff' => [60, 300, 900, 3600],
];
