# مدل داده

## موجودیت‌ها

| جدول | ستون‌های دامنه | قاعده |
| --- | --- | --- |
| users | id, name, email, password, is_admin, is_active, theme, remember_token | ایمیل یکتا، رمز hash؛ ظرفیت کل ۱۰ از مسیر Action |
| library_settings | id, lock_version | ردیف ثابت ۱ برای سری‌سازی نوشتن‌های حساس |
| media_assets | UUID id, sha256, path, original_name, mime_type, size_bytes, duration_seconds, uploaded_by | hash یکتا؛ یک فایل واقعی برای هر محتوای صوتی |
| tracks | id, media_asset_id, title, artist, album, search_text | هر asset حداکثر یک Track و متادیتای مشترک دارد |
| playlists | id, name, description, mood, status, cover_path, artwork_seed, created_by, search_text | published/draft؛ دسترسی با policy |
| playlist_track | playlist_id, track_id, position | هر آهنگ و هر position در یک پلی‌لیست یکتا |
| playlist_imports | UUID id, created_by, idempotency_key, payload_hash, name, description, mood, rows, status, report, playlist_id, publish, error | کلید idempotency در محدودهٔ مدیر یکتا؛ snapshot ردیف‌ها |
| liked_tracks | user_id, track_id | کلید مرکب و ثبت idempotent |
| saved_playlists | user_id, playlist_id | کلید مرکب و دادهٔ شخصی |
| playback_states | id, user_id, track_id, queue, position_seconds, volume, shuffle, repeat_mode, revision | یک ردیف برای هر کاربر؛ queue فهرست شناسه‌ها |
| audit_events | id, user_id, action, subject_id, metadata | ثبت اعمال مدیریتی بدون رمز یا payload کامل درخواست |

timestampهای معمول created_at/updated_at در جدول‌های دامنه وجود دارند. session، cache، jobs و failed_jobs از migrationهای استاندارد Laravel ایجاد می‌شوند.

## روابط و حذف

کاربر سازندهٔ فایل و پلی‌لیست است و حذف او به‌دلیل FK محدود می‌شود؛ در UI به‌جای حذف، حساب غیرفعال می‌شود. فایل صوتی مورد استفادهٔ Track قابل حذف مستقیم نیست. حذف playlist رابطه‌های آن را cascade می‌کند و حذف Track به‌دلیل playlist_track محدود است. شناسهٔ import و asset از نوع UUID، و شناسهٔ Track/Playlist از نوع bigint است. مجوز به نوع یا قابل حدس بودن شناسه متکی نیست.

از باینری صوت در ستون BLOB استفاده نمی‌شود. `path` و `cover_path` مسیر داخلی‌اند. `queue` یک snapshot منطقی از شناسه‌هاست و FK جدا برای هر عضو JSON ندارد؛ پیش از نوشتن اعتبارسنجی و هنگام بازگردانی دوباره بر اساس دسترسی جاری فیلتر می‌شود.

## قواعد مهم

- حالت انتشار فقط draft یا published از API پذیرفته می‌شود.
- mood یکی از focus/night/calm/energy/other است.
- تکرار فایل در یک manifest پذیرفته نمی‌شود؛ یک Track می‌تواند در چند Playlist باشد.
- هنگام استفادهٔ دوباره از Track، متادیتای قبلی حفظ و در گزارش اعلام می‌شود.
- مقدار volume بین صفر و یک و position حداکثر برابر طول واقعی فایل ذخیره می‌شود.
- revision با هر ذخیرهٔ موفق یک واحد افزایش می‌یابد؛ مقدار قدیمی 409 می‌گیرد.
- سقف ۵۰۰ آهنگ مربوط به هر import است؛ تعداد کل کاتالوگ محدود به ظرفیت فضای فایل و سرور است.
- تعداد ۱۰، تعداد حساب است؛ تعداد session و اتصال همزمان صوت یک معیار عملیاتی جداست.

## ایندکس‌ها

email، sha256، media_asset_id و user_id وضعیت پخش یکتا هستند. جدول‌های واسط روی کلید مرکب و ترتیب پلی‌لیست قید دارند. کاتالوگ روی status/id و ایمپورت روی status/created_at ایندکس دارد. جستجوی نسخهٔ اول با search_text نرمال‌شده و LIKE است؛ contains search به ایندکس معمولی متکی نیست. افزودن موتور جستجوی مستقل باید از اندازه‌گیری واقعی حجم و زمان query ناشی شود.

## تغییر schema

migrationهای منتشرشده تغییر نمی‌کنند؛ برای توسعهٔ بعدی migration جدید ایجاد کنید. قبل از استقرار، backup از دیتابیس و پوشهٔ private رسانه لازم است. rollback ساختار که جدول را حذف می‌کند داده را برنمی‌گرداند؛ راه پیش‌فرض تولید forward fix است.
