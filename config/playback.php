<?php

return [
    'lease_seconds' => (int) env('PLAYBACK_LEASE_SECONDS', 90),
    'command_ttl_seconds' => (int) env('PLAYBACK_COMMAND_TTL_SECONDS', 60),
    'transfer_ttl_seconds' => (int) env('PLAYBACK_TRANSFER_TTL_SECONDS', 30),
    'default_offline_quota_bytes' => (int) env('PLAYBACK_OFFLINE_QUOTA_BYTES', 524288000),
    'shell_cache_prefix' => 'ak-shell-v1',
];
