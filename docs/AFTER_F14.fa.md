# پس از F14

Migration: `2026_11_02_160000_create_playback_devices`. جداول دستگاه، اجاره، فرمان و entitlement آفلاین اضافه می‌شوند.

۱. `php artisan migrate`
۲. `php artisan test --filter='PlaybackDeviceLeaseTest|OfflineRevocationTest|ApiContractTest|MusicLibraryTest'`
۳. `npm run test` و `npm run typecheck`
۴. در Settings دستگاه‌ها را ببینید، claim/transfer را آزمایش کنید و محدودیت آفلاین را قبل از opt-in بخوانید.

فاز بعد F15 است. ماتریس واقعی چندمرورگر/خواب سیستم در F16 تکمیل می‌شود.
