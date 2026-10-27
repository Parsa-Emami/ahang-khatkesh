# کاتالوگ کامپوننت‌ها و قرارداد تکمیل

این سند inventory هدف است؛ وجود نام یا مسیر هدف، به معنی ساختن component نیست. primitiveهای فعلی UI.tsx، Music.tsx و Dialog و اجزای پنل حفظ و به‌تدریج استخراج می‌شوند. [blueprint.json](architecture/blueprint.json) وضعیت existing_requires_work یا planned، گروه و مسیر هدف هر مورد را مشخص می‌کند. تمام موارد، قرارداد [universal](UNIVERSAL_UI.fa.md) را به ارث می‌برند.

## قواعد مشترک

props فقط داده و event لازم را می‌گیرند؛ component نمایشی مستقیم API نمی‌زند. feature container با hook اختصاصی و query key، ارتباط API را اداره می‌کند. primitiveها مجوز را تصمیم نمی‌گیرند؛ feature براساس capability عمل را نمایش می‌دهد و سرور آن را enforce می‌کند. import از داخل feature دیگر از مسیرهای داخلی مجاز نیست؛ API عمومی feature یا primitive مشترک استفاده شود. barrel export سراسری که lazy loading ادمین را بشکند ساخته نشود.

هر component دارای ورودی/خروجی typed، نام قابل ترجمه، contract جهت، variant تم، سیاست motion و حالت‌های لازم است. password/OTP/recovery از props زنجیره‌ای غیرضروری، inspector cache و telemetry دور بمانند. پیام خطا به field واقعی وصل شود؛ spinner بدون متن، feedback کافی نیست. Dialog و Menu واحد، focus، Escape و restore focus یکسان داشته باشند. فرم هنگام mutation از ثبت تکراری جلوگیری می‌کند؛ گم‌شدن پاسخ شبکه، اثبات rollback نیست.

## پایه و پوسته

| ID | جزء | ورودی/رفتار و حالت‌های اختصاصی |
| --- | --- | --- |
| C01 | Button | variant/size، busy، disabled؛ busy حفظ عرض و aria-busy؛ منع double submit |
| C02 | IconButton | icon، label الزامی، pressed و shortcut اختیاری؛ tooltip تکمیلی |
| C03 | UiIcon | name معنایی، اندازه و directional policy؛ icon تزئینی hidden |
| C04 | Field | label، hint، errorId، required و invalid؛ متن دو زبان و input dir مناسب |
| C05 | SelectField | value/options و error؛ انتخاب با keyboard؛ label کامل و عدم وابستگی به رنگ |
| C06 | Dialog | open/onOpenChange/title/description؛ focus trap، Escape، dirty guard و pending |
| C07 | ConfirmDialog | شرح اثر، نام موضوع با bdi، confirm/cancel؛ بدون تأیید مبهم |
| C08 | Tabs | activeId و orientation؛ roving focus و جهت منطقی؛ محتوای inactive کنترل‌شده |
| C09 | Menu | anchor و items؛ focus، portal dir، بسته‌شدن پس از انتخاب |
| C10 | DataTable | columns/rows/rowKey؛ pagination، empty/error، sticky و overflow محدود |
| C11 | Pagination | cursor یا page مطابق API؛ آغاز/پایان و آخرین صفحهٔ خالی |
| C12 | StatusBadge | status به label/icon/token؛ حالت ناشناختهٔ امن |
| C13 | AsyncState | loading/empty/error/forbidden؛ retry مشخص و حفظ دادهٔ قبلیِ مجاز |
| C14 | ToastRegion | پیام محلی، severity، action محدود؛ aria-live بدون تکرار آزاردهنده |
| C15 | ErrorBoundary | خطای render، fallback و recovery؛ پاک‌شدن امن اطلاعات محرمانه |
| C16 | AppShell | ناوبری inline-start، محتوای main، پنل inline-end و player ثابت |
| C17 | Navigation | capability items، active route، drawer و skip link؛ نقش‌های واقعی |
| C18 | SearchBox | query، debounce 250ms، cancel و clear؛ IME فارسی و نتیجهٔ دیررس |
| C19 | LocaleSwitcher | زبان بومی، حفظ route/پخش/draft؛ تغییر document.lang/dir |
| C20 | ThemeSwitcher | light/dark/system؛ واکنش به تنظیم OS؛ رنگ از token |
| C21 | MotionPreference | system/full/reduced؛ reduced سیستم بر full اولویت دارد |

## کتابخانه و پخش

| ID | جزء | ورودی/رفتار و حالت‌های اختصاصی |
| --- | --- | --- |
| C22 | Artwork | cover_url مجاز، fallback محلی، aspect ratio ثابت و alt مناسب |
| C23 | PlaylistCard | entity/revision، لینک و play؛ کنترل لمس، draft/archived مطابق مجوز |
| C24 | TrackRow | title/credits/duration/current/liked؛ نام طولانی و نبود stream |
| C25 | TrackList | order و stable IDs؛ loading/empty/filtered؛ keyboard بدون nested button |
| C26 | LikeButton | mutation شخصی؛ unavailable و خطای شبکه؛ rollback خوش‌بینانهٔ محدود |
| C27 | SavePlaylistButton | ذخیرهٔ شخصی؛ sync با cache همان حساب |
| C28 | PlayerBar | Audio event واقعی؛ idle/loading/playing/paused/buffering/error/ended |
| C29 | SeekSlider | current/duration/seekable؛ LTR ثابت، drag preview و keyboard؛ عدم seek قبل metadata |
| C30 | VolumeControl | mute/level؛ مقدار محلی و محور ثابت؛ خطای autoplay جدا |
| C31 | QueuePanel | ترتیب واقعی، current، previous/next و restore؛ عدم نشت حساب قبلی |
| C32 | NowPlaying | expanded/collapsed؛ credits و media profile واقعی؛ hero بدون پوشاندن متن |
| C33 | PlaybackError | autoplay blocked، auth expired، 404 و codec unsupported؛ retry هدفمند |
| C34 | ShaderBackdrop | palette/theme/motion؛ static fallback، offscreen/hidden و context lost |
| C35 | EffectBoundary | خطای جلوه بدون ازکارافتادن player؛ teardown و fallback |

## امنیت و تنظیمات

| ID | جزء | ورودی/رفتار و حالت‌های اختصاصی |
| --- | --- | --- |
| C36 | LoginForm | password challenge، loading، 422/429؛ حفظ ایمیل و پاک‌کردن secret |
| C37 | MfaChallenge | OTP/recovery، انقضای challenge و بازگشت؛ کدهای LTR |
| C38 | MfaEnrollment | شروع، QR/secret موقت، تأیید و cancel؛ عدم cache پاسخ حساس |
| C39 | RecoveryCodes | نمایش موقت و ذخیرهٔ آگاهانه؛ بستن و خروج، حافظه را پاک کند |
| C40 | StepUpDialog | یک درخواست فعال، password/OTP، cancel/expire؛ intent مربوط به همان actor |
| C41 | SettingsDialog | locale/theme/motion/time zone و رمز؛ بدون remount پلیر |

## مدیریت، import و رسانه

| ID | جزء | ورودی/رفتار و حالت‌های اختصاصی |
| --- | --- | --- |
| C42 | AdminWorkspace | هشت بخش موجود و بخش تبدیل آینده؛ lazy و capability؛ deep link معتبر |
| C43 | OverviewPanel | شمارش واقعی، ظرفیت کاربران و next action؛ unavailable جدا از صفر |
| C44 | UploadQueue | فایل، bytes و نتیجهٔ هر مورد؛ progress واقعی، duplicate، cancel و quota |
| C45 | ImportWizard | upload/resolve/preview/confirm/status؛ بازگشت امن، plan expiry و حفظ داده |
| C46 | ImportPreviewTable | تصمیم create/reuse، هویت مؤثر، warning و row error؛ تغییر plan نیازمند تأیید |
| C47 | ImportStatus | queued/processing/completed/needs_review/failed/cancelled؛ polling توقف‌پذیر |
| C48 | PlaylistEditor | نام/شرح/mood/visibility، revision و dirty guard؛ field error محلی |
| C49 | PlaylistTracksEditor | افزودن/برداشتن/reorder تا 500؛ keyboard؛ حفظ draft و conflict resolver |
| C50 | CoverUploader | preview محدود، type/size/error، revision و اشاره‌گر جدید امن |
| C51 | EntityPicker | artist/release/track ID واقعی؛ homonym، no-result، pagination و cancel stale search |
| C52 | ArtistEditor | نام/credits با ID؛ revision، نام یکسان و اثر تغییر مشترک |
| C53 | ReleaseEditor | disc/track number، ordered credits و membership؛ تعارض محل و revision |
| C54 | RecordingEditor | recording ثابت، اصل/rendition و اثر تغییر روی playlistها؛ duration به‌تنهایی اثبات هویت نیست |
| C55 | MembersPanel | role، active، ظرفیت 10، آخرین owner و step-up؛ خطای همزمانی |
| C56 | MemberPasswordReset | target روشن و اثر بر نشست‌ها؛ secret یک‌بارنمایش و پاک‌سازی |
| C57 | AuditTimeline | cursor، فیلتر UTC/نمایش محلی، request_id؛ عدم خروجی credential |
| C58 | OperationsPanel | queue age/worker heartbeat/disk/backup؛ unknown و stale جدا از healthy |
| C59 | MediaProfileBadge | source FLAC یا MP3 320kbps؛ status واقعی؛ codec نامشخص بدون حدس |
| C60 | ConversionPanel | انتخاب recording/profile، تخمین فضا، مجوز و bulk محدود؛ نه اجرای CLI از مرورگر |
| C61 | ConversionStatus | queued/processing/validating/ready/failed/cancelled؛ پیشرفت تخمینی با برچسب |
| C62 | ConversionDetail | generation، source/output bytes، bitrate و خطای پاک؛ retry/cancel طبق مجوز |
| C63 | StorageBudget | used/reserved/available؛ reservation expiry و insufficient-storage؛ بدون path خصوصی |
| C64 | ConflictResolution | server snapshot و local draft، تفاوت و reload/discard صریح؛ بدون overwrite خودکار |

## سناریوهای مرزی مشترک

| سناریو | انتظار |
| --- | --- |
| تغییر زبان هنگام پخش و بازبودن dialog | Audio و draft ثابت؛ label، direction و focus درست |
| account switch در تب دوم | cache و queue قبلی پاک؛ mutation معلق به نام حساب تازه replay نشود |
| refetch متفاوت در فرم dirty | پیام تغییر سرور؛ draft و baseRevision حفظ شوند |
| shader unavailable یا reduced motion | ظاهر ثابت و خوانا؛ کنترل‌ها قابل استفاده؛ loop خاموش |
| قطع شبکه پس از commit | نتیجه «نامعلوم/در حال بررسی»؛ lookup idempotency/status پیش از تکرار |
| فرمت یا فضای رسانه نامعتبر | خروجی منتشر نشود؛ رزرو آزاد و temp طبق سیاست پاک شود |
| دورشدن focus یا expiry step-up | لغو قابل فهم؛ secret پاک؛ هیچ mutation تأییدنشده اجرا نشود |

برای primitiveها، fixtureهای حالت و تعامل؛ برای featureها، contract API و browser E2E؛ برای player، event و شبکهٔ واقعی لازم‌اند. رجیستری fixture سبک در A06/A07 ایجاد شود؛ Storybook یا ابزار دیگر فقط اگر همین نیاز را با هزینهٔ قابل قبول حل کند افزوده شود. شمارش componentها، جای شواهد استفاده‌پذیری یا طراحی بصری واقعی را نمی‌گیرد.
