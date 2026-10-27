# معماری فرانت‌اند و کامپوننت‌ها

## مرز state و API

React 19 و TypeScript روی Vite اجرا می‌شوند؛ تولید نهایی را Blade لاراول بارگذاری می‌کند. در توسعه، index.html پوستهٔ همان SPA است و `/api` به Laravel محلی proxy می‌شود. تمام عملیات تجاری با API همان مبدأ انجام می‌گیرند. مرورگر به MySQL، phpMyAdmin یا مسیر واقعی فایل صوتی وصل نمی‌شود.

| state | محل | عمر |
| --- | --- | --- |
| session و قابلیت‌ها | SessionProvider / Query | نشست جاری؛ پاک‌سازی cache هنگام خروج/انقضا |
| کاتالوگ، پلی‌لیست و عملیات | TanStack Query | کلید شامل entity و filter/page؛ invalidation پس از mutation |
| Audio، queue و progress | PlayerProvider بالای Routes | مستقل از تعویض صفحه؛ progress از Audio event |
| فرم و dialog | state محلی component | پاک‌سازی با بستن فرم یا unmount |
| رمز، OTP، کلید QR و recovery | state موقت | در Query cache یا storage ذخیره نمی‌شوند |
| theme | ThemeProvider + localStorage + پروفایل | فقط ترجیح light/dark/system؛ credential نیست |

API helper، CSRF، 419 refresh، session expiry و خطای ساختاری را هماهنگ می‌کند. برای 423 یک Dialog مرکزی رمز و OTP می‌گیرد و درخواست ردشده را یک بار تکرار می‌کند. 409 به‌معنی حفظ نسخهٔ سرور است؛ کاربر باید دادهٔ تازه را بخواند. تکرار خودکار mutation روی خطای شبکه به شکل عمومی انجام نمی‌شود؛ import از Idempotency-Key استفاده می‌کند.

## پوشه‌بندی

- `resources/js/components`: Brand، Artwork، Dialog، Buttonها، Music و تنظیمات مشترک.
- `context`: session/theme/toast و PlayerProvider.
- `features/security`: ورود مجدد، enrollment و recovery؛ response محرمانه cache نمی‌شود.
- `features/admin`: صفحهٔ lazy، قراردادها و query keyها، overview، imports، playlist arrangement، catalog editors، اعضا، audit و operations.
- `pages`: ورود، کتابخانه، جستجو، پسندیده‌ها و جزئیات پلی‌لیست.
- `lib/api.ts`: HTTP همان مبدأ و سازگاری CSRF.
- `resources/css`: tokenهای مشترک، تم و layout؛ CSS پنل lazy و CSS امنیت مشترک.

پنل ادمین یک chunk جداست؛ کد player با route remount نمی‌شود. UI نقش‌ها را از `permissions` می‌خواند. پنهان‌کردن دکمه امنیت نیست؛ Laravel همان capability را اعمال می‌کند. `is_admin` برای نمایش دسترسی جدید استفاده نمی‌شود.

## فهرست کامپوننت و رفتار

| سطح | کامپوننت | حالت‌های لازم |
| --- | --- | --- |
| هویت | LoginPage | رمز، challenge، recovery، انتظار، خطا و بازگشت |
| امنیت | MfaPanel / SessionConfirmation | enrollment ناتمام، تأیید، نمایش موقت کدها، cancel و انقضا |
| پوسته | AppLayout / sidebar / header | جستجو، theme، نقش و منوی جمع‌شونده |
| محتوا | PlaylistCard / TrackRow / Artwork | بدون کاور، خطای تصویر، خالی و دادهٔ واقعی |
| پخش | PlayerBar / queue / NowPlaying | آماده‌نبودن، پخش/توقف، seek، volume، خطای stream |
| مدیریت | AdminPage / OverviewPanel | منوی capability، آمار واقعی و راهنمای کار بعدی |
| ورود فایل | ImportsPanel | انتخاب فایل، آپلود جداگانه، خطای هر فایل، preview، confirm و سابقه |
| ترتیب | PlaylistTracksEditor | جستجو، اضافه/برداشتن، بالا/پایین، سقف، conflict |
| کاتالوگ | Artist/Release/Track editors | هویت مستقل، اعتبار هنری، دیسک/شماره و revision |
| اعضا | MembersPanel / password reset | ظرفیت، نقش، فعال‌سازی و ابطال نشست |
| پایش | AuditPanel / OperationsPanel | فیلتر UTC، cursor، metadata محدود، refresh و unavailable |

## دسترس‌پذیری و کیفیت

فارسی و RTL در ریشه؛ شناسه، کد و متن لاتین با dir مناسب. Dialog از Radix استفاده می‌کند؛ focus در فرم روی نخستین فیلد می‌رود، Escape و بازگشت focus حفظ می‌شود. ترتیب آهنگ فقط drag نیست و با دکمهٔ نام‌دار/صفحه‌کلید هم تغییر می‌کند. error با alert، نتیجه با status و loading با متن ارائه می‌شود. skip link، focus-visible، prefers-reduced-motion و دکمه‌های نام‌دار وجود دارند.

رنگ از token معنایی می‌آید؛ افزودن component نباید رنگ متن/سطح را hard-code کند. آیکون یا رنگ تنها نشان وضعیت نیست. نمودار waveform ساختگی برای نشان‌دادن پخش واقعی استفاده نمی‌شود. تصویرهای Artwork کد SVG محلی‌اند و فایل بیرونی لازم ندارند.

بازبینی مرورگر دسکتاپ با داده و نشست ساختگی محلی انجام شده است. تست کامل گوشی، screen reader، همهٔ نسبت‌های contrast و همهٔ حالت‌های WCAG انجام نشده و گواهی WCAG 2.2 AA ادعا نمی‌شود. ارزیابی مستقل و تست کاربر واقعی جزو شرط انتشار است.
