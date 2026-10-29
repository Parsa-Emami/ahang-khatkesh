# هویت بصری و جلوهٔ محلی F10

F10 هویت زیتونی/لیمویی/کاغذی را با tokenهای معنایی روشن و تیره، سطح ثابت پشت متن، focus سه‌پیکسلی و state مشترک انتظار، خطا و خالی یکپارچه می‌کند. `tokens.css` مرجع رنگ، فاصله، ارتفاع کنترل، گوشه و زمان حرکت است. متن و آیکون معنادار روی سطوح پشتیبانی‌شده آزمون contrast دارند؛ مرز تزئینی با مرز کنترل اشتباه نمی‌شود. ورودی invalid، disabled، placeholder و پیام خطا حالت مشخص دارند. فونت و آیکون محلی قبلی حفظ می‌شوند.

## منبع و مجوز

Dither Flow اثر Hamza Al-Bulushi از مخزن `ham7a311/Vitrine`، commit ثابت `3adb12a6173883561dc40213fa39ae9dc4d0ce20` وارد شده است. نسخهٔ دقیق TSX و CSS و MIT در `third_party/dither-flow` نگه‌داری می‌شود؛ آزمون hash، blob کد `eb8273aef893113ead05c4915623d61a68876418`، CSS برابر `fc76db8b94623b4e8a1f5fe10822f48500dae6c6` و مجوز `dc7f5a9b79f500372338ba1287cf67851c2033e3` را بررسی می‌کند. مجوز کامل در THIRD_PARTY_NOTICES و `public/licenses/dither-flow.txt` نیز توزیع می‌شود.

منبع WebGL خام است و dependency جدید نمی‌خواهد؛ Three.js، CLI رجیستری، script دوردست، innerHTML، texture یا درخواست شبکه ندارد. shader اصلیِ تاخوردگی پارچه و ordered dither حفظ شده؛ pointer stir حذف و lifecycle، رنگ، خطا و بودجه بازنویسی شده‌اند. CSP و قرارداد same-origin موجود تغییر نمی‌کنند. snapshot اصلی فقط سند منبع است و در build کامپایل نمی‌شود.

## اجرا و افت امن

ShaderBackdrop تنها در hero کتابخانه و پنجرهٔ گسترش‌یافتهٔ «همین حالا» وجود دارد. پنجره با دکمهٔ کاور نوار پلیر باز می‌شود، از همان PlayerProvider و Audio استفاده می‌کند، با Escape بسته و focus به دکمه بازمی‌گردد. متن و کنترل‌ها خارج از canvas و روی سطح خوانای ثابت‌اند. جلوه aria-hidden، بدون pointer event و بدون اثر بر مجوز، فرم یا پخش است.

| قید | رفتار اجرایی |
| --- | --- |
| ظرفیت | coordinator مشترک، حداکثر یک canvas؛ پنجرهٔ پخش بر hero اولویت دارد |
| بارگذاری | dynamic import پس از visible و مجازشدن؛ reduced motion هیچ chunk جلوه‌ای درخواست نمی‌کند |
| حرکت | ترجیح reduced کاربر یا سیستم و forced colors هرکدام جلوه را خاموش می‌کنند؛ full کاربر ترجیح سیستم را نقض نمی‌کند |
| صرفه‌جویی | save-data در مرورگر پشتیبان، hidden، offscreen و dialogهای تنظیمات/امنیت به CSS ثابت می‌روند |
| وضوح | DPR حداکثر ۱٫۵ و framebuffer حداکثر ۳۲۰٬۰۰۰ پیکسل |
| زمان | حداکثر ۳۰ draw در ثانیه؛ ۱۵ نمونهٔ warmup، سپس پنجرهٔ ۴۵ نمونه؛ حداقل ۳۶ فاصلهٔ بیش از ۷۰ms باعث fallback دائمی همان instance می‌شود |
| خطا | WebGL ناموجود، compile/link، context loss یا خطای ماژول به CSS ثابت تبدیل می‌شود؛ retry خودکار بی‌پایان ندارد |
| پاک‌سازی | لغو RAF، قطع ResizeObserver/IntersectionObserver/MutationObserver و listenerها، حذف buffer، program و shader و آزادسازی context |
| حافظهٔ تنظیمات | فقط locale/theme/motion موجود؛ هیچ GPU renderer، باتری یا fingerprint دستگاه خوانده نمی‌شود |
| bundle | gate ساخت، closure اختیاری و CSS/assetهای وابسته را با gzip می‌سنجد؛ سقف ۶۰KiB |

وقفهٔ موقت مانند hidden یا offscreen، canvas را جمع می‌کند و پس از بازگشت فقط در صورت داشتن slot از نو می‌سازد. خطای context/compile/performance تا پایان عمر همان backdrop ثابت می‌ماند. EffectBoundary فقط درخت جلوه را می‌گیرد؛ ErrorBoundary کل برنامه و مرز player مستقل‌اند.

## آزمون و حدود شواهد

`effects.test.ts` بودجه، اولویت، compile/link، context loss، throttling، افت مداوم فریم و cleanup تکرارپذیر را می‌سنجد. `design.test.ts` دقیق‌بودن منبع/MIT و ۱۰۴ جفت contrast را بررسی می‌کند. `check-effects.mjs` روی build واقعی lazy بودن و اندازهٔ افزوده را fail-closed کنترل می‌کند.

`f10.e2e.ts` فارسی/انگلیسی × RTL/LTR × روشن/تیره، عرض‌های ۳۲۰/۳۹۰/۷۶۸/۱۴۴۰، متن بلند، zoom دویست درصد، focus و stateهای انتظار/خطا/خالی را با screenshot و assertion معنایی ثبت می‌کند. آزمایش WebGL از Chrome با ANGLE/SwiftShader در CI استفاده می‌کند؛ visibility و save-data به‌طور کنترل‌شده شبیه‌سازی و offscreen با scroll واقعی بررسی می‌شود. این اندازه‌گیری benchmark GPU دستگاه واقعی نیست. عدم بارگیری lazy در motion کمتر، context loss واقعی WebGL و تداوم همان Audio نیز آزمون می‌شوند.

تصاویر فقط از دادهٔ مصنوعی مرورگرند؛ artifact `f10-visual-matrix` چهارده روز در CI می‌ماند. تصاویر مرجع طلایی خودکار نیستند؛ در این فاز ماتریس تصویری بازبینی و هندسه/contrast/رفتار با assertion کنترل می‌شود. baseline تصویری بعدی نباید بدون بازبینی تغییر کند. آزمایش فیزیکی Safari/Firefox، screen reader و بودجهٔ GPU موبایل واقعی در F16 باقی است. گواهی WCAG یا production صادر نشده است.

نتیجهٔ نهایی CI، fingerprint و اندازهٔ اندازه‌گیری‌شده پس از پایان suite در پروندهٔ شواهد F10 ثبت می‌شود؛ خروجی محلی به‌تنهایی تأیید PHP 8.5/MySQL/مرورگر نیست.
