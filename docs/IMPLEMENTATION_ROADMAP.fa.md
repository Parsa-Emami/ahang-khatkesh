# نقشهٔ پیاده‌سازی پس از بازبینی

> به‌روزرسانی: این سند سابقه/طرح نسخهٔ قبلی است. وضعیت اجرایی و مجوزهای تازه در [بازبینی جاری](CURRENT_REVIEW.fa.md) و ترتیب کار و شواهد در [رودمپ ۱۶ فازی](ROADMAP.fa.md) مشخص شده‌اند. در تعارض مجوزها، کاتالوگ فقط در اختیار سوپرادمین است.


وضعیت این commit: طراحی و ساختار هدف تکمیل و قابل بررسی است؛ **همهٔ بسته‌های A01 تا A11 هنوز planned هستند**. نسخهٔ runtime همچنان 0.2 است. افزایش تعداد سند یا موفقیت checker ساختار، نقص runtime را نمی‌بندد. اولویت بعدی A01، سپس A02 و A03 است؛ فعال‌کردن FLAC conversion و قابلیت‌های تازه پس از رفع این سه blocker انجام شود.

| بسته | وابستگی | مسئله و خروجی قابل تحویل | معیار بستن |
| --- | --- | --- | --- |
| A01 | ندارد | R01/R07؛ Action مرجع recording، final gate ایمپورت، effective plan و checker کاتالوگ | ownership تغییرکرده، insert متعارض و revision قدیمی هیچ انتشار جزئی نسازند؛ metadata preview مطابق نتیجه باشد |
| A02 | ندارد | R02؛ Action مشترک تغییر رمز/reset/MFA/role با fresh epoch و audit | درخواست قدیمی پس از reset رد شود؛ version همیشه صعودی؛ credential و session stamp از snapshot یکسان |
| A03 | A01 | R03/R07؛ immutable file lifecycle، durable cleanup، blob/asset split و quota پایه | خطای حذف قبلی، فایل مرجع جدید را حذف نکند؛ GC همزمان با attach امن؛ cutover blob با حفظ ID |
| A04 | A01، A02 | R06؛ generation/lease و reconciler import؛ retry دائمی/موقت جدا | stale failed و duplicate dispatch نسل تازه را تغییر ندهند؛ orphan run بازیابی و فقط یک playlist ساخته شود |
| A05 | A01، A02، A04 | R04/R05/R10؛ draft state، session context، ApiError و typeهای OpenAPI | refetch/409 draft را حفظ کند؛ account switch و 419/423 intent قبلی را replay نکنند؛ schema پاسخ تست شود |
| A06 | A05 | R11؛ locale provider، fa-IR/en-US، CSS منطقی، token و primitiveهای universal | parity ترجمه، keyboard و direction در تمام مسیرها؛ تغییر locale/theme player را reset نکند |
| A07 | A06 | R11؛ UiIcon و shader انتخاب‌شدهٔ 21st.dev با wrapper و notice | مجوز pin شده، بدون runtime ثالث؛ fallback/reduced motion/context loss؛ بودجهٔ جلوه و contrast واقعی |
| A08 | A01، A02، A03، A04، A05، A06 | R12/R07؛ conversion و reservation، FFmpeg adapter، worker/API و UI | MP3 واقعی CBR320، lineage صحیح، source محفوظ، duplicate/crash/revoke/quota امن؛ هر fixture کامل decode شود |
| A09 | A03، A04، A08 | R08؛ upgrade واقعی MySQL، backfill resumable و restore هماهنگ | ارتقای دیتای قبلی، قطع backfill و restore ایزوله با hash/MFA موفق و زمان‌های واقعی ثبت شوند |
| A10 | A03، A08 | R09؛ X-Accel، readiness/heartbeat و تست ظرفیت | ده stream با seek و مدیریت همزمان؛ location داخلی غیرقابل دسترسی؛ Range/HEAD/416 و quota پایدار |
| A11 | A01 تا A10 | R10/R11/R12؛ release evidence، امنیت، E2E و دسترس‌پذیری | یافتهٔ P0/P1 باز مرتبط با انتشار نباشد؛ ماتریس مرورگر/locale/theme، MySQL concurrency، restore و load با شاهد |

## مسیر فایل‌ها و تغییرهای هر بسته

A01: RegisterTrack، EnsureTrackCatalog، Track::booted، PreviewPlaylistImport و PublishPlaylistImport یک قرارداد نوشتن داشته باشند. parser و Resource فقط دادهٔ محدود را جابه‌جا کنند. fixture/factory و CreateDemoLibrary نیز مسیر معتبر را رعایت کنند. migration جدید برای plan_version/plan_hash، resolved rows و revisionهای لازم؛ هیچ migration قدیمی بازنویسی نشود. تست R01 باید رفتار صحیح را assertion کند، نه بازتولید موفق رفتار معیوب را.

A02: SessionController::password به Action منتقل و با ResetMemberPassword، UpdateUserAccess و MfaController هم‌قرارداد شود. هزینهٔ hash خارج قفل و compare نسخه/hash داخل آن؛ rate limit حساب و IP. تست stale snapshot، دو reset و revoke همزمان روی MySQL چند connection؛ پاسخ و پاک‌سازی session و recovery نیز بررسی شوند.

A03: PlaylistController::cover، MediaController و PrivateMedia از پروتکل مشترک reference publish استفاده کنند. migrationهای expand برای media_blobs، FKهای nullable و reservation؛ backfill جدا، checker و cutover مرحله‌ای؛ unique SHA قدیمی فقط پس از verify مناسب حذف شود. یک CleanupMedia job با مرجع‌یابی و fencing، dry-run و نگهداری backup barrier. fault injection پس از commit با تست بدون outer transaction؛ فایل master و کاور جدید باید موجود بمانند.

A04: PlaylistImport، ImportController و PublishPlaylistImport وضعیت و generation/lease بگیرند؛ فرمان reconciliation برای اپراتور و schedule کنترل‌شده افزوده شود. queue payload شامل ID و generation؛ همهٔ callbackها fence. مدت lease، timeout و retry_after به‌صورت یک مجموعه کنترل شوند. recovery به محتوای serialized جدول jobs وابسته نباشد.

A05: lib/api.ts، SessionProvider، PlaylistTracksEditor و hookهای query بازطراحی هدفمند شوند. session context و abort propagation واحد؛ broadcast فقط اعلان؛ 401/409/419/423/429 قرارداد مشخص. type شبکه از docs/openapi.json تولید و Resource success/error با schema اعتبارسنجی شود. ابزار تولید type و ابزار browser test با نسخه و peer dependency سازگار وارد lockfile شوند؛ type دست‌نویس موازی مرجع باقی نماند.

A06: مسیرهای i18n و components/ui طبق [UNIVERSAL_UI](UNIVERSAL_UI.fa.md) و [کاتالوگ](COMPONENT_CATALOG.fa.md) ساخته شوند؛ ابتدا پایه/امنیت، سپس صفحات listener و تمام پنل. extraction و ترجمهٔ کامل هر دو زبان، شامل aria/toast/validation/empty، شرط عبور است. backend locale allowlist و UTC پایدار؛ player، draft و server state با locale remount نشوند. CSS فیزیکی به‌تدریج و با diff تصویری اصلاح شود.

A07: source و CSS Dither Flow در commit pin شده بازبینی و در محل اختصاصی vendor با license کامل وارد شوند؛ wrapper محصول رفتار motion، theme، visibility و cleanup را اعمال کند. UiIcon فقط آیکون‌های مصرف‌شدهٔ Lucide را import کند. تست بدون WebGL و حالت کم‌تحرک باید پخش و فرم را سالم نگه دارد. screenshot دو جهت/دو تم و هزینهٔ واقعی bundle/GPU ثبت شود.

A08: MediaConversion، MediaStorageReservation، Request/Resource/Policy و Actionهای enqueue/retry/cancel/finalize؛ adapter Symfony Process و job TranscodeMedia در queue جدا با همان DB. endpointهای آیندهٔ [MEDIA_PIPELINE](MEDIA_PIPELINE.fa.md) همراه OpenAPI اجرایی ساخته شوند. media policy، محدودیت upload و تنظیمات deploy یکسان شوند. UI C44 تا C47 و C59 تا C63 وضعیت واقعی و خطای هر فایل را نمایش دهد. آماده‌بودن MP3 شرط انتشار FLAC برای شنونده و stream_url سازگار بماند.

A09: migrationهای جدید phaseهای جدا داشته باشند؛ runbook برای upgrade نیمه‌کارهٔ نسخهٔ قدیمی شامل inspect و restore snapshot شناخته‌شده باشد. تست fresh MySQL کافی نیست؛ fixture نسخهٔ قبلی با دادهٔ هم‌نام، چند playlist، likes و MFA واقعاً upgrade شود. runtime account و migrator جدا، phpMyAdmin محدود؛ backup DB+blob manifest+keys+commit در محیط خالی restore و hash شود. RPO/RTO هدف تا اندازه‌گیری، verified نشود.

A10: StreamController و deploy/nginx.conf برای internal media delivery؛ fallback توسعهٔ PHP حفظ شود. Supervisor رسانه و import جدا، stop process group، heartbeat و readiness؛ ops Resource بدون جزئیات حساس. بارسنجی با فایل و hardware مشخص و ده شنونده، همراه درخواست مدیریت، صف تبدیل و فضای محدود. معیارهای p95 هدف از [معماری](ARCHITECTURE_TARGET.fa.md) خوانده شوند و نتیجهٔ واقعی گزارش شود.

A11: یک release candidate با شناسهٔ commit ثابت، CI SQLite/MySQL، regressionهای جدید، typecheck/build/audit، مرورگر واقعی، screen reader، چندتب، load و restore. mapping کنترل‌های ASVS/WCAG به تست/مشاهده و یافته‌های باقی‌مانده. «بدون ایراد شناخته‌شده در دامنهٔ آزمون» فقط با این شواهد قابل گزارش است؛ تضمین بی‌نقصی مطلق داده نمی‌شود.

## ترتیب تحویل و دامنهٔ commit

هر بسته commit کوچکِ مستقل با رفتار، قرارداد API، migration جدید در صورت نیاز، UI state و تست معنادار دارد. برای عوض‌کردن نام پوشه یا یک اصلاح ظاهری ساده، تستی که صرفاً همان پیاده‌سازی را تکرار کند اضافه نشود. هر جا invariant داده، auth یا lifecycle عوض می‌شود، regression رفتاری لازم است. «planned»، «implemented» و «verified» در گزارش و blueprint یکی نشوند.

عنوان پیشنهادی commit بعدی: `fix: enforce recording ownership at import publication`، برای A01. عنوان commit این بستهٔ طراحی: `docs: define universal UI and resilient media architecture`.
