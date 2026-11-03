# تهدیدنامه F16 (ASVS 5.0.0)

نسخهٔ pin شدهٔ هدف: **OWASP ASVS 5.0.0 سطح L2** (هم‌تراز `security_target` در blueprint). صاحب کنترل پیش‌فرض: مالک محصول / سوپرادمین عملیاتی.

## دارایی‌ها

- حساب‌ها، نشست، MFA secrets، APP_KEY
- کاتالوگ و رسانهٔ خصوصی، stream، entitlement آفلاین
- تاریخچهٔ شنیدن، audit، backup manifest

## مرز اعتماد

مرورگر همان‌مبدأ ← Laravel session/CSRF/MFA ← MySQL/private media ← Nginx internal X-Accel. phpMyAdmin فقط loopback/VPN و خارج از مسیر mutation محصول است.

## سوءاستفاده‌های اصلی

| تهدید | کنترل | شاهد |
| --- | --- | --- |
| CSRF / session fixation | same-origin session، CSRF، Secure cookie در production | Playwright CSRF؛ SecurityHeaders |
| Credential stuffing | rate-limit login/2FA | ReleaseSecurityReview 429 |
| MFA bypass | challenge نسخه‌دار، recovery یک‌بار | MfaFlowTest، SecurityRegressionTest |
| Privilege / IDOR | Gate/Policy/Action، X-Session-Context | AccountAccessTest، ApiResponseSchemaTest |
| Path traversal / upload | PrivateMedia realpath، FFprobe | MusicLibraryTest، MediaProbeTest |
| XSS | CSP production، React escape، بدون HTML در lyrics | SecurityHeaders، LyricsTimelineTest |
| SQLi | binding Eloquent | AdministrationTest filter injection |
| Cache leakage | private no-store روی API/stream | ListeningPrivacyTest، SecurityHeaders |
| Dependency CVE | composer audit + OSV npm offline | CI checks |
| Offline revoke lag | opt-in + ack | OfflineRevocationTest |

DAST و pentest خارج از مرز اتومات مخزن‌اند و فقط روی staging مجاز با دادهٔ مصنوعی اجرا می‌شوند.
