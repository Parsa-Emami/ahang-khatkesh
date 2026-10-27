# مهارت‌ها و منابع

## استفاده در این ساخت

- **Sites**: اصول طراحی تجربه، responsive و خوانایی و مسیر بررسی رابط؛ خروجی پروژه Laravel برای GitHub است و روی Sites منتشر نشده است.
- **Laravel Boost / laravel-best-practices**: Actionهای محدود، FormRequest، policy، Resource، migration، قیدها، eager loading و job idempotent.
- **Laravel Boost / testing-best-practices**: تست رفتار و مرز دسترسی، خطاهای ایمپورت، ظرفیت و رقابت revision.
- **راهنمای PHP و Pint در Boost**: PHP 8.5، typeهای بازگشتی و قالب‌بندی ثابت.

راهنماهای تولیدشدهٔ Boost برای ادامهٔ توسعه در `.claude/skills` موجودند. `AGENTS.md` قراردادهای این مخزن را مشخص می‌کند. انتخاب مهارت به مسئله وابسته است؛ اضافه‌کردن ابزار و لایهٔ معماری، معیار کیفیت مستقل محسوب نمی‌شود.

## منابع بررسی‌شده

| منبع | کاربرد در تصمیم |
| --- | --- |
| [Laravel 13 Release Notes](https://laravel.com/docs/13.x/releases) | سازگاری نسخه با PHP 8.5 |
| [Laravel Authentication](https://laravel.com/docs/13.x/authentication) | session برای رابط هم‌مبدأ |
| [Laravel Sanctum](https://laravel.com/docs/13.x/sanctum) | مقایسهٔ SPA auth؛ در این نسخه session وب مستقیم کافی است |
| [Laravel HTTP Responses](https://laravel.com/docs/13.x/responses) | ارائهٔ فایل با پاسخ استاندارد |
| [21st.dev](https://21st.dev/) | الگوهای کارت، ناوبری و تم و زیست‌بوم React |
| [Music Player Card — Ravi Katiyar](https://21st.dev/@ravikatiyar162/components/music-player-card) | ایدهٔ کارت پخش و تم سازگار |
| [Sidebar — Manu Arora](https://21st.dev/@manuarora700/components/sidebar) | ایدهٔ ناوبری responsive و حالت‌های تعاملی |
| [Media Session API](https://developer.mozilla.org/en-US/docs/Web/API/Media_Session_API) | کنترل رسانه با بررسی پشتیبانی مرورگر |

از صفحات 21st.dev برای جهت بصری و تعامل الهام گرفته شده است. کد componentهای دارای source پشت حساب یا مجوز بررسی‌نشده کپی نشده است. UI، کاورهای هندسی و نشان این مخزن پیاده‌سازی اختصاصی‌اند. React، Radix، TanStack Query و Lucide از بسته‌های پروژه استفاده می‌شوند و فونت‌ها در build به‌صورت محلی ارائه می‌شوند.

## منابع تکمیلی و دامنهٔ ادعا در نسخهٔ 0.2

- [Laravel Fortify](https://laravel.com/framework/docs/fortify): TOTP، enrollment و recovery؛ منبع اصلی رفتار framework.
- [Laravel frontend](https://laravel.com/framework/docs/frontend): انتخاب‌های React/Vue/Livewire؛ انتخاب React SPA در این محصول تصمیم معماری است، نه ادعای برتری مطلق.
- [MySQL 8.4](https://dev.mysql.com/doc/refman/8.4/en/): InnoDB، constraint، transaction و indexes.
- [phpMyAdmin](https://www.phpmyadmin.net/downloads/): ابزار مستقل مدیریت DB؛ نسخهٔ stable مشاهده‌شده 5.2.3.
- [OWASP ASVS](https://owasp.org/www-project-application-security-verification-standard/): هدف ردیابی کنترل امنیت؛ گواهی انطباق صادر نشده است.
- [WCAG 2.2](https://www.w3.org/TR/WCAG22/): مبنای معیارهای تعامل؛ ممیزی کامل انجام نشده است.
- Product Design audit برای مشاهدهٔ مرورگر و گزارش محدودیت‌ها استفاده شد؛ طراحی با CSS/React محلی پیاده شده است.
- [Vitest advisory](https://github.com/vitest-dev/vitest/security/advisories/GHSA-qxg6-89mg-rqv8): dependency توسعه به نسخهٔ اصلاح‌شده 4.1.12 ارتقا یافت.
