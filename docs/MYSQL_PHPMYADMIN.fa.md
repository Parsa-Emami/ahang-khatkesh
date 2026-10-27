# راه‌اندازی MySQL و مدیریت با phpMyAdmin

MySQL دیتابیس برنامه است؛ phpMyAdmin رابط وب اپراتور برای همان دیتابیس است. برنامه از طریق PDO/Eloquent به MySQL وصل می‌شود و به API phpMyAdmin وابسته نیست. نسخهٔ هدف MySQL 8.4 LTS، engine برابر InnoDB، charset برابر utf8mb4 و collation پیش‌فرض utf8mb4_0900_ai_ci است.

## مسیر راه‌اندازی

1. در phpMyAdmin با حساب اپراتور DB وارد شوید؛ دیتابیس `ahang_khatkesh` را با utf8mb4 بسازید.
2. حساب migration جدا با مجوز DDL محدود به همین schema و حساب runtime به نام `ahang_app` با SELECT/INSERT/UPDATE/DELETE روی همین schema بسازید. رمزها را در مخزن ننویسید؛ اتصال runtime با root انجام نشود.
3. `.env.example` را به `.env` کپی و DB_HOST، DB_PORT، DB_DATABASE، DB_USERNAME و DB_PASSWORD را تنظیم کنید. برای migration از credential مخصوص استقرار و سپس برای وب از credential runtime استفاده کنید. در DB خارجی، TLS و `MYSQL_ATTR_SSL_CA` تنظیم شود.
4. `composer install`، `php artisan key:generate` و `php artisan migrate --seed` را اجرا کنید. ساختار جداول را از روی interface به‌صورت موازی بازسازی نکنید؛ migration مرجع است.
5. `npm ci`، `npm run build` و `php artisan app:create-admin`؛ اولین مدیر، در نبود مالک فعال به مالک تبدیل می‌شود. رمز به‌صورت تعاملی گرفته می‌شود.
6. `npm run dev` را اجرا و `http://localhost:5173` را باز کنید؛ مالک باید رمزساز را تأیید و کدهای بازیابی را ذخیره کند.

`.env.example` اکنون MySQL را پیش‌فرض دارد. برای توسعهٔ موقت SQLite باید DB_CONNECTION=sqlite و DB_DATABASE برابر مسیر مطلق فایل باشد. تست‌های SQLite از in-memory استفاده می‌کنند و انتخاب production را تغییر نمی‌دهند.

## گزینهٔ Docker برای دیتابیس محلی

`compose.dev.yml` فقط MySQL و phpMyAdmin محلی را اجرا می‌کند؛ Laravel روی میزبان توسعه اجرا می‌شود. Docker برای استفاده از پروژه اجباری نیست. قبل از اجرا، MYSQL_ROOT_PASSWORD و DB_PASSWORD را در محیط محلی خود تعیین کنید؛ مقدار خالی پذیرفته نمی‌شود.

```bash
docker compose -f compose.dev.yml up -d
```

MySQL روی 127.0.0.1:3306 و phpMyAdmin روی 127.0.0.1:8081 است. اگر این پورت‌ها قبلاً استفاده می‌شوند mapping را تغییر دهید و DB_PORT را هماهنگ کنید. ورود phpMyAdmin با حساب DB انجام می‌شود؛ ورود خودکار با رمز در compose وجود ندارد. حساب ساخته‌شده توسط image برای توسعه مجوز schema دارد؛ تفکیک runtime/migrator را برای production طبق بالا انجام دهید. اجرای Docker این فایل در این محیط آزموده نشده است.

## چه چیزهایی را از phpMyAdmin مدیریت کنیم

مشاهدهٔ schema و FK، EXPLAIN خواندنی، کنترل حجم، export/backup و بررسی دادهٔ غیرمحرمانه برای اپراتور مناسب است. فایل موسیقی را از phpMyAdmin وارد نکنید؛ از پنل برنامه و FFprobe استفاده کنید. role، MFA، revision، search_text، pivot position و ردیف قفل را با SQL دستی تغییر ندهید؛ این کار Actionها، audit و invariantها را دور می‌زند. نام فارسی و نام مشابه مجازند؛ برای تشخیص هویت از ID و روابط استفاده کنید.

phpMyAdmin را در production روی شبکهٔ عمومی رها نکنید؛ فقط مسیر مدیریتی محدود/VPN و HTTPS. رمز app، APP_KEY، secretهای MFA و recovery دادهٔ پشتیبان حساس‌اند. دسترسی اپراتور schema از دسترسی ادمین داخل برنامه جداست.

## پشتیبان و بازیابی

export شامل ساختار و داده با transaction سازگار و مجموعهٔ فایل‌های خصوصی را نگه دارید؛ APP_KEY جدا و امن لازم است. snapshot دیتابیس به‌تنهایی فایل‌ها را برنمی‌گرداند. قبل از upgrade backup و در staging بازیابی واقعی انجام شود؛ RPO و RTO بر اساس نتیجه تعیین شوند. حذف volume در Docker، پاک‌کردن DB یا `migrate:fresh` روی دادهٔ واقعی روش ارتقا نیست.

در این تغییر، مجموعهٔ تست روی MySQL Community 8.4.13 واقعی و PHP 8.5.12 اجرا شده است؛ نصب phpMyAdmin و مسیر مدیریتی production روی میزبان شما هنوز اجرا نشده‌اند.
