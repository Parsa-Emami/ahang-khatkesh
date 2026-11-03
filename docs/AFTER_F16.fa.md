# پس از F16 — برش Release Candidate

۱. `APP_DEBUG=false`، TLS، `SESSION_SECURE_COOKIE=true`، CSP production، least privilege DB، isolation workerها را روی staging اعمال کنید.
۲. `ops:backup-manifest` + restore drill واقعی روی ماشین جدا؛ نتیجه را در evidence با `restore_drill.signed=true` ثبت کنید.
۳. rollback را rehearsal کنید و `rollback.signed=true` بگذارید.
۴. DAST فقط staging مجاز با دادهٔ مصنوعی؛ false positive را در evidence بنویسید.
۵. ماتریس Safari/Firefox/Chromium و iOS/Android + NVDA/VoiceOver را امضا کنید.
۶. بازبین مستقل ≠ نویسنده، `independent_reviewer` را امضا کند.
۷. مالک پذیرش RC را تأیید کند؛ فقط سپس `production_approved` می‌تواند true شود.

CI سبز بدون این امضاها production نیست.
