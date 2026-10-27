# تصمیم‌های معماری

شناسه‌ها در این بازبینی یکتا شده‌اند؛ شماره‌های تکراری در سند قبلی مبنای ارجاع جدید نباشند. وضعیت «موجود» توصیف انتخاب یا رفتار پایه است و به معنی بی‌نقص‌بودن تمام مسیرها نیست؛ شکاف‌های باز در [بازبینی](ARCHITECTURE_REVIEW.fa.md) ثبت شده‌اند. «هدف پذیرفته‌شده» تصمیم طراحی برای اجراست و runtime آماده معرفی نمی‌شود.

| ADR | وضعیت | تصمیم و دلیل |
| --- | --- | --- |
| 001 | موجود | modular monolith در Laravel؛ تراکنش و استقرار ساده برای ده حساب؛ تفکیک سرویس پس از نیاز اندازه‌گیری‌شده |
| 002 | موجود | React SPA همان origin با session/CSRF و API داخلی؛ player مستقل از route؛ Sanctum فقط برای client مستقل آینده |
| 003 | موجود | فایل private و متادیتا در MySQL؛ blob باینری در جدول نگهداری نمی‌شود |
| 004 | موجود، شکاف R01 | جدایی preview و commit؛ gate نهایی و effective plan در ADR-017 تکمیل می‌شود |
| 005 | موجود | queue دیتابیس روی همان SQL connection و تراکنش؛ Redis/DB دیگر طراحی تحویل تازه می‌خواهد |
| 006 | موجود | قفل ردیف ثابت و ترتیب مشخص برای نوشتن حساس؛ صرفاً برای ده حساب و تا مشاهدهٔ contention |
| 007 | موجود | revision وضعیت پخش؛ snapshot قدیمی، نسخهٔ تازه را overwrite نکند |
| 008 | موجود، شکاف R07 | Track مشترک برای asset اصلی و حفظ متادیتای موجود؛ blob فیزیکی در ADR-019 جدا می‌شود |
| 009 | موجود | CSS token، composition و primitive مشترک؛ تم‌ها و RTL؛ از کپی بی‌مجوز پرهیز شود |
| 010 | موجود | manifest JSON/CSV با asset_id داخلی؛ نه URL سرویس خارجی یا مسیر فایل |
| 011 | موجود | MySQL 8.4/InnoDB/utf8mb4 و UTC هدف؛ phpMyAdmin ابزار اپراتور با credential جدا و دسترسی محدود |
| 012 | موجود | پنل مشترک React، lazy و capability؛ owner/administrator/curator/listener؛ is_admin مرجع مجوز نیست |
| 013 | موجود، شکاف R02 | Fortify MFA/recovery و recent verification؛ بدون TOTP سفارشی؛ epoch مشترک در ADR-018 تکمیل می‌شود |
| 014 | موجود | Artist/Release/Track/credit مستقل و ID قدیمی پایدار؛ homonym خودکار merge نمی‌شود؛ artist/album projection هستند |
| 015 | موجود | ویرایش revisionدار و archive؛ حذف فیزیکی عمومی و تکرار Track در playlist v1 وجود ندارد |
| 016 | هدف پذیرفته‌شده | final authorization و invariant gate در Action با actor تازه، epoch، مالکیت و revision در تراکنش؛ اعتبار preview کافی نیست |
| 017 | هدف پذیرفته‌شده | effective immutable import plan با resolved metadata/hash/version؛ تغییر مؤثر، review تازه؛ خطای دائمی retry کور ندارد |
| 018 | هدف پذیرفته‌شده | mutation مشترک امنیت با fresh epoch و compare snapshot؛ hash بیرون قفل و audit اتمیک؛ نشست قدیمی حق stamp تازه ندارد |
| 019 | هدف پذیرفته‌شده | media_blobs هویت بایت و media_assets هویت منطقی؛ duplicate bytes دلیل ادغام recording نیست؛ cutover با سازگاری ID/API |
| 020 | هدف پذیرفته‌شده | immutable file publish + durable DB cleanup + GC دارای مرجع/retention/fencing؛ شکست cleanup ارجاع جدید را خراب نکند |
| 021 | هدف پذیرفته‌شده | generation، lease و reconciler برای import/conversion؛ failed و callback قدیمی حق تغییر نسل تازه ندارند |
| 022 | هدف پذیرفته‌شده | universal UI با fa-IR/en-US، dir ریشه، CSS منطقی، bdi و message catalog؛ theme/motion مستقل و Audio پایدار |
| 023 | هدف پذیرفته‌شده | Lucide محلی و Dither Flow از 21st.dev با commit/license pin شده؛ wrapper، fallback، بودجه و بدون اتصال runtime ثالث |
| 024 | هدف پذیرفته‌شده | FLAC اصلی محفوظ؛ MP3 CBR320 با libmp3lame به‌صورت مشتق private، source lineage و validation کامل؛ بدون overwrite master |
| 025 | هدف پذیرفته‌شده | release با شواهد schema/API، browser، MySQL concurrency، load، restore، ASVS و WCAG؛ عدد هدف یا checker ساختار نتیجهٔ production نیست |

جزئیات قراردادها در [معماری هدف](ARCHITECTURE_TARGET.fa.md)، [universal UI](UNIVERSAL_UI.fa.md)، [رسانه](MEDIA_PIPELINE.fa.md) و [نقشهٔ پیاده‌سازی](IMPLEMENTATION_ROADMAP.fa.md) قرار دارند. migrationهای منتشرشده تغییر نمی‌کنند؛ expand/backfill/verify/contract برای evolution جدید لازم است. تغییر source یا dependency ثالث نیازمند بررسی مجوز، کد و lockfile است؛ انتخاب نام تجاریِ یک الگو یا ابزار، جای رفع مسئلهٔ واقعی را نمی‌گیرد.
