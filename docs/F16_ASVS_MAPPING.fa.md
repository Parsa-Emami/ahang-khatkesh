# نگاشت ASVS 5.0.0 → شواهد

وضعیت `automated` یعنی شاهد تست/CI در مخزن است. `human` تا امضا باز می‌ماند و مانع `production_approved` است.

| ASVS | کنترل | صاحب | شاهد | وضعیت |
| --- | --- | --- | --- | --- |
| V2 Auth | MFA/session/epoch | Owner | MfaFlowTest، CredentialEpochTest | automated |
| V3 Session | CSRF، context header، regenerate | Owner | ApiResponseSchemaTest، Playwright CSRF | automated |
| V4 Access | Gate/Policy/IDOR | Owner | AccountAccessTest، SuperAdministratorTest | automated |
| V5 Validation | FormRequest/allowlist | Owner | AdministrationTest audit filter | automated |
| V7 Cryptography | hashed password، encrypted MFA | Owner | Fortify + SecurityRegressionTest | automated |
| V8 Stored crypto / keys | APP_KEY offline envelope | Owner | ops:backup-manifest key_recovery | automated |
| V9 Communication | TLS + Secure cookie | Ops | human:staging-tls | human |
| V10 Malicious input | upload/path/lyrics HTML refuse | Owner | MediaProbeTest، LyricsTimelineTest | automated |
| V11 Business logic | revision/lease/fencing | Owner | Import/Conversion/PlaybackDevice tests | automated |
| V12 Files | private media، X-Accel internal | Owner | MusicLibraryTest، TenConcurrentStreamsTest | automated |
| V13 API | OpenAPI sync، no-store | Owner | ApiContractTest، SecurityHeaders | automated |
| V14 Config | APP_DEBUG=false deploy guide | Ops | AFTER_F16 + human:deploy | human |
| V50 Secure SDLC | SAST/DAST/dependency/pentest | Reviewer | CI audits + human:dast/pentest | mixed |
| V51 Assistive | keyboard/SR | Reviewer | F09/F10 browser + human:NVDA/VoiceOver | mixed |

یافته‌های CURRENT_REVIEW: N13/N15 با این نگاشت و inventory پوشش داده می‌شوند ولی تا امضای انسانی «بستهٔ انتشار» محسوب نمی‌شوند.
