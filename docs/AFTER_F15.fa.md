# پس از F15

۱. در صورت نیاز MySQL: `docker compose -f compose.dev.yml up -d` و `AHANG_F15_MYSQL=1`
۲. `php artisan migrate`
۳. `php artisan test --filter='MySqlUpgradeRestoreTest|TenConcurrentStreamsTest|AudioConversionTest|MusicLibraryTest'`
۴. برای X-Accel در staging: `MUSIC_STREAM_ACCEL_ENABLED=true` و `deploy/nginx.conf` را اعمال کنید
۵. `php artisan ops:backup-manifest` و `ops:restore-verify` را روی snapshot آزمایشی اجرا کنید

SLO ظرفیت فقط پس از مشاهده روی سخت‌افزار اعلام‌شده ثبت می‌شود. فاز بعد F16 است.
