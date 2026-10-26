# ahang-khatkesh · آهنگ خط‌کش

یک کتابخانهٔ موسیقی خصوصی با تجربهٔ آشنای پلیرهای موسیقی، برای حداکثر ۱۰ حساب شامل مدیران و حساب‌های غیرفعال. نام و هویت محصول **ahang-khatkesh** است.

این مخزن شامل یک پایهٔ اجرایی برای ادامهٔ توسعه است: Laravel 13، PHP 8.5، React 19، TypeScript و Vite؛ رابط فارسی، RTL و تم روشن/تیره؛ مدیریت اعضا و ورود پلی‌لیست از پنل ادمین؛ پخش فایل خصوصی، جستجو، علاقه‌مندی، ذخیرهٔ پلی‌لیست و بازگرداندن صف پخش. اتصال‌های زمان اجرای برنامه از API همان Laravel عبور می‌کنند. فایل صوت در فضای خصوصی فایل ذخیره می‌شود و متادیتا و رابطهٔ پلی‌لیست‌ها در دیتابیس قرار می‌گیرد.

## شروع توسعه

پیش‌نیازها: PHP 8.5.x با افزونه‌های معمول Laravel به‌همراه SQLite یا PDO MySQL، Composer 2، Node.js 24 و FFprobe از مجموعهٔ FFmpeg. PHP CLI و سرویس وب باید از نسخهٔ یکسان استفاده کنند.

```bash
composer install
cp .env.example .env
php artisan key:generate
php -r "file_exists('database/database.sqlite') || touch('database/database.sqlite');"
php artisan migrate --seed
npm ci
npm run build
php artisan app:create-admin
npm run dev
```

`app:create-admin` نام، ایمیل و رمز را تعاملی می‌پرسد؛ رمز در مخزن یا فرمان نمونه قرار ندارد. `npm run dev` وب‌سرور محلی Laravel روی پورت 8000، worker دیتابیس و Vite روی پورت 5173 را با هم اجرا می‌کند. آدرس توسعهٔ رابط `http://localhost:5173` است. برای مشاهدهٔ نسخهٔ ساخته‌شده می‌توان `php artisan serve` و worker را اجرا کرد و `http://localhost:8000` را باز کرد. هنگام راه‌اندازی مجدد، فایل `public/hot` باید به Vite در حال اجرا اشاره کند؛ اگر Vite خاموش است آن فایل موقت را حذف کنید.

در Windows کپی `.env.example` و ایجاد فایل SQLite را با فرمان معادل انجام دهید. اجرای PHP و Node باید در PATH در دسترس باشد. FFprobe با `FFPROBE_BINARY` قابل تنظیم است. حساب عمومی برای ثبت‌نام وجود ندارد؛ مدیر اعضای بعدی را از پنل می‌سازد.

برای تجربهٔ محلی همراه با صدای نمونه:

```bash
php artisan app:demo
```

این فرمان فقط در `local/testing` اجرا می‌شود؛ شش پلی‌لیست با صداهای مصنوعی تولیدشده می‌سازد و به حساب یا رمز نمونهٔ ثابت وابسته نیست. فایل‌های نمونه، ضبط هنرمندان واقعی نیستند.

## ورود یک پلی‌لیست

1. با حساب مدیر وارد «مدیریت کتابخانه» شوید.
2. نام، توضیح و حال‌وهوا را انتخاب کنید و فایل‌های صوتی را آپلود کنید؛ هر نوبت تا ۲۰ فایل و هر فایل تا ۱۰۰ MiB.
3. عنوان و هنرمند را تنظیم کنید، یا JSON/CSV دارای شناسهٔ فایل‌های همین کتابخانه را انتخاب کنید.
4. پیش‌نمایش را بررسی و ساخت به‌صورت منتشرشده یا پیش‌نویس را تأیید کنید.
5. worker پلی‌لیست را در یک تراکنش می‌سازد؛ وضعیت و گزارش در همان پنل قابل پیگیری است.

هر manifest حداکثر ۵۰۰ آهنگ و ۲ MiB دارد. ترتیب ردیف‌ها همان ترتیب پلی‌لیست است. نمونه‌های ساختار در [examples](examples) قرار دارند؛ شناسه‌های مثال باید با شناسهٔ واقعی حاصل از آپلود جایگزین شوند. URL موسیقی یک سرویس دیگر، فایل صوتی این برنامه محسوب نمی‌شود.

## سندهای توسعه

- [طرح جامع محصول و سناریوها](docs/PROJECT_BLUEPRINT.fa.md)
- [معماری، مرز ماژول‌ها و الگوها](docs/ARCHITECTURE.fa.md)
- [مدل داده و قواعد یکپارچگی](docs/DATA_MODEL.fa.md)
- [سیستم طراحی و کامپوننت‌ها](docs/DESIGN_SYSTEM.fa.md)
- [قرارداد API](docs/API.fa.md) و [OpenAPI 3.1](docs/openapi.json)
- [راهنمای ایمپورت](docs/IMPORTS.fa.md)
- [تصمیم‌های معماری](docs/DECISIONS.fa.md)
- [استقرار و عملیات](docs/OPERATIONS.fa.md)
- [فازهای ادامهٔ توسعه](docs/DEVELOPMENT_PLAN.fa.md)
- [مهارت‌ها و منابع طراحی](docs/SKILLS_AND_REFERENCES.fa.md)
- [وضعیت بررسی و محدودیت‌ها](docs/VERIFICATION.fa.md)

## بررسی تغییرات

```bash
php vendor/bin/phpunit
npm test
npm run build
composer validate --strict
composer audit
npm audit --omit=dev
php artisan optimize
php artisan optimize:clear
```

تست‌ها در دیتابیس تست اجرا می‌شوند. دادهٔ محیط توسعه را به تست متصل نکنید. CI برای PHP 8.5 و Node 24، SQLite و MySQL تنظیم شده است؛ اجرای محلی MySQL و اجرای GitHub Actions باید در وضعیت بررسی جداگانه ثبت شود.

## قواعد مخزن

`.env`، دیتابیس محلی، صوت‌ها، آپلودها، خروجی build، وابستگی‌ها و دادهٔ مرورگر نباید کامیت شوند. `composer.lock` و `package-lock.json` باید کامیت شوند. تغییرات ساختار دیتابیس با migration جدید انجام می‌گیرد. API و UI یک مبدأ دارند و احراز هویت با session و CSRF انجام می‌شود.

این مخزن به یک سرور PHP نیاز دارد؛ GitHub Pages میزبان مناسبی برای اجرای backend آن نیست. پیکربندی نمونهٔ Nginx و Supervisor در `deploy` موجود است.
