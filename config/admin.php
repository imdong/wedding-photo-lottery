<?php

return [
    'password' => env('ADMIN_PASSWORD', ''),
    'token_ttl_minutes' => (int) env('ADMIN_TOKEN_TTL_MINUTES', 480),
];
