# پس از کامیت F08

این کد روی سرور شما deploy نشده است. ابتدا روی staging با backup هماهنگ DB، media و دسترسی امن به APP_KEY اجرا کنید. worker و scheduler قبلی را متوقف کنید؛ migrationهای قدیمی را ویرایش نکنید و با phpMyAdmin فیلدهای lineage/quota را دستی تغییر ندهید.

```bash
php artisan down
git pull --ff-only
composer install --no-dev --prefer-dist --no-interaction
npm ci --include=dev
npm run build
php artisan optimize:clear
php artisan migrate --force
```

PHP CLI و FPM باید 8.5 باشند. برای worker، pcntl/posix و Linux لازم است. FFmpeg با libmp3lame، FFprobe و util-linux شامل setsid/prlimit نصب کنید. fingerprint ابزارهای واقعی سرور را بگیرید؛ مسیرها را با نصب خود تطبیق دهید:

```bash
sha256sum /usr/bin/ffmpeg /usr/bin/ffprobe
```

`FFMPEG_BINARY` و `FFPROBE_BINARY` و دو مقدار `MUSIC_FFMPEG_SHA256` و `MUSIC_FFPROBE_SHA256` را در env امن تنظیم کنید. در production، pin خالی یا ناهماهنگ پذیرفته نیست. `MUSIC_CONVERSIONS_ENABLED=true` و quota/headroom مناسب volume را تعیین کنید. `MUSIC_GC_ENABLED=false` را برای شروع نگه دارید. پس از تغییر config، cache قدیمی را پاک و doctor را اجرا کنید:

```bash
php artisan optimize:clear
php artisan media:doctor
php artisan media:backfill --apply --limit=100
php artisan media:verify --limit=1000
php artisan media:prepare-flac --limit=20
```

backfill را تا پایان inventory تکرار و verify را در تمام cursorها با `--after` ادامه دهید؛ errors باید خالی و ok=true باشد. doctor باید ok=true و ابزار pin شده را نشان دهد. بعد، با شناسهٔ واقعی یک سوپرادمین فعال، batchهای FLAC قبلی را enqueue کنید:

```bash
php artisan media:prepare-flac --apply --owner=OWNER_ID --limit=10
```

به‌جای OWNER_ID مقدار عددی واقعی را بگذارید. این فرمان فایل را تبدیل یا حذف نمی‌کند؛ فقط admission و reservation و queue می‌سازد. تا ظرفیت batch قبلی آزاد نشده، batch بعدی را تحمیل نکنید. خطای quota را با پاک‌کردن جدول یا status حل نکنید. failedها از پنل تبدیل با بررسی علت و retry صریح مدیریت شوند.

دو worker مستقل و scheduler لازم‌اند؛ نمونه‌های Supervisor را با مسیر سرور تطبیق دهید:

```bash
php artisan queue:work database --timeout=120
php artisan queue:work media_database --queue=media --tries=1 --timeout=800
php artisan library:maintain
```

cron هر دقیقه `php artisan schedule:run` را اجرا کند. worker رسانه یک process، retry_after=900، lease=960 و stopwaitsecs=840 دارد. تنظیمات Nginx/PHP-FPM برای FLAC 512 MiB و multipart 544 MiB به‌روز شده‌اند؛ فایل‌های temporary درخواست باید volume خصوصی و quota مستقل داشته باشند. `deploy/php-upload.ini` برای requestهای FPM است؛ time limit وب را به CLI worker تحمیل نکنید. نمونه‌های deploy روی سرور شما اجرا یا load test نشده‌اند.

پیش از خروج از maintenance، یک FLAC مصنوعی را از پنل آپلود کنید؛ ready، گزارش decode/CBR، حفظ اصل و اندازهٔ واقعی را ببینید؛ playlist بسازید؛ پخش پیش‌فرض، تغییر کیفیت با حفظ position، seek، سطل بازیافت و نقش listener را smoke test کنید. FLACهای منتشرشدهٔ قبلی باید پیش از بازکردن سرویس، MP3 آماده داشته باشند؛ مسیر auto به FLACِ فاقد MP3 برنمی‌گردد. سپس:

```bash
php artisan media:verify --limit=1000
php artisan up
```

صف، failed/needs_review، pending cleanup، heartbeat، فضای فیزیکی و ذخیرهٔ موقت را پایش کنید. تعویض build encoder، بررسی امنیت و pin تازه می‌خواهد؛ خروجی آماده درجا بازنویسی نشود. rollback مخرب migration انجام ندهید؛ از forward fix یا backup هماهنگ استفاده کنید.

گام توسعهٔ بعدی **F09** است: locale سراسری fa-IR/en-US، RTL/LTR تمام ۶۴ قرارداد کامپوننت، ترجمهٔ متن و aria و validation، حفظ player و draft هنگام تغییر زبان/تم، guard ناوبری فرم‌ها و آزمون کیبورد/جهت/تم. F08 به معنی تکمیل این ماتریس سراسری یا تأیید production نیست.
