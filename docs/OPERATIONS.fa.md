# راه‌اندازی، استقرار و عملیات

## محیط هدف

نسخهٔ PHP وب و CLI هر دو 8.5.x باشند. افزونه‌های ctype، curl، dom، fileinfo، mbstring، openssl، PDO، session، tokenizer، XML و درایور دیتابیس لازم‌اند. FFprobe باید در PATH یا FFPROBE_BINARY باشد. برای توسعه Node 24 و Composer 2 استفاده شده است. MySQL 8.4 با utf8mb4 هدف سرور است؛ SQLite برای تست و توسعهٔ موقت پشتیبانی می‌شود. تست‌ها روی MySQL 8.4.13 واقعی نیز اجرا شده‌اند؛ نتیجهٔ آخرین commit در سند بررسی و GitHub Actions کنترل شود.

برای شروع می‌توان 2 هسته و 2 تا 4 GiB RAM در نظر گرفت؛ این تخمین اولیه است و ظرفیت تأییدشده محسوب نمی‌شود. فضای رسانه را بر اساس تعداد فایل‌ها محاسبه کنید. هزار آهنگ سه‌دقیقه‌ای با 320 kbps تقریباً 7.2 GB صوت می‌خواهند. ده پخش 320 kbps تقریباً 3.2 Mbps ترافیک صوت، بدون سربار پروتکل، ایجاد می‌کنند. این محاسبه جای آزمون بار را نمی‌گیرد.

## تنظیمات سرور

- APP_ENV=production، APP_DEBUG=false و APP_URL برابر دامنهٔ HTTPS واقعی.
- APP_KEY فقط روی سرور تولید شود و در مخزن یا چت قرار نگیرد.
- SESSION_DRIVER=database، SESSION_ENCRYPT=true و SESSION_SECURE_COOKIE=true.
- QUEUE_CONNECTION=database و DB_QUEUE_RETRY_AFTER=180.
- queue و برنامه روی همان دیتابیس و همان connection نوشتن باشند؛ DB_QUEUE_CONNECTION را به دیتابیس مجزا تغییر ندهید.
- در MySQL مقدارهای DB_HOST، DB_PORT، DB_DATABASE، DB_USERNAME و DB_PASSWORD را روی سرور تنظیم کنید؛ DB_DATABASE مربوط به SQLite را نگه ندارید.
- timezone ذخیره‌سازی UTC و نمایش زمان با Intl مرورگر انجام می‌شود.
- document root فقط `public` باشد. `storage/app/private/media` نباید از وب قابل دستیابی باشد.
- فقط storage و bootstrap/cache برای کاربر سرویس وب قابل نوشتن باشند؛ مالکیت و permission را متناسب با میزبان تنظیم کنید.

نمونهٔ `deploy/nginx.conf` مسیر، hostname و socket نمونه دارد. TLS باید در وب‌سرور یا reverse proxy واقعی تنظیم شود. نمونهٔ Supervisor یک worker پایدار با timeout درست اجرا می‌کند. فایل‌ها باید با دامنه، مسیر و کاربر سرویس واقعی تطبیق داده شوند.

## حد فایل و workerها

upload_max_filesize حداقل 100M، post_max_size حدود 110M، client_max_body_size حدود 110m و max_file_uploads برای requestهای تکی پیش‌فرض کافی است. UI هر صوت را جدا ارسال می‌کند. backend هر فایل را تا 100 MiB و manifest را تا 2 MiB می‌پذیرد. FFprobe تا 15 ثانیه اجرا می‌شود.

برای PHP-FPM، تعداد childها باید پاسخگوی اتصال‌های صوت همزمان و چند درخواست API اضافه باشد. عدد را از مصرف واقعی RSS تعیین کنید. استریم فعلی از FPM عبور می‌کند؛ اگر درخواست‌های صوت ظرفیت worker را پر کنند، X-Accel پس از policy مرحلهٔ بعد بهینه‌سازی است. دیتابیس نباید گلوگاه انتقال باینری باشد.

## ترتیب release قابل تکرار

1. نصب از lockfileها با `composer install --no-dev --prefer-dist --no-interaction` و `npm ci`.
2. ساخت assets با `npm run build`.
3. backup دیتابیس و رسانه و بررسی فضای آزاد.
4. فعال‌کردن maintenance و توقف worker؛ اجرای `php artisan migrate --force` با credential migrator، پس از پشتیبان‌گیری. backfill این نسخه ارتقای zero downtime نیست.
5. بازگرداندن credential دیتابیس runtime به محیط سرویس؛ سپس `php artisan optimize` و `php artisan queue:restart`. credential migrator نباید در config cache وب باقی بماند.
6. سوئیچ release و بررسی `/up`، ورود، یک Range واقعی، یک import و یک ذخیرهٔ پخش.

مسیر `/up` liveness لاراول است؛ بررسی کامل DB/worker/readiness نیست. فرمان‌های تغییر production در این ساخت اجرا نشده‌اند. workflow موجود CI است و انتشار خودکار به سرور ندارد.

## عملیات روزمره

- صف: `php artisan queue:failed`، بررسی worker و لاگ؛ پس از رفع علت، retry از پنل import یا صف با دقت به وضعیت import.
- وضعیت import پردازش‌نشده: job موجود، worker فعال، connection یکسان و نبود timeout/کمبود فضا بررسی شود.
- تغییر کد worker: queue:restart برای بارگذاری release جدید.
- audit_events: ایجاد/فعال‌سازی حساب، بارگذاری صوت، پیش‌نمایش، ثبت صف، تکمیل import و تغییر playlist در DB ثبت می‌شوند. رابط audit با مجوز مستقل و cursor موجود است؛ اطلاعات محرمانه در Resource خروجی نیست.
- پشتیبان: دیتابیس، storage/app/private/media و APP_KEY جداگانه و امن نگهداری شوند؛ بازیابی عملی آزمایش شود.
- فایل‌های آپلودشده و استفاده‌نشده: پاک‌سازی خودکار وجود ندارد. پیش از افزودن آن، مرجع Track، import آماده/پردازشی و کاور جاری باید در نظر گرفته شوند.
- رمز فراموش‌شده: گردش ایمیلی وجود ندارد؛ مدیر مجاز از پنل رمز عضو را بازنشانی می‌کند، نشست‌ها باطل و MFA حفظ می‌شود. برای مالک قفل‌شده از app:recover-owner روی میزبان مورد اعتماد استفاده می‌شود.

## معیار انتشار

عبور از CI در PHP 8.5 و هر دو دیتابیس، بررسی مرورگر و گوشی، تست همزمانی واقعی ساخت حساب، تست بار 10 شنونده، تست بازیابی backup و تنظیم HTTPS شرط انتشار هستند. این موارد باید با نتیجهٔ واقعی در سند بررسی ثبت شوند.
