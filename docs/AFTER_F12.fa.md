# پس از F12

F12 migration جدید `2026_11_02_120000_create_catalog_governance` دارد. جدول‌های `catalog_change_batches` و `member_proposals` اضافه می‌شوند. dependency تازه‌ای نیست. [قرارداد F12](F12_CATALOG_GOVERNANCE.fa.md) مرجع رفتار است.

ابتدا backup معتبر بگیرید، workerها را متوقف و برنامه را maintenance کنید:

```bash
php artisan down
git pull --ff-only
composer install --no-dev --prefer-dist --no-interaction
php artisan migrate --force
npm ci
npm run build
php artisan optimize:clear
php artisan queue:restart
php artisan up
```

پس از ارتقا، در پنل admin بخش «حکمرانی» را باز کنید: workflows، duplicates، health، proposals و bulk. به‌عنوان عضو یک پیشنهاد عنوان بسازید و با سوپرادمین بپذیرید/رد کنید. برای bulk، preview سپس commit و در صورت نیاز rollback را امتحان کنید.

تأیید production و دستگاه فیزیکی در F16 باقی است. فاز بعدی F13 است.
