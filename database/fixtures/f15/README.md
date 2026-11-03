# F15 legacy upgrade fixture

Artificial, separate from production. Contains the shape expected before expand migrations:

- one listener user
- one track + media asset/blob paths
- liked_tracks, playback_states, playlist membership

Load only into an ephemeral MySQL 8.4 database (`ahang_f15_test`) with `AHANG_F15_MYSQL=1`.

```bash
mysql -h 127.0.0.1 -u ... ahang_f15_test < database/fixtures/f15/legacy_seed.sql
php artisan migrate --force
php artisan media:backfill --apply
php artisan ops:verify-upgrade
```

Do not run against production. phpMyAdmin must stay loopback/VPN and must not mutate product rows.
