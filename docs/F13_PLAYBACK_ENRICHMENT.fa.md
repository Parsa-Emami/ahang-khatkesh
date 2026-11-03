# F13: متن همگام، crossfade و جزئیات صوت

مرجع: `app/Support/Lyrics`، `app/Actions/Catalog/SaveTrackLyrics`، `resources/js/lib/lrc.ts`، `resources/js/lib/crossfade-engine.ts`، `resources/js/features/lyrics` و تنظیمات پخش در Settings.

## متن

`track_lyrics` فقط plain یا LRC دارد. HTML اجرا یا ذخیره نمی‌شود. منبع `manual` یا `licensed` است؛ licensed به `license_note` نیاز دارد. سقف ۶۴ کیلوبایت. LRC BOM را حذف می‌کند، ثانیهٔ ≥۶۰ و زمان منفی/بزرگ‌تر از مدت آهنگ را رد می‌کند و timestamp تکراری را با نگه‌داشتن آخرین خط کنترل می‌کند. GET برای Track قابل‌مشاهده؛ نوشتن فقط owner با MFA و step-up.

پنل متن در Now Playing با seek همگام است. پیمایش قابل توقف است و با `prefers-reduced-motion` نرم حرکت نمی‌کند.

## Crossfade

پیش‌فرض خاموش است و تا تأیید محدودیت دستگاه‌ها (`crossfade_acknowledged`) فعال نمی‌شود. پیاده‌سازی با دو `HTMLAudioElement` و fade حجمی است؛ teardown هنگام seek/pause/dispose منبع دوم را قطع می‌کند تا دوپخش نماند. این همپوشانی، gapless سطح codec نیست.

## Loudness و محدودیت‌ها

`loudness_lufs` روی `media_assets` فقط metadata است و master را بازنویسی نمی‌کند. نرمال‌سازی پخش اختیاری و gain محدود است. ماتریس دستگاه در `detectPlaybackCapabilities` ثبت می‌کند: gapless بومی نداریم، Safari/iOS و بلوتوث fade را ناپایدار می‌کنند. AutoMix یا Apple Sing ادعا نمی‌شود.

آزمون‌ها: `LyricsTimelineTest`، `CrossfadeDeviceMatrix`.
