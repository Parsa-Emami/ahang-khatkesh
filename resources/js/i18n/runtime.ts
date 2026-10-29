import domainEnglish from "../../../lang/en.json";
import domainPersian from "../../../lang/fa.json";
import { createIntl, createIntlCache } from "react-intl";
import fa from "./messages/fa-IR.json";
import en from "./messages/en-US.json";
import { defaults, type Preferences } from "./locales";
export type MessageKey = keyof typeof fa;
const messages = {
    "fa-IR": fa,
    "en-US": en satisfies Record<MessageKey, string>,
};
const cache = createIntlCache();
let preferences = defaults;
let intl = createIntl(
    { locale: preferences.locale, messages: messages[preferences.locale] },
    cache,
);
export const currentPreferences = () => preferences;
export function setRuntimePreferences(value: Preferences) {
    preferences = value;
    intl = createIntl(
        {
            locale: value.locale,
            messages: messages[value.locale],
            timeZone: value.time_zone,
        },
        cache,
    );
}
export function tr(
    key: MessageKey,
    values: Record<string, unknown> = {},
): string {
    const safe = Object.fromEntries(
        Object.entries(values).map(([name, value]) => [
            name,
            value === false || value === null || value === undefined
                ? ""
                : typeof value === "string" || typeof value === "number"
                  ? value
                  : String(value),
        ]),
    );
    const pattern = messages[preferences.locale][key];
    for (const name of Object.keys(safe)) {
        if (pattern.includes("{" + name + ", plural,")) {
            const value = safe[name];
            safe[name] =
                typeof value === "string"
                    ? Number(asciiDigits(value).replace(/[,٬]/g, ""))
                    : value;
        }
    }
    return intl.formatMessage({ id: key }, safe);
}
export function number(value: number, options: Intl.NumberFormatOptions = {}) {
    return new Intl.NumberFormat(preferences.locale, options).format(value);
}
export function date(
    value: string | number | Date,
    options: Intl.DateTimeFormatOptions = {},
) {
    return new Intl.DateTimeFormat(preferences.locale, {
        timeZone: preferences.time_zone,
        ...options,
    }).format(new Date(value));
}
export function asciiDigits(value: string) {
    return value.replace(/[۰-۹٠-٩]/g, (digit) =>
        String(digit.charCodeAt(0) - (digit <= "٩" ? 1632 : 1776)),
    );
}

export function roleLabel(role: string | undefined) {
    return tr(
        role === "owner"
            ? "role.owner"
            : role === "administrator"
              ? "role.administrator"
              : role === "curator"
                ? "role.curator"
                : "role.listener",
    );
}
const knownMessages = new Map<string, MessageKey>([
    ...Object.entries(fa).map(
        ([key, value]) => [value, key] as [string, MessageKey],
    ),
    ...Object.entries(en).map(
        ([key, value]) => [value, key] as [string, MessageKey],
    ),
]);
export function localizeText(value: string) {
    const key = knownMessages.get(value);
    if (key) return tr(key);
    if (preferences.locale === "en-US")
        return (domainEnglish as Record<string, string>)[value] || value;
    return (
        (domainPersian as Record<string, string>)[value] ||
        Object.entries(domainEnglish).find(
            ([, english]) => english === value,
        )?.[0] ||
        value
    );
}
