import { useLocale } from "./LocaleProvider";
import { tr } from "./runtime";
import type { Locale, Direction, Motion, Preferences } from "./locales";
export function PreferenceControls({
    onChange,
}: {
    onChange?: (value: Partial<Preferences>) => void;
}) {
    const preferences = useLocale();
    function change(value: Partial<Preferences>) {
        preferences.setPreferences(value);
        onChange?.(value);
    }
    return (
        <div className="preference-controls">
            <label>
                {tr("settings.language")}
                <select
                    dir="auto"
                    name="locale"
                    value={preferences.locale}
                    onChange={(event) =>
                        change({ locale: event.target.value as Locale })
                    }
                >
                    <option value="fa-IR" lang="fa">
                        فارسی
                    </option>
                    <option value="en-US" lang="en">
                        English
                    </option>
                </select>
            </label>
            <label>
                {tr("settings.direction")}
                <select
                    dir="auto"
                    name="direction"
                    value={preferences.direction}
                    onChange={(event) =>
                        change({ direction: event.target.value as Direction })
                    }
                >
                    <option value="auto">{tr("settings.autoDirection")}</option>
                    <option value="rtl">{tr("settings.rtl")}</option>
                    <option value="ltr">{tr("settings.ltr")}</option>
                </select>
            </label>
            <label>
                {tr("settings.motion")}
                <select
                    dir="auto"
                    name="motion"
                    value={preferences.motion}
                    onChange={(event) =>
                        change({ motion: event.target.value as Motion })
                    }
                >
                    <option value="system">
                        {tr("settings.systemMotion")}
                    </option>
                    <option value="full">{tr("settings.fullMotion")}</option>
                    <option value="reduced">
                        {tr("settings.reducedMotion")}
                    </option>
                </select>
            </label>
            {onChange && (
                <label>
                    {tr("settings.timeZone")}
                    <select
                        dir="auto"
                        name="time_zone"
                        value={preferences.time_zone}
                        onChange={(event) =>
                            change({ time_zone: event.target.value })
                        }
                    >
                        {[
                            ...new Set([
                                preferences.time_zone,
                                "Asia/Tehran",
                                "UTC",
                                "Europe/Berlin",
                                "Europe/London",
                                "America/New_York",
                            ]),
                        ].map((zone) => (
                            <option value={zone} key={zone}>
                                {zone}
                            </option>
                        ))}
                    </select>
                </label>
            )}
        </div>
    );
}
