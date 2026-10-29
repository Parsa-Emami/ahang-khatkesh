# F09 — زبان، جهت و دسترس‌پذیری پایه

مبنای پیاده‌سازی `9b01fe2c30a443af42bcd8a05b28ed2930d1bedc` است. وضعیت تأیید از `roadmap/phases.json` و evidence همان source خوانده می‌شود.

## رفتار اجرایی

`LocaleProvider` بالای router، نشست و پلیر قرار دارد. ترجمهٔ فارسی/انگلیسی با catalog تایپ‌شده و FormatJS اجرا می‌شود. متن رابط، نام قابل دسترس کنترل‌ها، پنجره‌ها، toast، وضعیت‌ها، خطاهای تبدیل و فرم‌های مدیریت به catalog منتقل شده‌اند. دادهٔ کاتالوگ مانند نام آهنگ، هنرمند و نام فایل ترجمه نمی‌شود. فونت‌ها همچنان محلی‌اند.

زبان `fa-IR/en-US` و جهت `auto/rtl/ltr` مستقل‌اند. `auto` تابع زبان است. تم همان light/dark/system باقی می‌ماند؛ تغییر سیستم بدون reload اعمال می‌شود. motion برابر system/full/reduced است و CSS ترجیح reduced سیستم را حتی در full محترم می‌داند. زبان، جهت و تم پیش از اولین frame با فایل محلی preferences.js اعمال می‌شوند. مقدار خراب localStorage به default امن برمی‌گردد. تغییر ترجیح، instance صوت یا صف و draft را دوباره نمی‌سازد.

تنظیمات حساب locale، direction، motion، time_zone و theme را از `PATCH /api/v1/me` ذخیره می‌کند. فقط همین فیلدهای allowlist قابل نوشتن‌اند؛ شناسه، نقش و access_version تغییری نمی‌کنند. درخواست‌ها Accept-Language مجاز می‌فرستند. middleware برای هر درخواست زبان را از allowlist تعیین می‌کند؛ response API دارای Content-Language، Vary: Accept-Language و private/no-store است. migration افزایشی مقدار پیش‌فرض فارسی/جهت خودکار را برای حساب‌های قبلی حفظ می‌کند.

تاریخ و عدد با Intl و منطقهٔ زمانی انتخابی نمایش داده می‌شوند. زمان API UTC و شناسه‌ها و payload ثابت‌اند؛ duration صوت محور زمانی است و عمداً LTR می‌ماند. OTP فارسی/عربی به ASCII تبدیل می‌شود؛ رمز عبور دستکاری نمی‌شود. متن مختلط از جهت auto و isolation در محل‌های نمایش داده استفاده می‌کند. جهت آیکون ناوبری، drawer موبایل و CSS فاصله‌های منطقی با جهت رابط هماهنگ است؛ نشانه‌های استاندارد رسانه وارونه نمی‌شوند.

## خروج از فرم

router داده‌ای React Router امکان مسدودکردن route و Back را می‌دهد؛ providers و پلیر داخل مسیر catch-all ثابت می‌مانند. registry مشترک draft برای artist/release/track، ترتیب انتشار و پلی‌لیست، اطلاعات پلی‌لیست، حساب عضو، reset رمز، ایمپورت، تنظیمات رمز و setup/recovery رمزساز کار می‌کند. تغییر تب‌های مدیریت که فرم ایمپورت را در حافظه نگه می‌دارند مجاز است؛ خروج از /admin همچنان guard دارد. بستن dialog و Escape در فرم dirty همان تأیید روشنِ ادامه یا کنارگذاشتن را نشان می‌دهند. خروج/refresh کامل صفحه از beforeunload بومی استفاده می‌کند؛ متن این هشدار را مرورگر تعیین می‌کند. هیچ رمز، کد MFA یا draft در localStorage ذخیره نمی‌شود.

در conflict یا پاسخ ناموفق، draft حفظ می‌شود. کنارگذاشتن فرم ایمپورت، job منتشرشده در سرور را لغو نمی‌کند؛ فقط انتخاب‌های محلی را پاک می‌کند. draftها در حافظه‌اند و پس از تأیید خروج باقی نمی‌مانند. کنترل‌های جستجو و فیلتر که ذخیرهٔ داده ندارند draft محسوب نمی‌شوند.

## دسترس‌پذیری و دامنهٔ بررسی

Dialog جهت جاری، focus trap و بازگشت focus به کنترل آغازگر دارد. drawer بستهٔ موبایل از Tab خارج است و drawer باز Escape و چرخهٔ Tab دارد. ورودی‌های نامعتبر aria-invalid و توضیح خطای متصل با aria-describedby دارند. خطای بومی و پاسخ 422 قابل ترجمه‌اند؛ toast/status/alert همان نقش معنایی خود را حفظ می‌کنند. slider صوت LTR است و حجم نام و مقدار قابل دسترس دارد. reduced motion جلوه‌های موجود را کاهش می‌دهد.

[نگاشت ۶۴ قرارداد](verification/f09-component-contracts.json) قرارداد زبان/جهت/تم را برای تمام inventory ثبت می‌کند. این فایل ادعای ۶۴ تست مستقل یا پیاده‌سازی تمام قابلیت‌های آینده نیست: C34 و C35 جلوه‌های F10 هستند و فعلاً فقط قرارداد مشترک را به ارث می‌برند. نواقص عملکردی آیندهٔ هر component در فاز مربوط باقی می‌مانند.

آزمون‌های تازه: LocalePreferencesTest برای ترجیحات، مجوز، خطای محلی و fallback زبان؛ مجموعهٔ locale direction theme matrix برای parity، ICU، Intl، مقدار نامعتبر، escaping و header؛ سناریوهای مرورگر برای ۲ زبان × ۲ جهت × ۳ تم در عرض‌های ۳۲۰، ۳۹۰، ۷۶۸ و ۱۴۴۰، zoom ۲۰۰٪، system theme، reduced motion، field error و focus؛ DraftNavigationTest برای instance واقعی Audio و draft هنگام تغییر زبان/تم، بستن dialog، route، Back و unload.

این فاز آزمون semantics قابل دسترس در Chromium دارد. آزمون انسانی NVDA/VoiceOver، Safari/Firefox، دستگاه فیزیکی، ممیزی کامل WCAG و release مستقل در F16 باقی است؛ CI معادل آن‌ها نیست. ابزار، تعداد و نتیجهٔ واقعی هر اجرا فقط پس از مشاهده در evidence ثبت می‌شود.


بررسی npm در این نسخه با OSV-Scanner 2.7.0 و SHA-256 ثابت انجام می‌شود: snapshot عمومی کل npm دریافت و تطبیق با package-lock فقط با `--offline --no-resolve` محلی اجرا می‌شود. `npm ci` ارسال خودکار audit ندارد. هیچ فایل قفل یا درخت وابستگی به سرویس audit ارسال نمی‌شود. خطای دریافت پایگاه یا scanner، بررسی را fail می‌کند؛ gate حذف نشده است. مرجع: https://google.github.io/osv-scanner/offline-mode/ .
