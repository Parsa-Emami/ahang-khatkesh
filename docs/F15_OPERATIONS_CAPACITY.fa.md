# F15: عملیات، ارتقای MySQL و ظرفیت واقعی

مرجع: `app/Actions/Ops`، `ops:verify-upgrade`، `ops:backup-manifest`، `ops:restore-verify`، `ops:alerts`، `StreamController`، `deploy/nginx.conf`، `database/fixtures/f15`.

## ارتقا و بازیابی

Fixture مصنوعی در `database/fixtures/f15` جدا از دادهٔ واقعی است. DDL در migrationهای expand می‌ماند؛ backfill طولانی با `media:backfill` و تأیید ادامه‌پذیر با `ops:verify-upgrade --limit --after`. Manifest پشتیبان شامل مسیر/SHA dump (اختیاری)، checksum رسانه، حضور config (بدون secret) و اشاره به بازیابی امن کلید است. `ops:restore-verify` ناسازگاری hash/media را fail می‌کند.

روی میزبان بدون MySQL، آزمون‌های SQLite منطق manifest/restore و verify ادامه‌پذیر را پوشش می‌دهند. آزمون MySQL واقعی با `AHANG_F15_MYSQL=1` و MySQL 8.4 (مثلاً `compose.dev.yml`) فعال می‌شود.

## X-Accel

با `MUSIC_STREAM_ACCEL_ENABLED=true` پس از `TrackPolicy`، Laravel فقط `X-Accel-Redirect` به `/internal-media/...` می‌فرستد. Nginx با `internal` و alias به دیسک خصوصی بایت را می‌دهد. Range/HEAD/Content-Disposition در مسیر PHP (dev) و هدر accel در مسیر تولید پوشش داده شده‌اند. Track حذف‌شده یا نامرئی حتی با URL داخلی حدس‌زده‌شده 404 است.

## ظرفیت و هشدار

`TenConcurrentStreams` ده Range هم‌زمان را اندازه می‌گیرد و snapshot ops را بدون ادعای SLO ثبت می‌کند. Operations API هشدارهای `disk_low`، `dead_queue`، `expired_lease` و `failed_jobs` را برمی‌گرداند.

## phpMyAdmin

فقط loopback/VPN، حساب جدا با حداقل مجوز، بدون root runtime. تغییر دادهٔ محصول فقط از پنل application.

آزمون‌ها: `MySqlUpgradeRestoreTest`، `TenConcurrentStreams`.
