# F12: پنل کامل مدیریت کاتالوگ و درخواست اعضا

مرجع پیاده‌سازی Actionهای `app/Actions/Catalog`، سرویس‌های `app/Services/Catalog`، کنترلرهای admin/member و پنل‌های `CatalogGovernancePanel` و `ProposalsPage` است. عضو هرگز Track/Playlist را مستقیم نمی‌نویسد؛ فقط پیشنهاد می‌سازد. پذیرش، ادغام، تعمیر و ویرایش گروهی فقط برای owner با MFA و step-up رمز تازه است.

## ویرایش گروهی

`POST /admin/catalog-batches` پیش‌نمایش dry-run می‌سازد. plan و snapshot در `catalog_change_batches` ذخیره می‌شوند و `plan_hash` قفل محتواست. `commit` فقط با همان hash و وضعیت preview زنده (۳۰ دقیقه) اعمال می‌شود. `rollback` از snapshot، Track/Artist و membershipهای like/playlist/release را برمی‌گرداند. audit بدون secret: `catalog.batch_previewed|committed|rolled_back`.

انواع: `track_metadata`، `artist_rename`، `track_move`، `merge_recordings`، `repair`. revision کهنه، hash غلط و دستهٔ مصرف‌شده 409 می‌گیرند.

## تشخیص تکراری و سلامت

`GET /admin/catalog-duplicates` نامزدها را بر اساس هویت ضبط و provenance برمی‌گرداند: `shared_bytes`، `shared_lineage`، `recording_candidate`، `name_collision`، `provenance_collision` و `homonym`. هم‌نامی هنرمند ادغام نمی‌شود. `GET /admin/catalog-health` فایل گمشده، primary_release شکسته و credit اصلیِ غایب را فهرست می‌کند. `GET /admin/workflows` شمارش catalog/import/conversion/trash/members/audit/operations و اقدام بعدی را می‌دهد.

ادغام دو ضبط، شناسهٔ survivor و لایک/عضویت را حفظ و source را soft-delete می‌کند؛ rollback آن را برمی‌گرداند.

## پیشنهاد اعضا

جدول `member_proposals` با وضعیت‌های pending/accepted/rejected/withdrawn. عضو از `/proposals` پیشنهاد می‌سازد یا پس می‌گیرد. سوپرادمین از `/admin/proposals` با revision می‌پذیرد یا رد می‌کند. پذیرش `track_metadata`/`artist_rename`/`duplicate_report` از مسیر batch می‌گذرد؛ `playlist_create` پلی‌لیست draft با مالکیت سوپرادمین می‌سازد؛ `repair_report` برای primary شکسته، clear را اعمال می‌کند و برای فایل گمشده فقط ack می‌شود چون بازآپلود مسیر رسانهٔ موجود است.

## آزمون

`CatalogBulkChangeTest` و `MemberProposalTest` الزامی‌اند. قرارداد مسیرها در `docs/openapi.json` هم‌تراز Route است.
