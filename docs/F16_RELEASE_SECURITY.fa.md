# F16: شواهد انتشار و بازبینی مستقل امنیت

مرجع: `docs/F16_THREAT_MODEL.fa.md`، `docs/F16_ASVS_MAPPING.fa.md`، `docs/verification/f16-negative-inventory.json`، `docs/verification/f16-release-evidence.template.json`، `tests/Feature/ReleaseSecurityReviewTest.php`، `tests/Feature/UniversalReleaseMatrixTest.php`.

## مرز فاز

F16 چارچوب evidence، نگاشت ASVS، تست‌های منفی تکمیلی و دروازهٔ production را در مخزن پیاده می‌کند. سبزشدن CI به‌تنهایی تأیید production نیست. `production_approved` فقط پس از امضای بازبین مستقل، ماتریس دستگاه/صفحه‌خوان، DAST/pentest مجاز، restore/rollback و مالک پذیرش روشن می‌شود.

## آنچه اتومات است

- هدرهای امنیتی، CSP در production، Cache-Control خصوصی API، rate-limit ورود
- فهرست تست‌های منفی موجود (auth/MFA/IDOR/path/privacy) در inventory
- قالب evidence انتشار و ماتریس دستگاه با `signed: false` تا تکمیل انسانی
- دروازهٔ `check-roadmap` برای F16 و منع `production_approved` بدون امضا

## آنچه انسانی می‌ماند

DAST روی staging با دادهٔ مصنوعی، pentest مجاز، Safari/Firefox/iOS/Android فیزیکی، NVDA/VoiceOver، restore drill واقعی، rollback تأییدشده، و امضای بازبین ≠ نویسنده.

آزمون‌ها: `ReleaseSecurityReview`، `UniversalReleaseMatrix`.
