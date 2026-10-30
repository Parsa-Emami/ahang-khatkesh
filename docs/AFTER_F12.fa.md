# پس از F12

F12 migration افزایشی `2026_10_30_034717_add_catalog_workflows` دارد. دو جدول journal و پیشنهاد و سه فیلد Track اضافه می‌شوند. شناسه‌ها، ترتیب و لایک‌های موجود حفظ می‌شوند. هویت ضبط قدیمی خودکار از نام یا فایل حدس زده نمی‌شود. dependency تازه‌ای اضافه نشده است. [قرارداد F12](F12_CATALOG_WORKFLOWS.fa.md) مرجع کارکرد است؛ شاهد نهایی CI در رودمپ ثبت می‌شود.

ابتدا backup هماهنگ دیتابیس، رسانهٔ خصوصی و APP_KEY را طبق روال انتشار تهیه و اعتبار آن را بررسی کنید. workerهای برنامه و media را با supervisor محیط متوقف و maintenance را فعال کنید. سپس:

```bash
php artisan down
git pull --ff-only
composer install --no-dev --prefer-dist --no-interaction
php artisan migrate --force
npm ci
npm run build
php artisan optimize:clear
php artisan queue:restart
```

`down` جای توقف worker را نمی‌گیرد. migration در MySQL تضمین تراکنش کامل DDL ندارد؛ شکست را پیش از up بررسی و اصلاح کنید. rollback migration، پیشنهادها و journal را حذف می‌کند؛ بازگردانی عملیاتی پس از شروع استفاده نیازمند backup هماهنگ است. از rollback کورکورانهٔ schema برای undo یک عمل مدیریتی استفاده نکنید.

bundle و manifest را با هم منتشر کنید. پس از موفقیت migration و build، workerهای قبلی و scheduler را فعال کنید و `php artisan up` بزنید. وظایف F07/F08/F11 مانند قبل برقرارند؛ F12 صف یا cron تازه‌ای لازم ندارد. این راهنما اجرای فرمان روی سرور شما محسوب نمی‌شود.

با owner دارای MFA وارد شوید: شمارش گردش کار، ویرایش آزمایشی یک آهنگ، پیش‌نمایش، commit و undo را بررسی کنید. از یک عضو، پیشنهاد پلی‌لیست بفرستید؛ مالک آن را ابتدا draft بپذیرد و دسترسی عضو پیش از انتشار را بررسی کنید. هویت و مرجع ضبط را فقط پس از بررسی معتبر ثبت کنید. برای repair ابتدا backup منبع قبلی را کنترل کنید؛ اگر منبع قدیمی وجود ندارد، undo تا بازیابی بایت معتبر متوقف می‌ماند.

فاز بعدی F13، متن همگام، crossfade و جزئیات صوت است. تأیید production و آزمون دستگاه‌های فیزیکی در F16 باقی است.
