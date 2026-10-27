# بازبینی معماری و تصمیم ادامهٔ توسعه

تاریخ: ۲۷ اکتبر ۲۰۲۶، منطقهٔ زمانی Asia/Tehran. مبنای بررسی: commit [`b9e62be`](https://github.com/Parsa-Emami/ahang-khatkesh/commit/b9e62be3e0f4e995bc7a96e714677ae4dc8a2403)، نسخهٔ اجرایی 0.2.

**نتیجه: معماری فعلی را بی‌نقص یا آمادهٔ انتشار عملیاتی تأیید نمی‌کنیم.** انتخاب استک و مرزهای اصلی مناسب‌اند؛ سه نقص واقعی در یکپارچگی ایمپورت، ابطال نشست و چرخهٔ فایل بازتولید شدند. قرارداد RTL/LTR سراسری و تبدیل خودکار FLAC نیز هنوز در کد اجرایی کامل نیستند. این تغییر مخزن، طرح هدف و ساختار قابل بررسی برای پیاده‌سازی بعدی را تثبیت می‌کند؛ اصلاح runtime این نقص‌ها را ادعا نمی‌کند.

## روش و حدود شواهد

کد Actionها، controllerها، migrationها، jobها، قرارداد API، state فرانت، منابع CSS و گزارش آزمون نسخهٔ 0.2 بررسی شدند. سه probe تشخیصی در همین جلسه، با PHP 8.5.12، Laravel 13.38.0 و SQLite حافظه‌ای اجرا شدند: ۳ سناریو و ۹ assertion بدون خطای اجرای probe. assertionها رفتار معیوب مشاهده‌شده را تأیید می‌کردند؛ این نتیجه به معنی اصلاح باگ یا موفقیت تست پذیرش نیست. دیتابیس واقعی کاربر استفاده نشد.

دو سناریو با snapshot قدیمی مدل و ترتیب کنترل‌شدهٔ عملیات بازتولید شدند؛ سناریوی فایل با تزریق خطای حذف در filesystem آزمایشی. این‌ها تست همزمانی چندپردازهٔ MySQL یا مشاهدهٔ حملهٔ واقعی نیستند. XML و کد موقت probe به مجموعهٔ تست عادی اضافه نشده‌اند؛ مراحل بازتولید و نتیجه در این سند و [گزارش ماشینی](verification/architecture-review-2026-10-27.json) ثبت شده‌اند. اصلاح بعدی باید تستی بنویسد که رفتار صحیح را مطالبه کند.

گزارش قبلی ۵۲ تست PHP و ۲۷۷ assertion روی SQLite و MySQL و ۵ تست صف فرانت، شواهد نسخهٔ پایه است. سبز بودن آن مجموعه، سناریوهای تازهٔ زیر را پوشش نمی‌دهد. بازبینی بصری جدید، E2E چندزبانه، آزمون ده stream و بازیابی عملیاتی در این مرحله انجام نشده‌اند.

## پوشش و اولویت

P0 پیش از توسعهٔ ویژگی جدید اصلاح شود. P1 پیش از فعال‌کردن قابلیت مرتبط یا انتشار لازم است. «ساختار جهانی» در این پروژه به قرارداد قابل آزمون، دسترس‌پذیری، قابلیت نگهداری و بازیابی قابل اثبات ترجمه می‌شود؛ رتبه یا تضمین بدون خطا نیست.

| شناسه | اولویت | بخش | شاهد | وضعیت runtime | بستهٔ اصلاح |
| --- | --- | --- | --- | --- | --- |
| R01 | P0 | مالکیت فایل و انتشار import | بازتولید شد | باز | A01 |
| R02 | P0 | تغییر رمز و access_version | بازتولید شد | باز | A02 |
| R03 | P0 | کاور پس از خطای cleanup | بازتولید شد | باز | A03 |
| R04 | P1 | حفظ draft فرم در refetch | بررسی کد | باز | A05 |
| R05 | P1 | retry وابسته به هویت نشست | بررسی کد و استنتاج سناریو | باز | A05 |
| R06 | P1 | نسل job، lease و recovery ایمپورت | بررسی کد | باز | A04 |
| R07 | P1 | مسیر یگانهٔ نوشتن کاتالوگ و هویت رسانه | بررسی کد | باز | A01، A03، A08 |
| R08 | P1 | ارتقای MySQL و backup سازگار | بررسی migration و تست | اثبات نشده | A09 |
| R09 | P1 | ظرفیت stream، دیسک و پایش | بررسی کد؛ بارسنجی نشده | اثبات نشده | A10 |
| R10 | P1 | قرارداد پاسخ و آزمون تجربهٔ کامل | بررسی تست‌ها | پوشش ناکافی | A05، A11 |
| R11 | P1 | universal UI، RTL/LTR و 21st.dev | بررسی ساختار فعلی و منابع | طرح هدف افزوده شد | A06، A07، A11 |
| R12 | P1 | FLAC به MP3 320kbps | بررسی کد و آزمایش encoder | pipeline وجود ندارد | A08، A11 |

## R01: اعتبارسنجی مالکیت در لحظهٔ انتشار

منابع: [PreviewPlaylistImport](../app/Actions/PreviewPlaylistImport.php)، [PublishPlaylistImport](../app/Jobs/PublishPlaylistImport.php)، [RegisterTrack](../app/Actions/RegisterTrack.php)، [EnsureTrackCatalog](../app/Actions/EnsureTrackCatalog.php)، [AttachRendition](../app/Actions/AttachRendition.php).

۱. فایل B آزاد است و برای Track تازه در پیش‌نمایش پذیرفته می‌شود. ۲. پیش از اجرای job، B به rendition آهنگ A متصل می‌شود. ۳. job، Track تازهٔ C با media_asset_id برابر B می‌سازد. ۴. track_media همچنان B را متعلق به A نگه می‌دارد و insertOrIgnore تضاد اتصال اصلی C را پنهان می‌کند. import با وضعیت completed پایان می‌یابد.

قفل مشترک، بررسی چند دقیقه قبل را تازه نمی‌کند. طرح اصلاح: یک Action مرجع برای ثبت ضبط و کنترل مجدد تمام مالکیت‌ها، snapshotهای مرتبط و مجوز actor در تراکنش انتشار. insert متعارض باید domain conflict بسازد. تغییر دادهٔ مؤثر، import را needs_review می‌کند و همهٔ نوشتن‌های انتشار rollback می‌شوند. پیش‌نمایش باید متادیتای نهاییِ resolved و policy بازاستفاده از Track موجود را نشان دهد.

## R02: snapshot قدیمی رمز و تکرار نسخهٔ دسترسی

منابع: [SessionController::password](../app/Http/Controllers/Api/SessionController.php)، [ResetMemberPassword](../app/Actions/ResetMemberPassword.php)، [UpdateUserAccess](../app/Actions/UpdateUserAccess.php).

درخواست تغییر رمز با User نسخهٔ V0 در حافظه نگه داشته شد؛ reset مدیر، رمز و access_version دیتابیس را به V1 برد. سپس درخواست قدیمی، رمز پیشین را در برابر همان snapshot تأیید کرد، رمز reset را بازنویسی کرد و دوباره V1 نوشت. پاسخ 200 بود و نشست با همان V1 تازه‌سازی شد. این یک interleaving کنترل‌شده است، نه ادعای اجرای دو درخواست واقعی همزمان.

طرح اصلاح: همهٔ تغییرهای رمز، MFA و نقش از قرارداد مشترک امنیت استفاده کنند؛ actor، epoch ارائه‌شده و credential snapshot در قفل جاری بررسی شوند. نسخه همیشه از مقدار تازهٔ DB افزایش یابد. درخواست منقضی، مجاز به تغییر رمز یا تأیید نشست جدید نیست. محاسبهٔ پرهزینهٔ hash بیرون از قفل سراسری و تطبیق version/hash درون تراکنش انجام شود؛ audit اتمیک باشد.

## R03: cleanup پس از commit، فایل جدید را حذف می‌کند

منابع: [PlaylistController::cover](../app/Http/Controllers/Api/Admin/PlaylistController.php)، [filesystems](../config/filesystems.php).

اشاره‌گر کاور جدید در DB commit شد؛ حذف کاور قبلی در afterCommit عمداً خطا داد. catch بیرونی، فایل جدید را حذف کرد. نتیجهٔ مشاهده‌شده: cover_path جدید در DB وجود داشت، خود فایل وجود نداشت. فایل و DB تراکنش مشترک ندارند.

طرح اصلاح: فایل immutable ابتدا در فضای خصوصی نوشته و اعتبارسنجی شود؛ تغییر اشاره‌گر و job پاک‌سازی قبلی در یک تراکنش DB ثبت شوند. شکست housekeeping فقط retry همان cleanup را ایجاد کند. cleanup پیش از حذف، نبود ارجاع و دورهٔ نگهداری را مجدد بررسی کند. پس از خطای مبهم commit، فایل را کورکورانه حذف نکنید؛ orphan قابل بازیابی از ارجاع شکسته کم‌خطرتر است.

## R04 تا R10: شکاف‌های تکمیلی

| مورد | وضعیت مشاهده‌شده | قرارداد اصلاح |
| --- | --- | --- |
| R04 | useEffect در [PlaylistTracksEditor](../resources/js/features/admin/PlaylistTracksEditor.tsx) دادهٔ تازهٔ query را بی‌قید روی draft می‌نویسد | baseRevision، dirty و remoteUpdateAvailable مستقل؛ 409 یا reconnect نباید draft را پاک کند |
| R05 | [api.ts](../resources/js/lib/api.ts) پس از 419 فقط CSRF را می‌گیرد و mutation قبلی را retry می‌کند؛ نشست چندتب همگام نیست | retry فقط برای همان actor و epoch؛ تغییر حساب، pending intent و cache حساب قبلی را باطل کند |
| R06 | processing هم queued و هم executing است؛ failed بدون generation می‌نویسد | generation، lease، fencing، reconciliation و خطاهای دائمی جدا از retry موقت |
| R07 | hook در [Track](../app/Models/Track.php) و مسیر saveQuietly، مرزهای نوشتن متفاوت دارند؛ asset هویت باینری و مالکیت ضبط را ترکیب می‌کند | Action مرجع، checker یکپارچگی و جداسازی blob فیزیکی از asset معنایی پیش از مشتق‌سازی |
| R08 | [migration کاتالوگ](../database/migrations/2026_10_27_000002_normalize_music_catalog.php) DDL و backfill را ترکیب می‌کند؛ [upgrade test](../tests/Feature/CatalogUpgradeTest.php) SQLite مستقل است | expand/backfill/verify/contract، تست ارتقای واقعی MySQL و restore هماهنگ DB و رسانه |
| R09 | [StreamController](../app/Http/Controllers/Api/StreamController.php) بایت‌ها را از PHP می‌دهد؛ فقط سقف هر فایل وجود دارد | X-Accel داخلی پس از مجوز Laravel، quota/reservation، اندازه‌گیری ده stream و ظرفیت مدیریت |
| R10 | [ApiContractTest](../tests/Feature/ApiContractTest.php) مسیر و method را می‌سنجد؛ schema پاسخ و تجربهٔ مرورگر کامل را نمی‌سنجد | type تولیدشده از OpenAPI، response validation و E2E هویت/خطا/چندتب/دسترسی‌پذیری |

در R04، refetchOnWindowFocus در [main.tsx](../resources/js/main.tsx) صریحاً false است؛ این یافته دربارهٔ پاسخ تغییرکردهٔ reconnect، invalidation یا درخواست درحال‌اجرا است. structural sharing برای پاسخ واقعاً یکسان، الزاماً effect تازه ایجاد نمی‌کند. در R05، مجوز سرور همچنان اعمال می‌شود؛ خطر، اجرای intent قدیمی با حساب دیگری است که خودش مجاز است، نه دورزدن قطعی authorization.

## R11 و R12: درخواست تازه و معیار پیاده‌سازی

نسخهٔ 0.2 فارسی و RTL است؛ ترجمهٔ کامل انگلیسی، locale provider واحد، پوشش تمام CSSهای فیزیکی و آزمون چهار ترکیب جهت/تم هنوز اثبات نشده‌اند. [قرارداد universal](UNIVERSAL_UI.fa.md) و [کاتالوگ کامپوننت](COMPONENT_CATALOG.fa.md) معیار مشترک همهٔ مسیرها را تعیین می‌کنند. Lucide از خانواده‌های فهرست‌شده در 21st.dev است. برای shader، Dither Flow با منبع و مجوز pin شده انتخاب شده؛ ادغام کد و تست GPU هنوز انجام نشده است.

تبدیل خودکار FLAC در runtime وجود ندارد. پروفایل پیشنهادی libmp3lame با CBR برابر 320000bps روی سه FLAC مصنوعی ۳ثانیه‌ای آزمایش شد: stereo 44.1kHz، mono 48kHz و stereo 96kHz با خروجی 44.1kHz. هر سه MP3 کامل decode شدند و stream bitrate برابر 320000 بود. FLAC نامعتبر رد شد ولی یک فایل staging صفر بایت باقی گذاشت؛ بنابراین وجود فایل، نشانهٔ موفقیت نیست. این آزمایش، queue، مجوز، فایل واقعی کاربر یا ظرفیت production را اثبات نمی‌کند. قرارداد کامل در [خط لولهٔ رسانه](MEDIA_PIPELINE.fa.md) است.

## تصمیم‌هایی که حفظ می‌شوند

Laravel 13/PHP 8.5، React/TypeScript/Vite، MySQL 8.4، modular monolith، API داخلی همان origin، session/CSRF/Fortify، صف دیتابیس مشترک، فایل خصوصی، شناسهٔ پایدار Track، capability و optimistic revision مناسب دامنه‌اند. phpMyAdmin ابزار اپراتور باقی می‌ماند. افزودن microservice، Kubernetes، HLS یا event sourcing بدون نیاز اندازه‌گیری‌شده، این نقص‌ها را رفع نمی‌کند.

**شرط شروع توسعهٔ بعدی:** قراردادهای [معماری هدف](ARCHITECTURE_TARGET.fa.md) و [نقشهٔ پیاده‌سازی](IMPLEMENTATION_ROADMAP.fa.md) مبنا قرار گیرند؛ ابتدا A01 تا A03، سپس قابلیت‌های universal و تبدیل صوت با وابستگی‌های مشخص اجرا شوند. تأیید production پس از بسته‌شدن یافته‌ها و ثبت شواهد A11 ممکن است.
