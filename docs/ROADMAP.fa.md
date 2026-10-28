# رودمپ اجرایی ahang-khatkesh

تاریخ مبنا: ۲۷ اکتبر ۲۰۲۶، Asia/Tehran. این برنامه ۱۶ فاز دارد. F01 تا F06 کد اجرایی دارند؛ وضعیت نهایی تأیید را از فهرست زیر و [دادهٔ ماشین‌خوان](roadmap/phases.json) بخوانید. F07 تا F16 هنوز اجرا نشده‌اند. «جهانی» هدف کیفیت است؛ گواهی بی‌نقصی یا هم‌ترازی زیرساخت با Spotify نیست.

این سند ترتیب اجرای نسخهٔ جدید را تعیین می‌کند. بسته‌های A01 تا A11 و ۶۴ قرارداد کامپوننت قبلی حذف نشده‌اند؛ جدول تطبیق پایین سند ارتباطشان را حفظ می‌کند. هر فاز یک محدودهٔ محدودِ قابل آزمون دارد؛ تأیید F04 به معنی تکمیل تمام blob/quota در A03 نیست.

## وضعیت قابل تأیید

- [ ] F01 — مرز سوپرادمین و سطل بازیافت
- [ ] F02 — پیش‌نمایش مؤثر و انتشار اتمیک ایمپورت
- [ ] F03 — نسخهٔ امنیتی جاری و کنترل credential
- [ ] F04 — پاک‌سازی بادوام فایل‌های بدون ارجاع
- [ ] F05 — صف شخصی و تایمر خواب
- [ ] F06 — حفظ draft، نشست چندتب و قرارداد پاسخ
- [ ] F07 — چرخهٔ کامل job، blob، quota و reconciliation
- [ ] F08 — FLAC به MP3 320k و انتخاب کیفیت
- [ ] F09 — زبان و جهت سراسری و دسترس‌پذیری پایه
- [ ] F10 — هویت بصری و shader محلی 21st.dev
- [ ] F11 — تاریخچه، حالت خصوصی و پیشنهاد قابل توضیح
- [ ] F12 — پنل کامل مدیریت کاتالوگ و درخواست اعضا
- [ ] F13 — متن همگام، crossfade و جزئیات صوت
- [ ] F14 — کنترل چند دستگاه و آفلاین اختیاری
- [ ] F15 — عملیات، ارتقای MySQL و ظرفیت واقعی
- [ ] F16 — شواهد انتشار و بازبینی مستقل امنیت

## روش اجرای هر فاز

۱. وابستگی‌های فاز و یافته‌های باز را بخوان؛ رفتار فعلی را بازتولید کن. ۲. مدل داده، API، UI و مهاجرت را مطابق acceptance بنویس؛ قرارداد جدید را به OpenAPI اضافه کن. ۳. تست رفتار صحیح، ورودی ناسالم، نقش غیرمجاز، revision قدیمی، rollback و failure خارجی را اضافه کن. ۴. آزمون متمرکز را پاس کن؛ سپس مجموعهٔ کامل PHP روی SQLite و MySQL، فرانت، typecheck/build، مرورگرهای مرتبط، formatter و audit را اجرا کن. ۵. کامیت implementation بزن و CI همان SHA را بررسی کن. ۶. تنها پس از موفقیت همه jobها، شناسهٔ run، فهرست تست‌ها و fingerprint کد را در سند evidence ثبت و وضعیت فاز و تیک را با کامیت جداگانه به‌روز کن.

`node scripts/check-roadmap.mjs` تیک بدون evidence، تست ناموفق یا skip، مدرک با fingerprint قدیمی و وابستگی دوری را رد می‌کند. در CI، history کامل و run واقعی GitHub هم بررسی می‌شود. `collect-verification.mjs` فقط گزارش‌های واقعی PHPUnit، Vitest و Playwright را می‌خواند. اگر کد، migration، تست، lockfile یا workflow عوض شود، evidence قبلی دیگر مدرک همان پیاده‌سازی نیست؛ ابتدا فازهای تأییدشده را به implemented برگردان یا مدرک تازه بساز. تغییر صرفاً توضیحی در docs این fingerprint را عوض نمی‌کند؛ OpenAPI بخشی از fingerprint است.

تست عمومی به تنهایی کافی نیست: هر فاز `required_tests` و acceptance مستقل دارد. فاز آینده با چند تست قدیمی تأیید نمی‌شود. معیارهای غیرخودکار و آزمایش دستگاه واقعی در F16 هم باید به مدرک جداگانه متصل شوند؛ این checker جای بازبینی مستقل را نمی‌گیرد.

## جزئیات فازها

### F01 — مرز سوپرادمین و سطل بازیافت

وابستگی: ندارد.

مرز داده: مقدار پایدار role=owner حفظ می‌شود و عنوان رابط سوپرادمین است. حذف، soft delete است. GET سطل بازیافت، DELETE و POST restore روی مسیرهای داخلی admin با MFA و step-up قرار دارند. TrashPanel و تأیید حذف، error و pagination دارند. عضو همچنان queue/like/save شخصی دارد. حذف فیزیکی و retention نهایی در F07/F12 است.

معیار خروج:

- تمام ورودی‌های نوشتن کاتالوگ فقط برای owner فعال مجازند.
- حذف و بازیابی revision دارند؛ فایل، شناسه، لایک و عضویت حفظ می‌شوند.
- API، Action و مرورگر مستقل از پنهان‌کردن دکمه بررسی می‌شوند.

آزمون‌های الزامی: `SuperAdministratorTest`.

### F02 — پیش‌نمایش مؤثر و انتشار اتمیک ایمپورت

وابستگی: F01.

ResolveImportPlan ورودی اصلی، متادیتای مؤثر، revision و مالکیت را جدا نگه می‌دارد. source_rows داخلی در پاسخ نیست. یک فایل نمی‌تواند هم‌زمان اصل یک ضبط و rendition ضبط دیگر شود. needs_review پیش‌نمایش تازه می‌خواهد؛ cancelled پس از تغییر دسترسی خودکار منتشر نمی‌شود. generation در retry افزایش می‌یابد و callback قدیمی fenced است. lease و reconciliation کامل عمداً در F07 باقی می‌مانند.

معیار خروج:

- پیش‌نمایش، متادیتای نهایی canonical و هش برنامه را نشان می‌دهد.
- تغییر مالکیت، revision یا دسترسی سازنده، انتشار جزئی ایجاد نمی‌کند.
- اجرای job و failed قدیمی نمی‌توانند نسل تازه را تغییر دهند.

آزمون‌های الزامی: `ImportPublicationIntegrityTest`، `PlaylistImportTest`.

### F03 — نسخهٔ امنیتی جاری و کنترل credential

وابستگی: F01.

ترتیب قفل امنیت: global، حساب جاری، سپس entity. تأیید رمز روی hash تازه انجام و پیش از نوشتن دوباره در تراکنش تطبیق داده می‌شود. افزایش epoch، ابطال نشست‌های دیگر و audit با تغییر credential اتمیک‌اند. هش مجدد هنگام ورود CAS دارد. تست interleaving کنترل‌شده جای آزمون چندپردازهٔ MySQL را نمی‌گیرد.

معیار خروج:

- snapshot منقضی نمی‌تواند reset جدید را بازنویسی کند.
- epoch از مقدار تازه DB افزایش می‌یابد؛ hash پرهزینه بیرون قفل است.
- MFA، recovery، تغییر نقش و reset از کنترل حساب جاری استفاده می‌کنند.

آزمون‌های الزامی: `CredentialEpochTest`، `MfaFlowTest`، `AccountAccessTest`.

### F04 — پاک‌سازی بادوام فایل‌های بدون ارجاع

وابستگی: F01، F03.

فایل جدید immutable پیش از تعویض اشاره‌گر نوشته می‌شود؛ اشاره‌گر و cleanup job روی database connection مشترک commit می‌شوند. cleanup ارجاع فعال و trashed را پیش از حذف بررسی می‌کند و failure را retry می‌کند. خطای مبهم ممکن است orphan باقی بگذارد؛ GC و quota در F07 آن را پوشش می‌دهند. تزریق failure باید بعد از commit واقعی DB آزمون شود، نه در outer transaction تست.

معیار خروج:

- اشاره‌گر جدید و cleanup job در یک تراکنش ثبت می‌شوند.
- شکست حذف قبلی، فایل جدید را حذف نمی‌کند.
- cleanup تکرارپذیر است و ارجاع سطل بازیافت را حفظ می‌کند.

آزمون‌های الزامی: `MediaLifecycleTest`.

### F05 — صف شخصی و تایمر خواب

وابستگی: F01، F03.

PlayerProvider بالاتر از route باقی می‌ماند. صف حداکثر ۵۰۰ آهنگ یکتا دارد؛ حذف مورد جاری، مورد بعدی را انتخاب می‌کند و حذف آخرین مورد جاری پخش را متوقف می‌کند. shuffle متعادل heuristic است، پیشنهاد هوشمند یا تاریخچه‌محور نیست. تایمر در همین تب و تا بستن آن معتبر است؛ تضمین اجرای دقیق در suspend سیستم ندارد. پخش و گذر زمان صوت با Audio events سنجیده می‌شوند.

معیار خروج:

- پخش بعدی، انتقال، حذف و پاک‌کردن صف، کاتالوگ را تغییر نمی‌دهند.
- ترتیب واقعی صف ذخیره و بازیابی می‌شود؛ shuffle مجاورت هنرمند را متعادل می‌کند.
- تایمر زمان‌دار و پایان آهنگ قبل از repeat اعمال می‌شوند.

آزمون‌های الزامی: `personal queue editing`، `real audio supports play next queue edits and timed stop without changing the playlist`.

### F06 — حفظ draft، نشست چندتب و قرارداد پاسخ

وابستگی: F02، F03، F04، F05.

API actor:epoch را از session bootstrap می‌گیرد؛ این header محرمانه یا جایگزین احراز هویت نیست. mutation بدون آن 428 و mismatch آن 409 است. refresh CSRF اجازهٔ انتقال intent به حساب دیگر نمی‌دهد. BroadcastChannel فقط تغییر نشست را اعلام می‌کند و credential نمی‌فرستد. draft dirty با refetch حفظ می‌شود؛ discard صریح است. guard ناوبری همه فرم‌ها، ترجمه کامل و type تولیدشده از OpenAPI هنوز در F09/F16 است.

معیار خروج:

- retry پس از 419 یا 423 فقط برای همان actor و epoch انجام می‌شود.
- همه mutationهای authenticated زمینهٔ نشست دارند؛ لغو و پاسخ دیررس حفظ می‌شوند.
- reconnect ترتیب محلی را پاک نمی‌کند؛ کنارگذاشتن صریح و schema validation اجرا می‌شوند.

آزمون‌های الزامی: `session-bound transport`، `versioned playlist draft`، `ApiResponseSchemaTest`، `dirty playlist order survives remote edits and reconnect until explicitly discarded`.

### F07 — چرخهٔ کامل job، blob، quota و reconciliation

وابستگی: F02، F03، F04، F06.

migrationهای expand برای media_blobs، asset reference، storage_reservations و lease فیلدهای import بنویس. blobs.sha256+size یکتا، ارجاع معنایی مستقل و مسیر immutable باشند. backfill قابل ادامه و integrity command با dry-run بساز. پیش از دریافت فایل، reservation محدود و expiry داشته باش. claim، heartbeat، lease expiry و reconcile با generation+token شرطی باشند. kill واقعی worker، callback قدیمی، duplicate dispatch، disk full، zero-byte staging و دو آپلود هم‌زمان را روی MySQL تست کن. اول GC فقط گزارش دهد؛ حذف پس از retention و recheck فعال شود.

معیار خروج:

- lease، heartbeat و recovery چندپردازه واقعی MySQL پاس شوند.
- blob فیزیکی از asset معنایی جدا شود؛ quota پیش از آپلود رزرو شود.
- orphan GC با retention، dry-run و recheck مرجع اجرا شود.

آزمون‌های الزامی: `ImportLeaseRecoveryTest`، `MediaBlobQuotaTest`.

### F08 — FLAC به MP3 320k و انتخاب کیفیت

وابستگی: F07.

قبل از encoder، blob، quota و fencing فاز F07 کامل شود. conversion و attempts جدول مستقل، profile version و input hash داشته باشند. FFmpeg بدون shell، با argv allowlist، شبکه بسته، کاربر بدون دسترسی اضافی، CPU/memory/time limit و staging خصوصی اجرا شود. پروفایل دقیق docs/MEDIA_PIPELINE.fa.md مرجع است: -c:a libmp3lame -b:a 320k -abr 0؛ فایل FLAC اصلی حذف نشود. خروجی با ffprobe و full decode و اختلاف مدت مجاز تأیید و سپس atomic rename شود. انتخاب کیفیت فقط rendition آماده و مجاز را stream کند. پنل ConversionQueue، جزئیات failure و retry سوپرادمین داشته باشد. golden fixtureهای mono/stereo و 44.1/48/96kHz، فایل خراب، چندکاناله، timeout و retry را تست کن؛ کاهش حجم را اندازه بگیر، تضمین نکن که همه فایل‌ها کوچک‌تر می‌شوند.

معیار خروج:

- اصل FLAC حفظ و MP3 با libmp3lame، CBR 320000 و decode کامل اعتبارسنجی شود.
- خطای codec، disk full، timeout، kill و تکرار job فایل نیمه‌آماده منتشر نکنند.
- پنل پیشرفت، retry مجاز، اندازهٔ واقعی و پروفایل خروجی داشته باشد.

آزمون‌های الزامی: `AudioConversionTest`، `ConversionRecoveryTest`، `quality picker`.

### F09 — زبان و جهت سراسری و دسترس‌پذیری پایه

وابستگی: F06.

LocaleProvider واحد و dictionary تایپ‌شدهٔ fa-IR/en-US بساز؛ تمام متن‌ها از جمله خطا، aria، Dialog، toast، admin و validation را پوشش بده. جهت مستقل از زبان و theme باشد؛ dir را روی document اعمال و متن mixed با bdi/dir=auto جدا کن. CSS فیزیکی را به logical properties تبدیل کن؛ timeline صوت عمداً LTR باقی بماند. ۳۲۰، ۳۹۰، ۷۶۸، ۱۴۴۰ و zoom ۲۰۰٪ را برای ترکیب‌های جهت/تم چک کن. همه فرم‌های dirty، back/route/unload و discard صریح داشته باشند. keyboard و focus-return و reduced motion را تست کن. ترجمهٔ ناقص به عنوان universal تأیید نشود.

معیار خروج:

- همه ۶۴ قرارداد کامپوننت در fa/en و RTL/LTR و light/dark/system پوشش داشته باشند.
- تغییر locale/theme پلیر را remount نکند؛ متن ترکیبی و logical CSS بررسی شوند.
- صفحه‌خوان، کیبورد، خروج امن از فرم و خطاهای ترجمه آزمون شوند.

آزمون‌های الزامی: `locale direction theme matrix`، `DraftNavigationTest`.

### F10 — هویت بصری و shader محلی 21st.dev

وابستگی: F09.

اسناد UNIVERSAL_UI و COMPONENT_CATALOG، منبع و مجوز pin شدهٔ Dither Flow در blueprint و THIRD_PARTY_NOTICES را بررسی کن. کد را پس از بررسی dependency و CSP به ماژول محلی محدود وارد کن؛ remote script ممنوع. 21st.dev منبع الهام/کد دارای مجوز است و به‌خودی‌خود اثبات کیفیت نیست. tokenهای رنگ، accent، typography، surface، focus، motion و spacing را برای هر دو theme تعریف کن. shader صرفاً پس‌زمینه تزئینی، lazy و قابل خاموش‌کردن باشد. tab hidden، battery محدود، GPU ناموجود، context loss و reduced-motion باید CSS ثابت داشته باشند. screenshot و contrast matrix همه stateها را ثبت کن.

معیار خروج:

- منبع و مجوز pin شده در مخزن وارد شود؛ هیچ کد یا asset دوردستی در runtime بار نشود.
- رنگ، contrast، focus، density و حالت‌های empty/error/loading یکپارچه شوند.
- shader تزئینی با context loss، reduced motion، hidden tab و بودجه GPU افت امن داشته باشد.

آزمون‌های الزامی: `visual matrix`، `shader fallback and reduced motion`.

### F11 — تاریخچه، حالت خصوصی و پیشنهاد قابل توضیح

وابستگی: F05، F08، F09.

listening_events حداقل دادهٔ لازم را داشته باشد: user، track، session/device pseudonym، started/completed/skip و UTC. dedup و حداقل زمان شنیدن، retention، export و erase مشخص باشند. private session هیچ رویداد بلندمدت نسازد. پیشنهاد با امتیاز توضیح‌پذیر از liked/history/recency/mood و diversity و opt-out ساخته شود؛ cold start از کاتالوگ curated باشد. موسیقی نامرئی یا trashed در پیشنهاد نیاید. mix مجازی به کاتالوگ چیزی اضافه نمی‌کند. deletion و عدم نشت بین دو کاربر را در API و query cache تست کن.

معیار خروج:

- history با consent، retention و پاک‌کردن واقعی ساخته شود؛ private session ذخیره نشود.
- پیشنهاد فقط از کاتالوگ مجاز و با توضیح علت، diversity و کنترل کاربر ساخته شود.
- پیشنهاد یا mix به پلی‌لیست عمومی تبدیل نشود مگر با تأیید سوپرادمین.

آزمون‌های الزامی: `ListeningPrivacyTest`، `ExplainableMixTest`.

### F12 — پنل کامل مدیریت کاتالوگ و درخواست اعضا

وابستگی: F07، F08، F09، F10.

Admin را به workflows روشن catalog/import/conversion/trash/members/audit/operations تقسیم کن؛ وضعیت، شمارش و اقدام بعدی قابل فهم باشد. proposal اعضا جدول مستقل با pending/accepted/rejected دارد و هیچ write مستقیم به track/playlist نمی‌کند. فقط superadmin با step-up و snapshot/revision می‌تواند پیشنهاد را قبول و کاتالوگ را تغییر دهد. bulk rename/move/metadata قبل از commit dry-run و پس از آن audit بدون secret داشته باشد. duplicate detection بر اساس recording identity و provenance باشد، نه صرفاً نام یا hash. repair و merge شناسه‌های پایدار و لایک‌ها را حفظ کنند؛ بدون snapshot و تست migration اجرا نشوند.

معیار خروج:

- bulk edit با preview، revision، rollback و audit باشد.
- تشخیص تکراری، هویت هم‌نام، فایل گمشده و مسیر ترمیم کنترل‌شده داشته باشد.
- عضو فقط پیشنهاد می‌دهد؛ ایجاد، حذف و انتشار نهایی فقط سوپرادمین است.

آزمون‌های الزامی: `CatalogBulkChangeTest`، `MemberProposalTest`.

### F13 — متن همگام، crossfade و جزئیات صوت

وابستگی: F08، F09، F11.

Lyrics فقط ورودی دستی یا منبع مجاز، متن plain و حداکثر حجم داشته باشد؛ HTML دلخواه اجرا نشود. LRC parser زمان منفی/بزرگ، BOM و تکرار timestamp را کنترل کند. scrolling قابل توقف و compatible با reduced motion باشد. crossfade اختیاری و خاموش به صورت پیش‌فرض تا تأیید دستگاه‌ها، با دو Audio و teardown روشن پیاده شود. loudness analysis صرفاً metadata و playback gain قابل خاموش‌کردن باشد؛ اصل را بازنویسی نکند. AutoMix هوشمند و Apple Sing معادل این پیاده‌سازی ادعا نشوند. Safari/iOS، بلوتوث و تغییر device و tab sleep آزمایش شوند.

معیار خروج:

- متن دستی دارای مجوز، LRC parser محدود و sync با seek آزمون شود.
- crossfade خاموش‌شدنی با دو audio و cleanup کامل و بدون دوپخش ناخواسته ساخته شود.
- gapless، loudness metadata و محدودیت واقعی Safari/iOS و قالب‌ها ثبت شوند.

آزمون‌های الزامی: `LyricsTimelineTest`، `CrossfadeDeviceMatrix`.

### F14 — کنترل چند دستگاه و آفلاین اختیاری

وابستگی: F06، F08، F09، F11.

playback_devices، session-bound device id، active lease و revision مستقل تعریف کن. remote command فقط به دستگاه متعلق به همان کاربر با sequence/expiry ارسال شود. انتقال پخش نیاز به پذیرش دستگاه، وضعیت loading و fallback داشته باشد؛ فرمان قدیمی پس از revoke معتبر نباشد. service worker فقط shell عمومی نسخه‌دار را پیش‌فرض cache کند؛ صوت خصوصی بدون opt-in cache نشود. آفلاین سهمیه، وضعیت دانلود، پاک‌سازی هنگام خروج و توضیح محدودیت ابطال در دستگاه کاملاً آفلاین می‌خواهد. اگر این محدودیت با مدل امنیتی پذیرفته نشد، قابلیت آفلاین غیرفعال بماند.

معیار خروج:

- انتخاب دستگاه فعال، fencing، revoke و تعارض دو دستگاه قابل مشاهده باشد.
- آفلاین فقط با انتخاب صریح، سهمیه، خروج امن و محدودیت ابطال توضیح‌داده‌شده باشد.
- چندتب، reconnect، sleep سیستم و نسخهٔ cache در مرورگرهای هدف آزمون شوند.

آزمون‌های الزامی: `PlaybackDeviceLeaseTest`، `OfflineRevocationTest`.

### F15 — عملیات، ارتقای MySQL و ظرفیت واقعی

وابستگی: F07، F08، F12.

MySQL 8.4 واقعی با snapshot دارای کاربر، Track قدیمی، لایک، صف، playlist و رسانه را ارتقا بده؛ fixture مصنوعی و مجزا باشد. DDL و backfill طولانی را جدا و verification command قابل ادامه بساز. backup manifest شامل DB snapshot، media checksums، config و key recovery امن باشد؛ restore روی ماشین جدا و hash/media consistency بررسی شود. phpMyAdmin فقط VPN/loopback، حساب مجزا با حداقل مجوز و بدون root runtime؛ تغییر دادهٔ محصول از پنل application باشد. Nginx internal X-Accel بعد از Policy، Range/HEAD و Content-Disposition را تست کن. ده stream هم‌زمان با import/conversion و جستجو را با سخت‌افزار اعلام‌شده بسنج؛ SLO فقط پس از مشاهده ثبت شود. disk low، dead queue، expired lease و failed jobs هشدار داشته باشند.

معیار خروج:

- ارتقای دادهٔ نسخهٔ قبلی در MySQL 8.4 و بازیابی هماهنگ DB و فایل اثبات شود.
- X-Accel داخلی بعد از مجوز Laravel، Range، HEAD و revoke درست کار کنند.
- ده stream همراه import و سقف دیسک، صف، تأخیر و هشدارها اندازه‌گیری شوند.

آزمون‌های الزامی: `MySqlUpgradeRestoreTest`، `TenConcurrentStreams`.

### F16 — شواهد انتشار و بازبینی مستقل امنیت

وابستگی: F10، F11، F12، F13، F14، F15.

تهدیدنامه و الزامات قابل اعمال OWASP ASVS با نسخه pin شده، صاحب کنترل و evidence بساز. کنترل‌های auth/session/MFA/upload/path/XSS/SQLi/CSRF/IDOR/rate-limit/cache و dependency را با تست‌های منفی مستقل مرور کن. سناریوهای failure و multi-process، device matrix واقعی iOS/Android و Safari/Firefox/Chromium و NVDA/VoiceOver اجرا شوند. DAST فقط روی staging مجاز با دادهٔ مصنوعی؛ نتیجه و false positive ثبت شود. deployment با APP_DEBUG=false، TLS، secure cookie، CSP، least privilege، worker isolation و restore drill نهایی تأیید شود. هیچ P0/P1 حل‌نشده یا تست skip برای release پذیرفته نشود. یک بازبین مستقل از نویسنده، evidence را امضا کند؛ سبزشدن CI به‌تنهایی تأیید production نیست.

معیار خروج:

- همه یافته‌های باز و نیازمندی‌های قابل اعمال ASVS با شاهد بسته شوند.
- SAST/DAST، dependency audit، تست نفوذ مجاز و keyboard/screen-reader مستقل پاس شوند.
- راهنمای استقرار، restore drill، rollback و مالک پذیرش، release candidate را تأیید کنند.

آزمون‌های الزامی: `ReleaseSecurityReview`، `UniversalReleaseMatrix`.

## تطبیق با بسته‌های قبلی

| بستهٔ پیشین | فازهای اجرای تازه | وضعیت دامنهٔ باقیمانده |
| --- | --- | --- |
| A01 | F01، F02، F07 | هویت blob و checker عمومی هنوز باقی است |
| A02 | F03 | آزمون race چندپردازه در F16 تکمیل می‌شود |
| A03 | F04، F07 | cleanup اصلاح شده؛ quota/GC/blob کامل نشده‌اند |
| A04 | F02، F07 | generation هست؛ lease و recovery کامل نیست |
| A05 | F06، F09، F16 | draft/session/schema پایه هست؛ type generation و همه فرم‌ها باقی است |
| A06 | F09 | fa/en و RTL/LTR سراسری هنوز تأیید نشده |
| A07 | F10 | shader هنوز به runtime وارد نشده |
| A08 | F07، F08 | تبدیل خودکار هنوز اجرا نشده |
| A09 | F15 | upgrade/restore واقعی باقی است |
| A10 | F15 | offload و بارسنجی باقی است |
| A11 | F16 | تأیید انتشار هنوز انجام نشده |

## گام بعد

پس از اثبات F01 تا F06، F07 پیش‌نیاز آغاز pipeline واقعی تبدیل صوت است. بدون blob، quota، fencing و چرخهٔ staging، اضافه‌کردن FFmpeg فقط بخشی از راه است. هر بار scope یک فاز عوض شد، acceptance، required_tests، OpenAPI و ارزیابی ریسک همان فاز نیز به‌روز شوند.
