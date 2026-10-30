# قرارداد API نسخهٔ 0.2

تمام مسیرها زیر `/api/v1` و web middleware لاراول هستند. اتصال همان مبدأ با cookie و CSRF انجام می‌شود؛ bearer token در browser storage وجود ندارد. [OpenAPI 3.1](openapi.json) هر ۵۳ عملیات فعلی را فهرست می‌کند و تست ApiContractTest، نبودن مسیر بدون سند یا سند بدون مسیر را بررسی می‌کند. GETها HEAD خودکار Laravel نیز دارند؛ Range و HEAD صوت در تست feature جدا بررسی می‌شوند.

| گروه | عملیات | مجوز و رفتار |
| --- | --- | --- |
| نشست | session، login، login/challenge، logout | session عمومی؛ login دو مرحله‌ای در MFA؛ registration عمومی بسته |
| حساب | me، me/password، me/password/confirm | ترجیح ظاهر، رمز فعلی و تأیید تازه |
| MFA | me/mfa، confirm، recovery-codes | secret فقط در enrollment؛ recovery فقط با MFA و step-up؛ no-store |
| کتابخانه | playlists، tracks، stats، cover، stream | فقط محتوای قابل مشاهده؛ صوت و کاور نیز policy دارند |
| شخصی | like/unlike، save/unsave، playback | کاربر جاری؛ playback با revision |
| ادمین: خواندن | overview، operations، audit-events | capability مستقل و MFA تأییدشده |
| اعضا | users GET/POST/PATCH، users/{user}/password PUT | users.manage و step-up؛ تغییر نقش ممتاز فقط مالک |
| رسانه | media GET/POST | media.manage؛ آپلود خصوصی و FFprobe |
| import | imports، imports/{id}، commit | imports.manage؛ multipart manifest و Idempotency-Key؛ job اتمیک |
| کاتالوگ | artists GET/POST/PATCH، releases GET/POST/PATCH/detail | catalog.manage؛ هویت مستقل؛ revision برای ویرایش |
| روابط | releases/{release}/tracks PUT، tracks/{track} PATCH | شمارهٔ دیسک/آهنگ یکتا، اعتبار هنری و primary release |
| نسخهٔ رسانه | tracks/{track}/renditions POST | catalog.manage + media.manage؛ ثبت نسخهٔ موجود، بدون transcoding |
| پلی‌لیست | playlists POST/PATCH، tracks PUT، cover POST | catalog.manage؛ draft اولیه، revision، انتشار/بایگانی |

خواندن ادمین به enrollment MFA و نشست MFAشده نیاز دارد. نوشتن ادمین و خواندن/چرخش recovery به تأیید رمز و OTP حداکثر ۱۵ دقیقه نیاز دارد. پاسخ 423 دارای code=`password_confirmation_required` و 428 دارای code=`mfa_enrollment_required` است. challenge پس از ۱۰ دقیقه یا تغییر access_version با 422 و code=`challenge_expired` رد می‌شود.

409 در ویرایش به معنی revision قدیمی است؛ تازه‌سازی و تصمیم آگاهانه لازم است. cover در multipart نیز revision می‌گیرد. وضعیت playlist شامل draft/published/archived است؛ انتشار مجموعهٔ خالی رد می‌شود. ردیف‌های manifest می‌توانند artist_id و release_id موجود داشته باشند؛ metadata آهنگ مشترک بازنویسی نمی‌شود.

خروجی User شامل role، role_label، permissions و mfa_enabled است؛ is_admin فقط سازگاری قدیمی و deprecated است. secret، recovery، password و مسیر فایل از Resource کاربر/آهنگ حذف‌اند. کد بازیابی و QR فقط در endpoint مشخص امنیت برمی‌گردند و نباید وارد Query cache یا لاگ شوند.

فهرست‌های catalog و playlist با page؛ audit با cursor و ترتیب id نزولی است. فیلتر since/until روی audit تاریخ میلادی UTC و until شامل تمام آن روز است. metadata رویداد allowlist دارد. URI کاور و stream داخلی‌اند؛ خطای visibility به 404 تبدیل می‌شود.

## تاریخچه و میکس F11

GET/PATCH `listening/settings`، POST `listening/plays` و `listening/events`، GET/DELETE `listening/history`، DELETE `listening/history/{eventId}`، GET `listening/export` و `listening/mix` فقط برای حساب جاری‌اند. mutationها به revision حریم خصوصی و نشست/CSRF جاری نیاز دارند. playback با listening_revision و private_mode از snapshot خصوصی جلوگیری می‌کند. grant اشتباه 422، نشست دیگر 403، context قدیمی 409 و grant منقضی 410 می‌گیرد. جزئیات آستانه، retention و وزن‌ها در [قرارداد F11](F11_LISTENING_PRIVACY.fa.md) و schema دقیق در OpenAPI است.

## API گردش کار F12

مسیر عادی `member-proposals` فقط درخواست‌های همان حساب را می‌خواند و با UUID تکرار درخواست، پیشنهاد ثبت می‌کند. مسیر `admin/proposals` صندوق owner و reject با revision است. پذیرش از `admin/catalog-changes/preview` با operation=proposal عبور می‌کند. `admin/catalog-changes` دفتر پیش‌نمایش‌ها و `/{changeId}/commit` و `/{changeId}/rollback` با revision و snapshot_hash هستند. `admin/catalog-health` و `admin/workflows` فقط projection مجاز می‌دهند. شکل دقیق ورودی و پاسخ در OpenAPI اجرایی ثبت شده است.

همهٔ نوشتن‌ها session، CSRF و X-Session-Context می‌خواهند؛ نوشتن مدیریتی علاوه بر capability به MFA و تأیید اخیر رمز نیاز دارد. 409 تعارض، 410 پایان مهلت و 422 ورودی یا رسانهٔ نامعتبر است. پاسخ proposal، snapshot، کلید تکرار، epoch و hash payload را افشا نمی‌کند. پاسخ journal، input خام یا before/after داخلی را ندارد؛ فقط summary مجاز نمایش داده می‌شود.
