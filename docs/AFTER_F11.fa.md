# پس از F11

F11 migration جدید `2026_10_29_230000_create_listening_privacy` دارد. history برای همهٔ حساب‌های موجود و تازه خاموش شروع می‌شود. هیچ تاریخچه‌ای از دادهٔ قدیمی playback ساخته نمی‌شود. dependency تازه‌ای اضافه نشده است. [قرارداد و حدود F11](F11_LISTENING_PRIVACY.fa.md) مرجع رفتار است.

ابتدا از دیتابیس، فایل‌های خصوصی و APP_KEY طبق روال انتشار خود backup معتبر بگیرید. برنامه را maintenance و workerها را با supervisor همان محیط متوقف کنید. پس از دریافت کد:

```bash
php artisan down
git pull --ff-only
composer install --no-dev --prefer-dist --no-interaction
php artisan migrate --force
npm ci
npm run build
php artisan optimize:clear
php artisan queue:restart
php artisan up
```

فرمان down در بلوک بالا به تنهایی worker را متوقف نمی‌کند؛ توقف supervisor و هماهنگی سرویس‌ها باید پیش از migration انجام شود. پس از migration/build موفق، workerهای موجود شامل صف media و scheduler را دوباره فعال کنید. migration در MySQL تراکنش DDL کامل ندارد؛ در صورت شکست، اپراتور پیش از up وضعیت را بررسی و forward fix می‌کند. rollback این migration تمام تاریخچه و تنظیمات تازه را حذف می‌کند.

cron موجود Laravel باید هر دقیقه `php artisan schedule:run` را اجرا کند. دستور `php artisan schedule:list` باید `listening:prune` را نشان دهد. برای پاک‌سازی اولیه می‌توان `php artisan listening:prune --limit=1000` را اجرا کرد؛ خروجی فقط تعداد حذف‌شده است. بدون اجرای scheduler، حذف زمان‌بندی‌شدهٔ فیزیکی عقب می‌افتد.

bundle و manifest را با هم منتشر و صفحه را تازه کنید. در «تاریخچهٔ شنیدن» رضایت را بررسی کنید؛ فعال‌شدن صریح لازم است. آهنگ واقعی را بیش از سی ثانیه یا نصف مدت کوتاه‌تر بشنوید و یک ردیف را ببینید. خروجی JSON و حذف را امتحان کنید. با شروع حالت خصوصی باید همان Audio ادامه یابد، رویداد تازه ثبت نشود و دستگاه تازه صف خصوصی را restore نکند. در تب دیگر نیز وضعیت خصوصی تازه شود. میکس باید علت هر انتخاب و کنترل opt-out داشته باشد و پلی‌لیست عمومی نسازد.

این راهنما به معنی اجرای فرمان‌ها روی سرور کاربر نیست. تأیید production و دستگاه‌های فیزیکی در F16 باقی است. فاز بعدی F12، پنل کامل مدیریت کاتالوگ و درخواست اعضا است.
