<?php

// Only these addresses may set X-Forwarded-For / X-Forwarded-Proto (same rule as the legacy portal).
return [
    'proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))))),
];
