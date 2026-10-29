# پس از F09

این تغییر روی سرور شما خودکار اجرا نشده است. migration تازه فقط ترجیحات رابط را به users اضافه می‌کند؛ دادهٔ صوت و کاتالوگ جابه‌جا نمی‌شود.

پس از backup، طبق روال ارتقای پروژه worker را متوقف و برنامه را در maintenance قرار دهید، سپس:

```bash
git pull --ff-only
composer install --no-dev --prefer-dist --optimize-autoloader
php artisan migrate --force
npm ci
npm run build
php artisan optimize:clear
php artisan queue:restart
php artisan up
```

worker را طبق Supervisor محیط خود دوباره اجرا کنید. ورود عضو و سوپرادمین، تنظیمات زبان/جهت/تم، پخش بدون توقف هنگام تغییر زبان و هشدار خروج از فرم dirty را بررسی کنید. نشست و CSRF همان قرارداد قبلی را دارند. اگر فقط build جدید نصب شود و migration اجرا نشود، ذخیرهٔ ترجیحات درست کار نمی‌کند. پس از تأیید F09، گام بعد F10 برای هویت بصری و جلوهٔ محلی با fallback است.
