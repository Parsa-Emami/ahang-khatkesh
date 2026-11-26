# P01-C — Composer security remediation

The previous main run 45771789254 failed Composer audit with 17 advisories across seven packages. The isolated resolver run 45772341293 passed `composer audit --locked`, with the resulting lock SHA-256 `26b5a37849d46915b6a6e698efa76bd52da3a5728e9a1e05234a4818d276d643`. The artifact is bound to source commit `0a3e5a916d6a047510cc5c5077d8e74f32402cfb` and was committed to the isolated branch in `7b67217bb86667ba4e126aae19258d605522f10c`.

| Package | Resolved version |
|---|---|
| laravel/framework | 13.44.1 |
| symfony/http-foundation | 8.1.12 |
| symfony/http-kernel | 8.1.12 |
| symfony/routing | 8.1.12 |
| symfony/serializer | 8.1.12 |
| symfony/yaml | 8.1.12 |
| web-auth/webauthn-lib | 5.3.17 |

No advisory suppression or manual lockfile editing. The resolver used Composer without running dependency scripts or plugins; composer.json was unchanged. The temporary branch-only write workflow has been removed. CI now audits the locked dependencies before installing or executing package scripts. Failed test jobs only upload partial visual artifacts when present; successful jobs still require expected artifacts.

Acceptance remains conditional on exact-head full SQLite/MySQL regression, frontend tests, npm advisory checks, browser/MFA journeys and review of the resulting dependency diff. Do not deploy or claim production readiness solely from the Composer audit.
