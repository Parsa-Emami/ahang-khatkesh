# رابط universal، RTL/LTR و تم‌ها

وضعیت: قرارداد A06 در F09 پیاده‌سازی و با CI همان source تأیید شده است؛ A07 و جلوه‌ها در F10 باقی‌اند. [قرارداد اجرایی F09](F09_LOCALE_AND_ACCESSIBILITY.fa.md) مقدم است. نسخهٔ 0.2 رابط فارسی/RTL دارد؛ کامل‌بودن انگلیسی، LTR و همهٔ حالت‌های این سند ادعا نمی‌شود. universal در این محصول یعنی ساختار مستقل از زبان، جهت، تم، اندازهٔ صفحه و شیوهٔ ورودی، با دامنهٔ پشتیبانی و تست مشخص.

## دامنه و مالکیت تنظیمات

| محور | قرارداد اولیه |
| --- | --- |
| زبان‌های کامل هدف | fa-IR و en-US؛ افزودن زبان جدید با همان catalog و آزمون |
| جهت | root با lang و dir از locale config؛ fa-IR=rtl، en-US=ltr؛ محتوای کاربر مستقل |
| تم | light، dark، system؛ رنگ معنایی؛ تغییر سیستم بدون reload |
| motion | system، full، reduced؛ prefers-reduced-motion همیشه محترم؛ خاموش‌کردن جلوه در تنظیمات |
| اندازه | 360، 390، 768، 1280 و 1440px؛ reflow در عرض مؤثر 320px؛ safe-area موبایل |
| ورودی | touch، pointer و keyboard؛ کنترل ضروری فقط hover نباشد |
| زمان | UTC در API؛ IANA time_zone در تنظیمات؛ Intl در نمایش؛ مدت آهنگ timestamp نیست |
| داده | عنوان فارسی، لاتین، عربی و ترکیبی؛ ایموجی و ZWNJ؛ نام طولانی و نام تکراری |

LocaleProvider، ThemeProvider و SessionProvider بالای routeها و player هستند. تغییر locale یا theme نباید Audio، position، queue یا فرم dirty را reset کند. locale، theme و motion ترجیح غیرمحرمانه‌اند؛ credential در localStorage نیست. تنظیم locale با allowlist در پروفایل و برای صفحهٔ ورود در ترجیح محلی ذخیره شود؛ مقدار ناشناخته به default مشخص برگردد. lang/dir پیش از نخستین frame مؤثر تعیین شوند تا صفحه در جهت اشتباه چشمک نزند.

## ساختار فرانت هدف

| مسیر هدف | مسئولیت |
| --- | --- |
| resources/js/i18n/locales.ts | فهرست locale، direction، time zone default و نام بومی زبان |
| resources/js/i18n/LocaleProvider.tsx | اتصال ترجیح، document.lang/dir و IntlProvider |
| resources/js/i18n/messages/fa-IR.json | تمام متن‌های محصول، خطاهای UI و نام دسترس‌پذیر |
| resources/js/i18n/messages/en-US.json | کلیدهای یکسان؛ بدون fallback فارسی پنهان در انگلیسی |
| resources/js/i18n/formatters.ts | number، bytes، relative time، date و duration با Intl |
| resources/js/components/ui | primitiveهای Button، Field، Dialog، Tabs، Menu و وضعیت‌ها |
| resources/js/components/icons | رجیستری معنایی، جهت‌پذیری انتخابی و label |
| resources/js/components/effects | EffectBoundary، ShaderBackdrop و fallback محلی |
| resources/css/tokens.css | tokenهای معنایی و theme؛ استخراج تدریجی از app.css |
| lang/fa و lang/en | پیام validation و domain لاراول با code ثابت |

برای message، plural و interpolation از FormatJS/react-intl استفاده شود؛ افزودن dependency در A06 همراه بررسی peer dependency و lockfile انجام شود. جملات با اتصال چند رشته ساخته نشوند؛ template کامل ترجمه شود. کلید پیام typed و parity کلیدهای دو زبان در CI بررسی شود. route، enum value، field path، request_id و error.code ترجمه نمی‌شوند. client، locale مجاز را در Accept-Language می‌فرستد؛ responseهای locale-dependent خصوصی باشند و cache با locale کلید بخورد. ترتیب sort نمایش با locale و tie-breaker ID تعریف شود؛ API ID و مقایسهٔ revision مستقل بمانند.

## چیدمان و متن دوجهته

حاشیه و فاصله با margin-inline، padding-inline، inset-inline و text-align:start/end نوشته شوند؛ border و radius منطقی برای گوشه‌های وابسته به جهت. left/right فقط برای معنای فیزیکیِ آگاهانه، مثل timeline صوت، مجاز است. وارونه‌کردن کل صفحه با transform یا row-reverse سراسری ممنوع است؛ ترتیب DOM باید با خواندن و Tab سازگار بماند. Grid و flex بر اساس محور inline، جای sidebar و drawer را عوض کنند؛ ترتیب کارت‌ها و جدول از قرارداد component تبعیت کند.

عنوان، نام هنرمند و متن ورودی کاربر با dir=auto و در inlineهای حساس با bdi ایزوله شوند. نام ضبط برای «مناسب‌شدن LTR» transliterate یا ترجمهٔ خودکار نشود. ایمیل، URL، hash، UUID، کد OTP/recovery و زمان پخش، LTR و قابل copy باقی بمانند. شمارهٔ شمارشی در UI با Intl محلی شود؛ مقدار کپی‌شدهٔ شناسه و payload اعداد استاندارد داشته باشد. placeholder جای label را نگیرد. input عددی یا OTP صفحه‌کلید مناسب داشته باشد؛ digits فارسی/عربی در ورودی کد با تبدیل محدود به ASCII، بدون دستکاری رمز عبور، اعتبارسنجی شوند.

جدول ادمین در RTL ستون آغاز را راست و در LTR چپ قرار می‌دهد؛ overflow محدود داخل table container است. sticky column و منوی سطری از inline-start/end استفاده کنند. tooltip، popover، dialog و portalها direction جاری را دریافت کنند. تغییر زبان هنگام بازبودن dialog باید label و alignment را درست نگه دارد و focus نپرد. فرم dirty در تغییر locale حفظ شود؛ warning خروج/بستن هم ترجمه شود.

## سیاست آیکون و کنترل صوت

| معنا | رفتار RTL/LTR |
| --- | --- |
| back/forward ناوبری، chevron شروع/پایان، بازکردن sidebar | مطابق محور inline؛ فقط glyph جهت‌دار برعکس شود |
| play/pause، skip previous/next، shuffle، repeat، volume | علامت استاندارد رسانه ثابت؛ ترتیب زمان عوض نشود |
| بالا/پایین ترتیب، upload/download، status، brand | ثابت؛ label محلی و معنای روشن |
| spinner/equalizer تزئینی | aria-hidden؛ تابع motion؛ بدون القای progress واقعی |
| icon-only action | نام ترجمه‌شده، focus-visible، aria-pressed در toggle؛ tooltip تنها نام نباشد |

seek و محور زمانی در هر دو جهت LTR هستند. ArrowRight برابر +۵ ثانیه و ArrowLeft برابر −۵ ثانیه؛ زمان گذشته از چپ به راست زیاد می‌شود. volume محور مشخص و label درصد محلی دارد. keyboard shortcut در input، textarea، contenteditable یا dialog فعال مزاحم تایپ نشود. هر عمل با دکمه نیز قابل انجام است. وضعیت loading/error از event صوت و پاسخ API می‌آید؛ shader و equalizer تزئینی، waveform واقعی معرفی نمی‌شوند.

## زبان بصری و حالت‌های مشترک

هویت موجودِ زیتونی، لیمویی و کاغذی حفظ می‌شود؛ رنگ‌ها در [سیستم طراحی](DESIGN_SYSTEM.fa.md) تعریف شده‌اند. به tokenها نقش‌های semantic مانند on-accent، focus، danger، warning، success و scrim اضافه شود. هر رنگ متن/سطح، برای هر theme بررسی contrast دارد. متن عادی هدف 4.5:1، متن بزرگ و اجزای معنادار UI هدف 3:1 دارند؛ رنگ مرز تزئینی به‌تنهایی کنترل قابل تشخیص نیست. گرادینت یا glass پشت متن بدون سطح خوانای پایدار استفاده نشود.

فونت‌های محلی Vazirmatn و Manrope باقی می‌مانند؛ fallback مناسب scriptهای دیگر، line-height کافی فارسی و font-loading بدون تغییر شدید layout. متن انگلیسی طولانی، pseudo-localization با حداقل ۴۰٪ انبساط، ellipsis همراه راه دسترسی به نام کامل، zoom ۲۰۰٪ و reflow در ۴۰۰٪ بررسی شوند. player ثابت نباید محتوا، focus یا safe-area پایین را بپوشاند. اندازهٔ هدف لمسی ۴۴px انتخاب طراحی است؛ کنترل‌های فشرده باید معیار فاصله/اندازهٔ WCAG را رعایت کنند.

primitiveها حالت‌های default، hover/focus، disabled، busy، invalid و success لازم را دارند. featureها loading، empty، error، forbidden، session expired و conflict را جدا نمایش می‌دهند؛ empty ناشی از مجوز با «هیچ داده‌ای نداریم» اشتباه نشود. 401 خروج کنترل‌شده، 403 نبود مجوز، 409 حفظ draft، 422 خطای field، 423 step-up قابل لغو، 429 زمان retry و قطع شبکه، رفتار روشن و ترجمه‌شده دارند. خطاها request_id قابل copy و پیام بدون path داخلی دارند.

## پذیرش منابع 21st.dev و shader

[21st.dev components](https://21st.dev/community/components) منبع انتخاب و ایده است؛ [icons](https://21st.dev/community/icons) چند خانواده با مجوز مستقل دارد. خانوادهٔ اصلی همان Lucide/ISC موجود است؛ مخلوط‌کردن اندازه و stroke خانواده‌های متعدد لازم نیست. رجیستری UiIcon، نام معنایی را به import محلی Lucide وصل می‌کند. runtime هیچ درخواست یا script به 21st.dev نمی‌فرستد.

انتخاب shader برای A07، [Dither Flow از ham7a311](https://21st.dev/@ham7a311/components/dither-flow) است. منبع [Vitrine در commit ثابت](https://github.com/ham7a311/Vitrine/blob/3adb12a6173883561dc40213fa39ae9dc4d0ce20/src/registry/backgrounds/dither-flow/DitherFlow.tsx)، blob کد `eb8273aef893113ead05c4915623d61a68876418` و [مجوز MIT](https://github.com/ham7a311/Vitrine/blob/3adb12a6173883561dc40213fa39ae9dc4d0ce20/LICENSE)، blob مجوز `dc7f5a9b79f500372338ba1287cf67851c2033e3` بررسی شدند. وضعیت فعلی selected_source_verified است؛ vendoring و تطبیق wrapper هنوز اجرا نشده‌اند. کپی از branch متحرک یا ادعای ادغام‌شدنِ صرفاً با ثبت لینک مجاز نیست.

در ادغام، کد و CSS منبع، dependencyها، دسترسی شبکه، innerHTML، lifecycle و license بازبینی شوند. notice کامل مجوز در THIRD_PARTY_NOTICES و فایل license حفظ شود؛ بدون اجرای خودکار CLI ناشناخته. wrapper محصول palette روشن/تیره، reduced motion، visibility، GPU fallback و teardown را اعمال می‌کند. منبعی که این قرارداد را برآورده نکند adapt می‌شود یا جای خود را به fallback کدنویسی‌شدهٔ محلی می‌دهد؛ فرم و player به جلوه وابسته نیستند.

ShaderBackdrop فقط در hero کتابخانه و نمای گسترش‌یافتهٔ NowPlaying، به‌صورت decorative/aria-hidden/pointer-events:none قرار گیرد. در فرم امنیت، import و جدول مدیریت، جلوهٔ متحرک به‌کار نرود. EffectBoundary خطای WebGL، context loss یا compile را به سطح CSS ثابت تبدیل کند؛ text و CTA بیرون canvas باشند. lazy load، لغو requestAnimationFrame، حذف buffer/program/listener، توقف هنگام hidden/offscreen و احترام به save-data در صورت پشتیبانی لازم‌اند. motion کمتر یا خاموش یعنی هیچ loop فعال باقی نماند.

بودجه‌های هدف، هنوز اندازه‌گیری‌نشده: حداکثر یک canvas فعال، DPR≤1.5، سقف 30fps و بارگذاری اختیاری جلوه حداکثر 60KiB gzip افزوده. افت مداوم frame به fallback ثابت منتهی شود؛ shader نباید پخش یا interaction را متوقف کند. پشتیبانی WebGL شرط استفاده از برنامه نیست. کمبود GPU یا APIهای ترجیح باتری نباید باعث حدس‌زدن توان دستگاه یا fingerprinting شود.

## شرط پذیرش همگانی

هر مسیر از ورود تا پنل و هر component کاتالوگ، ماتریس direction × theme × locale را دارد؛ light/dark در هر دو جهت و هر دو زبان، system با شبیه‌سازی تغییر تنظیم، و reduced motion جداگانه بررسی شوند. مقایسهٔ تصویری با fixtureهای ثابت و semantic assertions انجام شود؛ snapshot به‌تنهایی صحت مجوز یا دسترس‌پذیری را ثابت نمی‌کند. baseline تصویر بدون بررسی reviewer تغییر نکند.

هدف مرورگر در زمان انتشار: نسخهٔ پایدار Chrome، Firefox و Safari و نسخهٔ پایدار پیشین آن‌ها، Safari روی iOS و Chrome روی Android واقعی؛ نسخه‌ها در گزارش pin شوند. keyboard، NVDA/Firefox و VoiceOver/Safari، خطای بارگذاری فونت/تصویر، WebGL غیرفعال، شبکهٔ کند و تغییر locale هنگام پخش/فرم dirty آزمایش شوند. WCAG 2.2 AA هدف است؛ گواهی یا پوشش نامحدودِ همهٔ دستگاه‌ها ادعا نمی‌شود.

منابع فنی: [W3C: جهت ساختاری HTML](https://www.w3.org/International/questions/qa-html-dir)، [W3C: متن inline دوجهته](https://www.w3.org/International/articles/inline-bidi-markup/)، [FormatJS React Intl](https://formatjs.github.io/docs/react-intl/)، [WCAG 2.2](https://www.w3.org/TR/WCAG22/). تصمیم‌های محصول و بودجه‌ها متعلق به این پروژه‌اند و نتیجهٔ اندازه‌گیری نیستند.
