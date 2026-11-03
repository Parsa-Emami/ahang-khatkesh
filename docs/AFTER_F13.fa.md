# پس از F13

Migration جدید: `2026_11_02_140000_create_playback_enrichment`. جدول `track_lyrics` و فیلدهای loudness/crossfade اضافه می‌شوند. dependency تازه نیست.

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

در Settings، محدودیت crossfade را تأیید و سپس فعال کنید. برای یک Track، متن LRC دارای مجوز ذخیره و در Now Playing همگام‌سازی با seek را ببینید. Loudness را فقط به‌صورت metadata ثبت کنید. فاز بعد F14 است.
