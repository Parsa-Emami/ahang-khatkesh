# ahang-khatkesh · آهنگ خط‌کش

یک کتابخانهٔ موسیقی خصوصی با تجربهٔ آشنای پلیرهای موسیقی، برای حداکثر ۱۰ حساب شامل مدیران و حساب‌های غیرفعال. نام و هویت محصول **ahang-khatkesh** است.

این مخزن شامل نسخهٔ 0.2 و پایهٔ اجرایی برای ادامهٔ توسعه است: Laravel 13، PHP 8.5، React 19، TypeScript و Vite؛ رابط فارسی، RTL و تم روشن/تیره؛ مدیریت اعضا و ورود پلی‌لیست از پنل ادمین؛ پخش فایل خصوصی، جستجو، علاقه‌مندی، ذخیرهٔ پلی‌لیست و بازگرداندن صف پخش. اتصال‌های زمان اجرای برنامه از API همان Laravel عبور می‌کنند. فایل صوت در فضای خصوصی فایل ذخیره می‌شود و متادیتا و رابطهٔ پلی‌لیست‌ها در دیتابیس قرار می‌گیرد.

**وضعیت فعلی:** اصلاح runtime مالکیت ایمپورت، epoch رمز و cleanup کاور، دسترسی انحصاری سوپرادمین، سطل بازیافت، حفظ draft، retry وابسته به نشست، صف قابل ویرایش و تایمر خواب اضافه شده‌اند. [رودمپ ۱۶ فازی](docs/ROADMAP.fa.md) وضعیت implementation و verification را جدا نگه می‌دارد؛ F01 تا F08 کد اجرایی دارند؛ وضعیت تأیید جاری فقط از رودمپ خوانده شود. F09 تا F16 هنوز اجرا نشده‌اند. سهمیهٔ ذخیره‌سازی، جداسازی blob، بازیابی job و GC کنترل‌شده نیز اجرا شده‌اند. نتیجه و محدودیت‌ها در [evidence F07](docs/verification/foundation-through-f07-2026-10-28.json) ثبت‌اند. این پروژه هنوز production یا هم‌تراز Spotify/Apple Music تأیید نشده است. [نقص‌های باقی‌مانده](docs/CURRENT_REVIEW.fa.md) و [مقایسهٔ محصول](docs/PRODUCT_BENCHMARK.fa.md) مرجع ادامه‌اند.

## شروع توسعه

ابتدا MySQL و حساب دیتابیس را طبق [راهنمای MySQL و phpMyAdmin](docs/MYSQL_PHPMYADMIN.fa.md) آماده و تنظیمات DB را در `.env` وارد کنید. پیش‌نیازها: PHP 8.5.x با افزونه‌های معمول Laravel به‌همراه PDO MySQL و MySQL 8.4، Composer 2، Node.js 24 و FFprobe از مجموعهٔ FFmpeg. PHP CLI و سرویس وب باید از نسخهٔ یکسان استفاده کنند.

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm ci
npm run build
php artisan app:create-admin
npm run dev
```

`app:create-admin` نام، ایمیل و رمز را تعاملی می‌پرسد؛ رمز در مخزن یا فرمان نمونه قرار ندارد. اولین مدیر در نبود مالک فعال به مالک تبدیل می‌شود؛ برای دسترسی پنل باید رمزساز را فعال و کدهای بازیابی را ذخیره کند. `npm run dev` وب‌سرور محلی Laravel روی پورت 8000، worker دیتابیس و Vite روی پورت 5173 را با هم اجرا می‌کند. آدرس توسعهٔ رابط `http://localhost:5173` است. برای مشاهدهٔ نسخهٔ ساخته‌شده می‌توان `php artisan serve` و worker را اجرا کرد و `http://localhost:8000` را باز کرد. هنگام راه‌اندازی مجدد، فایل `public/hot` باید به Vite در حال اجرا اشاره کند؛ اگر Vite خاموش است آن فایل موقت را حذف کنید.

در Windows کپی `.env.example` را با فرمان معادل انجام دهید؛ اتصال MySQL را در همین فایل تنظیم کنید. اجرای PHP و Node باید در PATH در دسترس باشد. FFprobe با `FFPROBE_BINARY` قابل تنظیم است. حساب عمومی برای ثبت‌نام وجود ندارد؛ مدیر اعضای بعدی را از پنل می‌سازد.

برای تجربهٔ محلی همراه با صدای نمونه:

```bash
php artisan app:demo
```

این فرمان فقط در `local/testing` اجرا می‌شود؛ شش پلی‌لیست با صداهای مصنوعی تولیدشده می‌سازد و به حساب یا رمز نمونهٔ ثابت وابسته نیست. فایل‌های نمونه، ضبط هنرمندان واقعی نیستند.

## ورود یک پلی‌لیست

1. با حساب سوپرادمین وارد «مدیریت کتابخانه» شوید.
2. نام، توضیح و حال‌وهوا را انتخاب کنید و فایل‌های صوتی را آپلود کنید؛ هر نوبت تا ۲۰ فایل و FLAC تا ۵۱۲ MiB و سایر فرمت‌ها تا ۱۰۰ MiB.
3. عنوان و هنرمند را تنظیم کنید، یا JSON/CSV دارای شناسهٔ فایل‌های همین کتابخانه را انتخاب کنید.
4. پیش‌نمایش را بررسی و ساخت به‌صورت منتشرشده یا پیش‌نویس را تأیید کنید.
5. worker پلی‌لیست را در یک تراکنش می‌سازد؛ وضعیت و گزارش در همان پنل قابل پیگیری است.

هر manifest حداکثر ۵۰۰ آهنگ و ۲ MiB دارد. ترتیب ردیف‌ها همان ترتیب پلی‌لیست است. نمونه‌های ساختار در [examples](examples) قرار دارند؛ شناسه‌های مثال باید با شناسهٔ واقعی حاصل از آپلود جایگزین شوند. URL موسیقی یک سرویس دیگر، فایل صوتی این برنامه محسوب نمی‌شود.

## سندهای توسعه

- [تبدیل FLAC، MP3 و انتخاب کیفیت در F08](docs/F08_AUDIO_CONVERSION.fa.md)
- [پس از F08: ارتقا و گام بعد](docs/AFTER_F08.fa.md)

- [چرخهٔ رسانه، quota و recovery در F07](docs/F07_STORAGE_AND_RECOVERY.fa.md)
- [پس از کامیت: ارتقا و گام بعد](docs/AFTER_COMMIT.fa.md)

- [رودمپ ۱۶ فازی و معیار تأیید](docs/ROADMAP.fa.md)
- [بازبینی تازه و نقص‌های باقی‌مانده](docs/CURRENT_REVIEW.fa.md)
- [معیار Spotify و Apple Music](docs/PRODUCT_BENCHMARK.fa.md)

- [بازبینی معماری، نقص‌های باز و شواهد](docs/ARCHITECTURE_REVIEW.fa.md)
- [معماری هدف 0.3 و invariantهای اصلاحی](docs/ARCHITECTURE_TARGET.fa.md)
- [قرارداد universal، RTL/LTR، locale و 21st.dev](docs/UNIVERSAL_UI.fa.md)
- [کاتالوگ ۶۴ کامپوننت و حالت‌های پذیرش](docs/COMPONENT_CATALOG.fa.md)
- [ساختار FLAC به MP3 320kbps و پنل تبدیل](docs/MEDIA_PIPELINE.fa.md)
- [بسته‌ها و ترتیب دقیق پیاده‌سازی](docs/IMPLEMENTATION_ROADMAP.fa.md)
- [ساختار ماشین‌خوان و checker معماری](docs/architecture/README.fa.md)
- [طرح جامع محصول و سناریوها](docs/PROJECT_BLUEPRINT.fa.md)
- [معماری، مرز ماژول‌ها و الگوها](docs/ARCHITECTURE.fa.md)
- [معماری فرانت و کامپوننت‌ها](docs/FRONTEND_ARCHITECTURE.fa.md)
- [نقش‌ها، MFA و امنیت پنل](docs/ADMIN_SECURITY.fa.md)
- [بازبینی رابط و محدودیت‌های آزمون](docs/DESIGN_REVIEW.fa.md)
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
node scripts/check-architecture.mjs
node scripts/check-roadmap.mjs
php vendor/bin/phpunit
npm test
npm run build
composer validate --strict
php artisan media:doctor
composer audit
php vendor/bin/pint --test
npm run format:check
npm audit
php artisan optimize
php artisan optimize:clear
```

آزمون مرورگر: ابتدا `npm run build` و نصب Chromium با `npx playwright install chromium` یا Chrome نصب‌شده با `AHANG_BROWSER_CHANNEL=chrome`؛ سپس `npm run test:browser`. این runner رمز تصادفی و SQLite/رسانهٔ مصنوعی مجزا در temporary directory سیستم می‌سازد؛ trace و storageState ذخیره نمی‌کند. مرورگر HTTP با APP_ENV=local کار می‌کند تا CSRF واقعی فعال بماند. تنظیم MUSIC_MEDIA_ROOT مسیر فضای خصوصی را تعیین می‌کند. خروجی reports و test-results در git ذخیره نمی‌شوند.

تست‌ها در دیتابیس تست اجرا می‌شوند. دادهٔ محیط توسعه را به تست متصل نکنید. CI برای PHP 8.5 و Node 24، SQLite و MySQL تنظیم شده است؛ اجرای محلی MySQL و اجرای GitHub Actions باید در وضعیت بررسی جداگانه ثبت شود.

## قواعد مخزن

`.env`، دیتابیس محلی، صوت‌ها، آپلودها، خروجی build، وابستگی‌ها و دادهٔ مرورگر نباید کامیت شوند. `composer.lock` و `package-lock.json` باید کامیت شوند. تغییرات ساختار دیتابیس با migration جدید انجام می‌گیرد. API و UI یک مبدأ دارند و احراز هویت با session و CSRF انجام می‌شود.

این مخزن به یک سرور PHP نیاز دارد؛ GitHub Pages میزبان مناسبی برای اجرای backend آن نیست. پیکربندی نمونهٔ Nginx و Supervisor در `deploy` موجود است.

## ارتقای این تغییر

پیش از اجرا، [قرارداد ارتقا و محدودیت‌ها](docs/CURRENT_REVIEW.fa.md) را بخوانید: backup، maintenance و توقف worker؛ migration جدید و build تازه، سپس worker و smoke test. role=owner همان سوپرادمین است. administrator/curator دیگر آهنگ یا پلی‌لیست اضافه، ویرایش یا حذف نمی‌کنند. import قدیمی بدون plan_hash باید دوباره preview شود. API نوشتن authenticated به X-Session-Context با actor:access_version نیاز دارد؛ فرانت قدیمی باید تازه‌سازی شود. هیچ‌یک از این مراحل روی سرور کاربر خودکار اجرا نشده است.
