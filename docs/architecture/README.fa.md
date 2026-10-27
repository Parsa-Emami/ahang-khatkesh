# قرارداد ساختاری معماری

[blueprint.json](blueprint.json) inventory ماشین‌خوانِ **طرح هدف** است؛ فایل تنظیم runtime، migration، route فعال یا گواهی صحت نیست. نسخهٔ اجرا 0.2 و هدف طراحی 0.3 است. همهٔ بسته‌های implementation در آغاز planned و gateهای انتشار open هستند.

فایل شامل stack، یافته‌ها و وابستگی بسته‌ها، ۶۴ قرارداد component، سیاست locale/theme/icon/shader، profile تبدیل صوت، بودجهٔ worker و APIهای برنامه‌ریزی‌شده است. source موجود فقط وقتی existing_sources دارد به پیاده‌سازی پایه اشاره می‌کند؛ وضعیت existing_requires_work به معنی تکمیل universal نیست. target_path محل پیشنهادی استخراج/ساخت است، نه لینک به فایل موجود.

بررسی مستقل از dependency:

```bash
node scripts/check-architecture.mjs
```

این checker قرارداد ساختار، شناسه‌های یکتا، پوشش یافته‌ها، DAG بسته‌ها، وجود source/doc، ثبت همهٔ C01 تا C64، ماتریس universal، مجوز pin شده و روابط timeout/bitrate را می‌سنجد. این بررسی، JSON Schema عمومی، اجرای UI، صحت تبدیل، امنیت یا performance را اعتبارسنجی نمی‌کند. CI آن را جدا از تست‌های application اجرا می‌کند. برای تغییر ساختار، هم سند خواندنی و هم blueprint به‌روز شوند؛ success checker یافتهٔ runtime را closed نمی‌کند.

اسناد مرجع: [بازبینی](../ARCHITECTURE_REVIEW.fa.md)، [هدف](../ARCHITECTURE_TARGET.fa.md)، [رابط universal](../UNIVERSAL_UI.fa.md)، [کامپوننت‌ها](../COMPONENT_CATALOG.fa.md)، [رسانه](../MEDIA_PIPELINE.fa.md) و [نقشهٔ اجرا](../IMPLEMENTATION_ROADMAP.fa.md).
