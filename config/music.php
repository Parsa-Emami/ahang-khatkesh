<?php

return [
    'ffprobe' => env('FFPROBE_BINARY', 'ffprobe'),
    'max_tracks_per_import' => 500,
    'max_upload_kilobytes' => 102400,
    'max_duration_seconds' => 14400,
    'storage_quota_bytes' => (int) env('MUSIC_STORAGE_QUOTA_BYTES', 107374182400),
    'storage_headroom_bytes' => (int) env('MUSIC_STORAGE_HEADROOM_BYTES', 1073741824),
    'reservation_seconds' => 900,
    'upload_lease_seconds' => 300,
    'max_active_reservations' => 20,
    'garbage_retention_seconds' => 86400,
    'garbage_collection_enabled' => (bool) env('MUSIC_GC_ENABLED', false),
    'import_lease_seconds' => 240,
    'import_queue_stall_seconds' => 600,
    'import_max_recoveries' => 3,
];
