> F09 و F01 تا F08 با [CI موفق 40858380705](https://github.com/Parsa-Emami/ahang-khatkesh/actions/runs/40858380705) و ۱۳۴ تست PHP روی هر دو SQLite و MySQL، ۳۹ تست فرانت، ۱۸ سناریوی واقعی مرورگر و ۹ تست چندپردازهٔ MySQL تأیید شده‌اند. [شواهد جاری](verification/foundation-through-f09-2026-10-29.json) و [قرارداد F09](F09_LOCALE_AND_ACCESSIBILITY.fa.md) مقدم‌اند؛ بندهای تاریخی پایین سابقهٔ پایان F08 را نگه می‌دارند. گام بعد F10 است.

> تأیید F01 تا F08 پس از اصلاح F08-B10 و ارتقای امنیتی F08-B11 روی commit `e9e5c6a3ca2a34f0fe11f226600aa445856d0b2b` با [CI موفق همان کد](https://github.com/Parsa-Emami/ahang-khatkesh/actions/runs/40824157871) بسته شد: ۱۲۸ تست PHP روی هر دو DB، ۳۰ تست فرانت، ۱۲ سناریوی مرورگر و ۹ تست چندپردازهٔ MySQL. symfony/mime از 8.1.7 به 8.1.10 برای CVE-2026-45070 ارتقا یافت و composer audit در هر دو job پاس شد. چهار تست batch همچنان مقیدبودن آپلود به actor/epoch آغازین را پوشش می‌دهند. [قرارداد F08](F08_AUDIO_CONVERSION.fa.md) و [شواهد جاری](verification/foundation-through-f08-2026-10-29-security.json) مقدم‌اند؛ گام بعدی F09 است.

# وضعیت اجرایی و نقص‌های باقی‌مانده

> F01 تا F07 با CI موفق همان commit تأیید شدند. F07 چرخهٔ blob، quota، رزرو، backfill، GC و lease/recovery را به runtime افزوده است. N01 تا N03 و R06/R07 در دامنهٔ قرارداد فعلی بسته‌اند؛ [قرارداد F07](F07_STORAGE_AND_RECOVERY.fa.md) و [evidence](verification/foundation-through-f07-2026-10-28.json) مرجع‌اند. جدول اصلاح‌های زیر سابقهٔ قبل از F07 را نیز نگه می‌دارد. این بند سابقهٔ پایان F07 است؛ گام جاری پس از F08، F09 است.


مبنای این دور، commit `9f58be08fe4c915b35535c6f944f2d7455bcb063` است. این سند وضعیت تازه را توصیف می‌کند؛ گزارش `ARCHITECTURE_REVIEW.fa.md` سند تاریخی رفتار نسخهٔ 0.2 باقی می‌ماند. وضعیت تأیید نهایی و SHA آزمون‌شده از [رودمپ](ROADMAP.fa.md) و evidence مرتبط خوانده شود.

## اصلاح‌های اجرایی

| موضوع | رفتار تازه | شاهد الزامی |
| --- | --- | --- |
| مرز کاتالوگ | فقط owner فعال با عنوان سوپرادمین؛ همه roleهای دیگر بدون نوشتن song/playlist/media/import | SuperAdministratorTest و مرورگر |
| R01 | effective plan و snapshot؛ recheck پیش از انتشار؛ تضاد به needs_review و rollback کامل می‌رسد | ImportPublicationIntegrityTest |
| R02 | کنترل حساب/hash/epoch جاری در تراکنش؛ snapshot قدیمی reset تازه را بازنویسی نمی‌کند | CredentialEpochTest، MfaFlowTest |
| R03 | pointer و cleanup job اتمیک؛ شکست حذف فایل قبلی فایل جدید را حذف نمی‌کند | MediaLifecycleTest با commit واقعی و failure تزریقی |
| R04 | draft محلی dirty با refetch/reconnect حفظ می‌شود؛ discard صریح | versioned-draft و مرورگر |
| R05 | replay فقط برای actor و epoch همان intent؛ context header اجباری برای mutation | api.test، ApiResponseSchemaTest |
| بخشی از R06 | generation برای handle و failed، لغو با تغییر دسترسی سازنده | تست تلاش قدیمی و جدید import |
| بخشی از R07 | hook نوشتن پنهان Track حذف و RegisterTrack مسیر مرجع شده؛ تضاد insert پنهان نمی‌شود | تست ثبت و بازاستفاده و تضاد رسانه |
| بخشی از R10 | schema واقعی پاسخ با Opis و مسیرهای مرورگر اضافه شده | ApiResponseSchemaTest و Playwright |
| قابلیت پخش | صف شخصی قابل ویرایش، play-next، shuffle متعادل، تایمر خواب | Vitest و پخش WAV مصنوعی در مرورگر |
| خطای تازه در restore | تغییر نام هنرمند هنگام trash در بازیابی از رابطهٔ canonical تازه می‌شود | تست restore در SuperAdministratorTest |

پس از F07، نقص مشخص R06/R07 با تست واقعی چندپردازهٔ MySQL بسته شده است؛ R10 و معیارهای گستردهٔ انتشار همچنان بازند. تست deterministic، تست چندپردازه و بارسنجی سه شاهد متفاوت‌اند؛ هیچ‌کدام جای دیگری را نمی‌گیرد.

## مرز مجوزهای فعلی

| نقش | کاتالوگ، رسانه، ایمپورت، حذف و بازیابی | مدیریت حساب‌ها | پنل | پخش و کتابخانهٔ شخصی |
| --- | --- | --- | --- | --- |
| سوپرادمین / owner | کامل، با MFA و step-up در نوشتن | کامل، با حفظ آخرین owner فعال | کامل | کامل |
| administrator / مدیر اعضا | ممنوع | listener/curator؛ بدون اعطای نقش privileged یا تغییر owner | اعضا، overview، audit و operations طبق capability | فقط محتوای منتشرشده |
| curator / ناظر محتوا | ممنوع | ممنوع | overview و امنیت حساب | فقط محتوای منتشرشده |
| listener | ممنوع | ممنوع | بدون پنل مدیریت | فقط محتوای منتشرشده |

حذف از صف یا برداشتن لایک، حذف آهنگ از کاتالوگ نیست. ذخیرهٔ پلی‌لیست شخصی فقط bookmark است. همکاری آینده نیز مسیر «پیشنهاد ← تأیید سوپرادمین» دارد و مجوز ایجاد/حذف عمومی به عضو نمی‌دهد.

کنترل backend در route و Action هر دو وجود دارد. قفل global سپس حساب تازه و entity، نوشتن‌های حساس را serialize می‌کند. سرور session، CSRF، MFA، capability و access_version را مستقل از UI بررسی می‌کند. `X-Session-Context` دادهٔ عمومی actor:epoch است و جای این کنترل‌ها را نمی‌گیرد. mutation احرازشده بدون آن 428 و با هویت ناهماهنگ 409 است.

## کارهای باز پیش از انتشار

| شناسه | اولویت | نقص یا عدم اثبات | اقدام بعدی |
| --- | --- | --- | --- |
| N01 / R06 | بسته در دامنهٔ F07 | recovery و fencing با kill واقعی worker پاس شد | evidence F07 |
| N02 / R07 | بسته در دامنهٔ F07 | blob مستقل، backfill و integrity checker با آزمون dedup هم‌زمان پاس شد | evidence F07 |
| N03 / بخشی از R09 | بسته در دامنهٔ F07 | quota، reservation، cleanup و GC با retention پاس شدند؛ load/offload باقی است | F15 |
| N04 / R12 | بسته در دامنهٔ F08 | تبدیل واقعی، guard، سهمیه، recovery، پنل و کیفیت با تست واقعی پاس شدند | evidence F08 |
| N05 / R11 | بسته در دامنهٔ F09 | کاتالوگ fa/en و جهت مستقل، ترجیحات حساب و ماتریس مرورگر اضافه شده‌اند | F09 |
| N06 / R11 | P2 | shader 21st.dev فقط منبع pin شده دارد؛ ورود runtime، GPU budget و visual matrix باقی است | F10 |
| N07 | P2 | تاریخچه، private session و پیشنهاد توضیح‌پذیر هنوز ساخته نشده‌اند | F11 |
| N08 | P2 | bulk operations، repair/merge و پیشنهاد اعضا workflow کامل ندارند | F12 |
| N09 | P2 | متن همگام، crossfade، gapless و loudness analysis نیستند | F13 |
| N10 | P2 | کنترل دستگاه فعال و آفلاین اختیاری وجود ندارند؛ تعارض playback فقط revision دارد | F14 |
| N11 / R08 | P1 | ارتقای نمونهٔ واقعی نسخهٔ قبلی در MySQL و restore هماهنگ DB و رسانه اثبات نشده | F15 |
| N12 / R09 | P1 | X-Accel مجاز و ده stream هم‌زمان با import/conversion بارسنجی نشده‌اند | F15 |
| N13 / R10 | P1 | schema response برای مسیرهای منتخب است؛ تمام APIها و type generation کامل نشده‌اند | F16 |
| N14 | بسته در دامنهٔ F09 | محافظ مشترک route/back/unload و بستن dialog به فرم‌های ویرایش متصل شده است | F09 |
| N15 | P1 | ASVS mapping کامل، pentest مستقل، Safari/Firefox، دستگاه فیزیکی و صفحه‌خوان آزمون نشده‌اند | F16 |
| N16 | P2 | تایمر خواب فقط در تب فعلی است؛ زمان دقیق در suspend سیستم تضمین ندارد | F14، F16 |

P1 یعنی پیش از production یا فعال‌کردن قابلیت وابسته حل شود؛ به معنی وقوع قطعی حمله نیست. این فهرست، نقص‌های شناخته‌شده و شواهدِ موجود را پوشش می‌دهد و تضمین کشف تمام نقص‌های ممکن نیست.

## قواعد داده و عملیات

MySQL 8.4/InnoDB/utf8mb4، migration افزایشی، کلید خارجی، revision و فایل خصوصی حفظ می‌شوند. phpMyAdmin برای اپراتور، بررسی ساختار و بکاپ است. ویرایش مستقیم جدول‌های محصول، Actionها، audit و invariantها را دور می‌زند؛ مدیریت روزمره آهنگ و playlist باید از پنل برنامه انجام شود. حساب runtime دیتابیس با حساب migration/اپراتور جدا باشد و phpMyAdmin از اینترنت عمومی در دسترس نباشد.

این نسخه به ارتقای کنترل‌شده نیاز دارد: backup، توقف worker، maintenance، اجرای migration تازه، ساخت bundle تازه، راه‌اندازی worker و smoke test. importهای آماده/failed قدیمی که plan_hash ندارند باید دوباره preview شوند. processingهای نسخهٔ قبلی بدون نسل معتبر نباید با payload قدیمی دوباره queue شوند. مقدار role=owner به‌خاطر سازگاری داده عوض نشده است؛ مجوز کاتالوگ administrator/curator عمداً کاهش یافته است. bundle قدیمی به دلیل context header برای نوشتن پذیرفته نمی‌شود؛ تازه‌سازی کامل رابط لازم است.

حذف فعلی قابل بازیابی است. حذف فیزیکی فایل یا دادهٔ حساب، purge خودکار، وعدهٔ پاک‌شدن آفلاین از راه دور و نگهداری نامحدود تاریخچه ارائه نشده‌اند.

## ارزیابی کیفیت

انتخاب modular monolith برای ده حساب، Laravel و React و MySQL و private storage قابل دفاع است. افزایش تعداد service یا نصب Kubernetes معیار کیفیت نیست. معیار این پروژه: مجوز قابل آزمون، صحت داده زیر failure، رابط قابل استفاده، دسترس‌پذیری، بازیابی و عملیات قابل اندازه‌گیری است. رسیدن به کیفیت محصول جدی ممکن است؛ هم‌ترازی فعلی با Apple Music/Spotify یا ادعای امن‌ترین/بی‌نقص‌ترین نرم‌افزار قابل تأیید نیست. پس از F08، فازهای F09 تا F16 و بازبینی مستقل باقی‌اند.
