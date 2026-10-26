# API داخلی نسخهٔ ۱

پیشوند تمام مسیرها `/api/v1` است. برنامه و API در یک مبدأ اجرا می‌شوند. داده‌های JSON موفق با `data` و فهرست‌های resource با `data/meta/links` برمی‌گردند. تمام ارتباط‌های رابط با کاتالوگ، حساب، علاقه‌مندی، پخش و مدیریت از همین API عبور می‌کنند؛ تنها assetهای استاتیک build مانند فونت و JS مستقیم سرو می‌شوند.

## نشست و CSRF

ابتدا GET `/session` برای دریافت `data.csrf_token` و کاربر فعلی فراخوانی می‌شود. مرورگر cookie session را با credentials همان مبدأ می‌فرستد. برای POST/PUT/PATCH/DELETE هدر `X-CSRF-TOKEN` لازم است. پاسخ‌های login/logout/password توکن به‌روز را می‌دهند. cookie نام پیش‌فرض `ahang-khatkesh-session` دارد و در تنظیمات Laravel قابل تغییر است. session در دیتابیس است و در تولید cookie باید Secure و HTTP-only باشد.

| روش | مسیر | مجوز | ورودی / خروجی |
| --- | --- | --- | --- |
| GET | /session | عمومی | csrf_token و user یا null |
| POST | /login | عمومی + CSRF | email,password؛ session جدید |
| POST | /logout | عضو فعال | invalidate و csrf_token جدید |
| PATCH | /me | عضو فعال | theme=light/dark/system؛ UserResource |
| PUT | /me/password | عضو فعال | current_password,password,password_confirmation |
| GET | /stats | عضو فعال | تعداد پلی‌لیست و آهنگ قابل مشاهده |
| GET | /playlists | عضو فعال | q,mood,saved=0/1,page؛ صفحهٔ 24تایی |
| GET | /playlists/{playlist} | عضو فعال + policy | اطلاعات و آهنگ‌های مرتب‌شده |
| GET/HEAD | /playlists/{playlist}/cover | عضو فعال + policy | فایل کاور خصوصی |
| GET | /tracks | عضو فعال | q,liked=0/1,page؛ صفحهٔ 50تایی |
| GET/HEAD | /tracks/{track}/stream | عضو فعال + policy | صوت خصوصی با Range |
| PUT / DELETE | /tracks/{track}/like | عضو فعال + policy | افزودن/برداشتن لایک شخصی |
| PUT / DELETE | /playlists/{playlist}/save | عضو فعال + policy | ذخیره/حذف از کتابخانهٔ شخصی |
| GET | /playback | عضو فعال | صف فیلترشده، آهنگ جاری و revision |
| PUT | /playback | عضو فعال | ذخیرهٔ snapshot و بازگرداندن revision جدید |
| GET | /admin/users | مدیر فعال | اعضا و capacity=10 |
| POST | /admin/users | مدیر فعال | name,email,password,is_admin اختیاری |
| PATCH | /admin/users/{user} | مدیر فعال | is_active؛ غیرفعال‌سازی خود رد می‌شود |
| GET | /admin/media | مدیر فعال | assetهای بارگذاری‌شده، صفحهٔ 50تایی |
| POST | /admin/media | مدیر فعال | multipart audio؛ شناسهٔ asset و duplicate |
| GET | /admin/imports | مدیر فعال | سابقه، صفحهٔ 20تایی |
| POST | /admin/imports | مدیر فعال | multipart name,description,mood,manifest و Idempotency-Key |
| GET | /admin/imports/{import} | مدیر فعال | snapshot و status/report |
| POST | /admin/imports/{import}/commit | مدیر فعال | publish boolean؛ status پردازش |
| PATCH | /admin/playlists/{playlist} | مدیر فعال | name,description,mood,status |
| POST | /admin/playlists/{playlist}/cover | مدیر فعال | multipart cover تا 5 MiB و 4096×4096 |

## snapshot پخش

فیلدهای ورودی: track_id عدد یا null، queue آرایهٔ یکتای شناسه‌های Track تا 500 عضو، position_seconds عدد، volume از صفر تا یک، shuffle boolean، repeat_mode یکی از off/all/one و revision عدد صحیح غیرمنفی. track_id باید داخل queue باشد. هر شناسه باید برای کاربر قابل مشاهده باشد. طول زمان با طول واقعی فایل محدود می‌شود. در GET، queue به‌جای شناسه، TrackResourceها را به همان ترتیب برمی‌گرداند. بازگردانی خودکار به معنی autoplay نیست.

## خطاها و نرخ درخواست

| کد | معنی |
| --- | --- |
| 401 | نشست معتبر احراز هویت وجود ندارد |
| 403 | حساب غیرفعال یا مجوز مدیر لازم است |
| 404 | رکورد پیدا نشده یا محتوای قابل مشاهده نیست |
| 409 | revision قدیمی یا استفادهٔ متفاوت از کلید idempotency |
| 413 | محدودیت لایهٔ وب/PHP برای فایل بزرگ‌تر از حد مجاز |
| 416 | Range صوت خارج از طول فایل |
| 419 | نشست/CSRF منقضی؛ رابط یک بار توکن را تازه می‌کند |
| 422 | message و errors با نام فیلدها |
| 429 | محدودیت نرخ؛ Retry-After را رعایت کنید |

ورود: 5 تلاش در دقیقه برای ترکیب ایمیل/IP و 30 برای IP. درخواست‌های عادی کاتالوگ و نوشتن‌های حساب: 180 در دقیقه برای کاربر. آپلود علاوه بر آن، 30 در دقیقه برای مدیر. صوت و کاور زیر throttle کاتالوگ نیستند؛ Rangeهای معمول پلیر نباید با درخواست API عادی اشتباه شوند. درگاه وب باید حفاظت سطح اتصال را جداگانه تنظیم کند.

اطلاعات runtime و قالب درخواست‌ها در [openapi.json](openapi.json) با OpenAPI 3.1 موجود است. پاسخ‌های path داخلی و رمز در schema عمومی قرار ندارند. ابزارهای Swagger/OpenAPI می‌توانند این فایل را برای مستندات یا تولید client مصرف کنند.
