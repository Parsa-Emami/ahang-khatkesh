# F14: کنترل چند دستگاه و آفلاین اختیاری

مرجع: `app/Actions/Playback`، `resources/js/lib/playback-device.ts`، `resources/js/features/playback/DevicePicker.tsx`، `resources/js/lib/shell-sw.ts`، `resources/js/lib/offline-store.ts`.

## دستگاه و اجاره

`playback_devices` به `device_key` نشست‌محور و `session_binding` وابسته است. `playback_leases` generation، token، expiry و revision مستقل از `playback_states` دارد. فقط دارندهٔ اجارهٔ فعال می‌تواند snapshot پخش را بنویسد؛ در غیر این صورت `playback_device_fenced` برمی‌گردد. انتقال با offer/accept/reject و timeout به fallback است. revoke دستگاه، دستورهای pending را invalidate می‌کند و generation را بالا می‌برد.

## فرمان‌های راه دور

`playback_device_commands` فقط برای دستگاه همان کاربر با sequence و expiry صادر می‌شود. پس از bump generation یا revoke، فرمان قدیمی مصرف نمی‌شود.

## Service Worker و آفلاین

SW فقط پوستهٔ عمومی نسخه‌دار (`/`، favicon، preferences، `/build/*`) را cache می‌کند؛ `/api/**` و stream هرگز. دانلود صوت خصوصی فقط با `offline_opt_in` و تأیید محدودیت ابطال است. سهمیه روی سرور، وضعیت در `offline_downloads`، و wipe هنگام logout. دستگاه کاملاً آفلاین تا reconnect از revoke/logout باخبر نمی‌شود؛ این محدودیت در Settings و `usage.revocation_limit` توضیح داده شده است.

آزمون‌ها: `PlaybackDeviceLeaseTest`، `OfflineRevocationTest`، `ShellServiceWorkerMatrix`.
