<?php

return [
    'disk_low_bytes' => (int) env('OPS_DISK_LOW_BYTES', 2147483648),
    'dead_queue_seconds' => (int) env('OPS_DEAD_QUEUE_SECONDS', 900),
    'failed_jobs_alert' => (int) env('OPS_FAILED_JOBS_ALERT', 1),
    'capacity_report_path' => env('OPS_CAPACITY_REPORT_PATH', 'reports/f15-capacity.json'),
];
