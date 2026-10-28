# F08 — تبدیل واقعی FLAC، حفظ اصل و انتخاب کیفیت

مبنا: commit `54f669a2be717c68284d5efb232f545e3d63b644`، تاریخ ۲۸ اکتبر ۲۰۲۶، Asia/Tehran. وضعیت تأیید و SHA آزمون‌شده فقط از [رودمپ](ROADMAP.fa.md) و evidence مربوط خوانده شود. این قابلیت استقرار production یا تکمیل F09 تا F16 را اعلام نمی‌کند.

F08 همراه با رگرسیون F01 تا F07 در [CI موفق 40700486944](https://github.com/Parsa-Emami/ahang-khatkesh/actions/runs/40700486944) روی همان source fingerprint پاس شد. ۱۲۸ تست PHP روی هر دو DB، ۲۶ تست فرانت، ۱۲ سناریوی مرورگر و ۹ تست چندپردازه؛ [evidence کامل](verification/foundation-through-f08-2026-10-28.json). این تأیید فقط دامنهٔ فاز را پوشش می‌دهد.

## رفتار محصول

FLAC اصلی در فضای خصوصی محفوظ می‌ماند. MP3 مشتق با `libmp3lame`، CBR برابر 320000bps، بدون ABR/VBR و بدون metadata و تصویر ورودی ساخته می‌شود. mono/stereo حفظ می‌شوند. sample rate برابر 44100/48000 حفظ و سایر نرخ‌ها به 44100 تبدیل می‌شوند. چندکاناله بدون پروفایل صریح رد می‌شود. MP3 اتلافی است؛ نگه‌داشتن هر دو فایل، لزوماً حجم کل دیسک را کم نمی‌کند. اندازهٔ واقعی دو نسخه در پنل دیده می‌شود.

آپلود FLAC تازه، admission رسانه، رزرو فضای مشتق و درج job را در همان تراکنش SQL انجام می‌دهد. اگر quota یا صف کافی نباشد، پذیرش نهایی rollback می‌شود و کپی موقت تا پاک‌سازی همچنان charged می‌ماند. فایل‌های تکراری می‌توانند blob مشترک و asset مستقل داشته باشند؛ مشتق خودکار به‌عنوان اصلِ ضبط تازه پذیرفته نمی‌شود. افزایش سقف فقط برای codec واقعی FLAC به 512 MiB است؛ سایر فرمت‌ها همچنان حداکثر 100 MiB هستند.

پلی‌لیست منتشرشده باید MP3 آماده داشته باشد؛ این شرط در commit ایمپورت، job انتشار و ویرایش پلی‌لیست بررسی می‌شود. پیش‌نویس پیش از اتمام تبدیل مجاز است. اتصال همان master آزاد به اصل یک Track تازه، snapshot تبدیل معلق را تحت قفل global به‌روز می‌کند؛ اتصال به rendition ضبط دیگر چنین مجوزی نمی‌دهد.

مسیر داخلی `/api/v1/tracks/{track}/stream` حفظ شده است. برای FLAC، حالت auto نسخهٔ MP3 آماده را انتخاب می‌کند؛ `quality=original` اصل و `quality=mp3-320` مشتق را انتخاب می‌کند. همهٔ انتخاب‌ها همان Policy و visibility را طی می‌کنند. فایل آماده‌نشده fail closed است. ETag از SHA نسخهٔ انتخاب‌شده می‌آید. GET/HEAD، conditional GET، Range و If-Range آزمایش می‌شوند. کیفیت در تب فعلی انتخاب می‌شود؛ همگام‌سازی ترجیح میان دستگاه‌ها جزو F14 است.

## نقص‌های واقعی و اصلاح‌ها

| مورد | نقص یا سناریوی بازتولیدشده | اصلاح |
| --- | --- | --- |
| F08-B01 | FLAC آپلود می‌شد ولی تبدیل و gate آمادگی MP3 وجود نداشت | pipeline صف، رزرو مشتق، validation کامل، پنل و gate انتشار/پخش |
| F08-B02 | FLAC بریده‌شده ممکن است بدون خطای صریح FFmpeg و با اختلاف مدت کمتر از 0.25s خروجی بدهد | decode کامل اصل، جمع sampleهای frame checksum و تطبیق دقیق با STREAMINFO؛ progress زمانی منبع شمارش دقیق نیست |
| F08-B03 | مرگ PHP لزوماً فرزند FFmpeg را متوقف نمی‌کند؛ cleanup می‌تواند با نویسندهٔ زنده مسابقه دهد | guardian مستقل، بررسی parent PID، process group، قفل attempt و قفل encoder سراسری |
| F08-B04 | تحویل دوبارهٔ job مرده پیش از انقضای lease با MaxAttemptsExceeded می‌تواند failed دائمی شود | طبقه‌بندی recoverable، backoff محدود و نسل تازه؛ اصلاح برای import و conversion و آزمون واقعی driver |
| F08-B05 | Range باز با start خارج فایل می‌توانست از BinaryFileResponse پاسخ 200 بگیرد؛ suffix بزرگ هم نیاز به normalization داشت | اعتبارسنجی و normalization Range پس از مجوز و conditional/If-Range؛ 416 صحیح و بدون مسیر خصوصی |
| F08-B06 | رفتن از فرم import به پنل تبدیل، draft درون پنل را از بین می‌برد | عمر ImportsPanel در جابه‌جایی بخش‌های همان admin حفظ و با تغییر actor/epoch پاک می‌شود؛ Dialog فقط در بخش فعال باز است |
| F08-B07 | صفِ منتظر پشت encoder سالم نباید به‌عنوان worker مرده بازیابی شود | queued stall در حضور heartbeat تازهٔ encoder فعال، rotate نمی‌شود |
| F08-B08 | رزرو conversion نباید از endpoint لغو upload آزاد شود | لغو فقط از ConversionExecution؛ claim و finalize وضعیت و token رزرو را بررسی می‌کنند |
| F08-B09 | بازاستفاده از FLAC قدیمی با codec=null می‌توانست admission تبدیل را رد کند | probe facts معتبر در همان admission backfill می‌شوند؛ آماده‌سازی batch فایل قدیمی نیز command دارد |

وجود تست‌های شکستِ این دور نشان‌دهندهٔ شکست نسخهٔ نهایی نیست؛ نتیجهٔ نهایی در evidence همان commit ثبت می‌شود.

## مدل داده و مالکیت

`media_assets` فیلدهای `source_asset_id`، `ownership_revision`، `codec`، `sample_rate` و `channels` دارد. مشتق یک asset تازه و immutable است که به master ثابت اشاره می‌کند. blob فیزیکی همچنان قرارداد F07 را دارد. `media:verify` ناسازگاری blob، projection، اصل ضبط و lineage تبدیل آماده را گزارش می‌کند.

`media_conversions` برای `(source_asset_id, profile_id, profile_version)` یکتاست. state، generation، revision، actor/epoch، fingerprint و ownership snapshot، lease/heartbeat، progress، خروجی آماده و report محدود نگه‌داری می‌شوند. PUT تکراری resource موجود را برمی‌گرداند؛ failed را پنهانی retry نمی‌کند.

هر نسل یک `media_conversion_attempts` و یک reservation مستقل دارد. مسیر staging و candidate قبل از نوشتن ثبت می‌شوند. فضای attempt قدیمی تا حذف واقعی آزاد نمی‌شود؛ attempt تازه مسیر قبلی را overwrite نمی‌کند. قیدهای FK و unique، هویت و lineage را نگه می‌دارند. down در حضور conversion از حذف مخرب داده جلوگیری می‌کند؛ forward fix یا backup هماهنگ لازم است.

در claim، heartbeat و finalize، generation/token، حساب فعال سوپرادمین، epoch و مالکیت فعلی دوباره بررسی می‌شوند. لغو و تغییر مجوز، انتشار را fence می‌کنند. callback قدیمی خروجی نسل تازه را تغییر نمی‌دهد. publication اتمیک و idempotent است؛ تحویل queue می‌تواند تکرار شود.

## اجرا و validation

Laravel، guardian PHP را با argv و stdin JSON داخلی اجرا می‌کند. guardian در session مستقل از process group والد اجرا می‌شود تا kill گروه PHP نگهبان را هم از بین نبرد. guardian، attempt lock و encoder lock را می‌گیرد و FFmpeg را با `setsid` و `prlimit` در گروه قابل توقف اجرا می‌کند. هیچ shell یا flag، URL یا مسیر دلخواه از مرورگر وارد فرمان نمی‌شود. input با realpath داخل media root و SHA/size کنترل و demuxer به flac محدود است. protocol فقط file/pipe است.

ترتیب بررسی: ساختار STREAMINFO و تعداد نمونهٔ اعلام‌شده؛ decode کامل اصل و شمار دقیق sampleهای frame checksum؛ encode؛ FFprobe خروجی؛ بررسی تک‌تک headerهای MP3 برای MPEG-1 Layer III و bitrate index برابر 320kbps؛ decode کامل خروجی؛ fsync؛ hash و rename محلی؛ سپس commit نهایی blob/asset/lineage/quota/audit. تفاوت مدت خروجی حداکثر 0.25s است و جای شمارش نمونهٔ اصل را نمی‌گیرد. metadata پروفایل و fingerprint ابزار در report ثبت می‌شوند.

FLAC streaming بدون تعداد sample مثبت در STREAMINFO، یا فایل بدون امضای raw FLAC در ابتدای آن، در این پروفایل پذیرفته نمی‌شود. برای چنین masterهایی باید ابزار معتبر، یک FLAC استاندارد تازه بسازد؛ فایل اصلی خودکار دست‌کاری نمی‌شود.

stdout و stderr دوره‌ای تخلیه، JSON/progress و probe محدود و خطاهای عمومی sanitise می‌شوند. path، token و stderr خام در API نیستند. حافظهٔ فرزند حداکثر 768 MiB، یک thread codec، سقف file size برابر reservation و CPU/wall budget محدود است. فقط یک encoder سنگین روی volume فعال می‌شود. قفل بدون TTL تا پایان واقعی process نگه داشته می‌شود؛ guardian پس از مرگ parent، گروه فرزند را متوقف می‌کند.

| بودجه | مقدار فعلی |
| --- | --- |
| encoder | 600s |
| هر validation خارجی/scan | 30s |
| adapter | 740s؛ شامل حاشیه و چهار بخش validation |
| job timeout | 800s |
| media retry_after | 900s |
| lease | 960s؛ heartbeat هر 5s |
| Supervisor stopwaitsecs | 840s؛ stopasgroup/killasgroup |
| nonterminal conversions | حداکثر ۲۰؛ active reservation مشترک نیز حداکثر ۲۰ |
| output | حداکثر 600 MiB و حداکثر بایت رزروشده |
| reservation estimate | ceil(duration × 40000 × 1.02) + 1048576 |

مدت طولانی روی سرور کند ممکن است از budget عبور کند؛ تبدیل با حفظ اصل fail می‌شود. افزایش budget به تغییر هماهنگ timeout/retry_after/lease/Supervisor و تست دوباره نیاز دارد. مقدار retry_after ناسازگار در admission رد می‌شود.

timeout، مرگ worker و خطاهای گذرای شناخته‌شدهٔ SQL، حداکثر سه نسل خودکار با backoff 30/60s دارند. codec نامعتبر، فایل ناقص و خروجی نامعتبر کورکورانه تکرار نمی‌شوند. quota ناکافی در retry به پیام ظرفیت و retry دستی می‌رسد. cleanup به صف سبک database سپرده می‌شود و با شکست حذف، رزرو charged باقی می‌ماند. maintenance مستقل از GC اختیاری هر دقیقه recovery را انجام می‌دهد.

## API و پنل

شش مسیر policy، list، detail، request، retry و cancel مطابق [OpenAPI](openapi.json) فعال‌اند. همهٔ مسیرهای مدیریت تبدیل فقط سوپرادمین، session/CSRF/MFA و در mutationها step-up/context دارند. فهرست cursor و filter دارد؛ کنترل retry/cancel به revision و expected_generation نیاز دارد. profile و آرگومان encoder سمت سرور ثابت‌اند.

پنل، تخمین پیشرفت encoder، مرحلهٔ validation، اندازهٔ واقعی، reserve، خطای قابل اقدام، بازیابی خودکار و retry/cancel را نشان می‌دهد. 100٪ فقط بعد از commit موفق است. پیش‌نمایش import، readiness زندهٔ هر source را نشان می‌دهد. پلیر با تغییر کیفیت position را حفظ می‌کند. اجزای تازه از CSS منطقی، تم موجود و کنترل‌های native قابل کیبورد استفاده می‌کنند؛ screenshot دو جهت و دو تم در desktop/mobile viewport ثبت می‌شود. ترجمهٔ کامل fa/en و دسترس‌پذیری کل نرم‌افزار همچنان F09/F16 است.

## عملیات و شواهد

سرور تبدیل به Linux، PHP 8.5 CLI با pcntl/posix، FFmpeg دارای libmp3lame، FFprobe، setsid/prlimit و volume خصوصی با flock/rename نیاز دارد. Windows host می‌تواند از WSL/Linux server استفاده کند؛ runtime بومی Windows در این فاز پشتیبانی نمی‌شود. در production، SHA-256 دو binary باید پین باشد؛ `media:doctor` وجود ابزار، encoder و تطبیق pin را بررسی می‌کند. فایل‌های آماده با تغییر ابزار خودکار overwrite نمی‌شوند؛ تغییر رفتار پروفایل، release و version تازه می‌خواهد.

[راهنمای پس از F08](AFTER_F08.fa.md) شامل upgrade، فعال‌سازی و آماده‌سازی FLACهای قدیمی است. `media:prepare-flac` پیش‌فرض read-only و `--apply --owner=<id>` فقط enqueue محدود با مجوز مالک فعال است. تبدیل واقعی همچنان در worker انجام می‌شود.

تست‌ها: AudioConversionTest، ConversionRecoveryTest، ConversionApiTest، رگرسیون import redelivery، ConversionProcessTest روی MySQL و journey مرورگر conversion/quality. kill هنگام encode، kill کل process group والد، kill پس از promote و پیش از commit، duplicate delivery، redelivery واقعی driver، disk limit، disk failure، zero staging، corrupt/truncated source، revoke، stale generation، quota، lineage، draft و کیفیت پوشش دارند. fixtures مصنوعی‌اند؛ هیچ آهنگ واقعی کاربر تبدیل نشده است. بار ده stream، restore production، مرورگر/دستگاه واقعی دیگر و audit مستقل جزو F15/F16 باقی‌اند.

منابع framework: [FFmpeg libmp3lame](https://ffmpeg.org/ffmpeg-codecs.html#libmp3lame)، [FFmpeg protocols](https://ffmpeg.org/ffmpeg-protocols.html)، [Symfony Process](https://symfony.com/doc/current/components/process.html)، [Laravel 13 queues](https://laravel.com/framework/docs/13.x/queues). نتایج پروژه از تست‌های مخزن می‌آیند.
