> راهنمای جاری پس از F08 در [AFTER_F08](AFTER_F08.fa.md) است؛ مراحل زیر سابقهٔ ارتقای F07 هستند.

# پس از کامیت F07 چه کنیم؟

این مخزن روی سرور شما deploy نشده است. مراحل زیر را ابتدا روی staging و backup هماهنگ DB+media اجرا کنید. تغییر دستی جدول‌ها در phpMyAdmin جای migration و backfill را نمی‌گیرد.

۱. CI همان SHA باید کاملاً موفق باشد؛ evidence و وضعیت F07 را در ROADMAP ببینید. از DB، فایل‌های خصوصی و روش امن بازیابی APP_KEY نسخهٔ پشتیبان داشته باشید. workerها و scheduler قبلی را متوقف و برنامه را maintenance کنید.

۲. کد و dependency و bundle را به‌روز کنید و migration افزایشی را اجرا کنید:

```bash
php artisan down
git pull --ff-only
composer install --no-dev --prefer-dist --no-interaction
npm ci --include=dev
npm run build
php artisan optimize:clear
php artisan migrate --force
```

۳. `MUSIC_STORAGE_QUOTA_BYTES` و `MUSIC_STORAGE_HEADROOM_BYTES` را مطابق volume واقعی تنظیم کنید. پیش‌فرض‌ها به ترتیب ۱۰۰ GiB و ۱ GiB هستند. `MUSIC_GC_ENABLED=false` را برای شروع نگه دارید. PHP temporary upload و Nginx request body باید فضای خصوصی و محدود جداگانه داشته باشند؛ نمونه‌های deploy را با مسیر سرور خود تطبیق و با ابزارهای همان سرور بررسی کنید.

۴. رسانه‌های موجود را بررسی و backfill کنید:

```bash
php artisan media:backfill --limit=100
php artisan media:backfill --apply --limit=100
php artisan media:verify --limit=1000
php artisan media:gc --limit=500
```

backfill را تا `remaining=0` و `inventory_complete=true` تکرار کنید. `errors` باید خالی باشد. dry-run با remaining مثبت ممکن است خروج ناموفق بدهد چون هنوز آمادهٔ فعال‌سازی نیست. `media:verify` باید در همه batchها `ok=true` و در پایان cursor خالی بدهد؛ اگر next_cursor دارد از `--after=<cursor>` ادامه دهید. فایل گمشده یا mismatch را قبل از فعال‌سازی با منبع معتبر اصلاح کنید؛ گزارش را نادیده نگیرید. GC پیش‌فرض فقط گزارش می‌دهد.

۵. worker و scheduler تازه را راه بیندازید. cron استاندارد Laravel باید هر دقیقه `php artisan schedule:run` را اجرا کند؛ اجرای دستی نمونهٔ maintenance:

```bash
php artisan library:maintain
php artisan queue:work database --timeout=120
```

برای اجرا به‌صورت سرویس از Supervisor نمونه استفاده کنید. retry_after باید از timeout بزرگ‌تر بماند؛ lease و بودجه‌ها در config مشخص‌اند. payload صفِ قدیمی و preview نسخهٔ قبل مجوز انتشار جدید نیستند؛ import قدیمی را در صورت needs_review با پیش‌نمایش تازه بسازید. blob contract باعث تغییر هش effective plan شده است.

۶. با سوپرادمین، آپلود کوچک، preview، ساخت playlist، پخش خصوصی، تغییر کاور، سطل بازیافت و بازیابی را smoke test کنید. با listener، نوشتن کاتالوگ و رزرو فضا باید رد شوند. پس از موفقیت، با `php artisan up` maintenance را بردارید و صف، pending cleanup، دیسک و log را زیر نظر بگیرید.

GC حذف‌کننده را تنها پس از بررسی dry-run و retention فعال کنید. `media:gc --mark` علامت‌گذاری و `media:gc --sweep` حذف candidateهای واجد شرایط را انجام می‌دهند؛ ردیف referenced و فایل تازه حذف نمی‌شوند. برای پیمایش همه batchها next_cursor را دنبال کنید. خاموش‌بودن GC خودکار مانع بازیابی upload/import نمی‌شود.

## گام توسعهٔ بعدی

F08: pipeline واقعی FLAC → MP3 320kbps. اول conversion/attempt و reservation مشتق‌سازی، fencing و resource limits؛ سپس اجرای FFmpeg بدون shell و شبکه، staging خصوصی، full decode خروجی، حفظ اصل FLAC، اتصال rendition آماده و پنل پیشرفت/خطا/retry/انتخاب کیفیت. این قابلیت هنوز در runtime ساخته نشده است. در هر مرحله نقص بازتولیدشده، اصلاح، تست و evidence همان commit ثبت می‌شود.
