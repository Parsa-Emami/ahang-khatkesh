import {
    createContext,
    useContext,
    useSyncExternalStore,
    useLayoutEffect,
    useEffect,
    type PropsWithChildren,
} from "react";
import { IntlProvider } from "react-intl";
import fa from "./messages/fa-IR.json";
import en from "./messages/en-US.json";
import {
    readPreferences,
    normalizePreferences,
    resolveDirection,
    type Preferences,
} from "./locales";
import {
    setRuntimePreferences,
    currentPreferences,
    subscribePreferences,
} from "./runtime";
const initial =
    typeof localStorage === "undefined"
        ? normalizePreferences()
        : readPreferences();
setRuntimePreferences(initial);
const LocaleContext = createContext({
    ...initial,
    dir: resolveDirection(initial),
    setPreferences: (_value: Partial<Preferences>) => {},
});
export function applyPreferences(value: Preferences) {
    document.documentElement.lang = value.locale;
    document.documentElement.dir = resolveDirection(value);
    document.documentElement.dataset.motion = value.motion;
    setRuntimePreferences(value);
}
export function LocaleProvider({ children }: PropsWithChildren) {
    const preferences = useSyncExternalStore(
        subscribePreferences,
        currentPreferences,
        currentPreferences,
    );
    const setPreferences = (value: Partial<Preferences>) =>
        setRuntimePreferences(
            normalizePreferences({ ...currentPreferences(), ...value }),
        );
    useLayoutEffect(() => {
        applyPreferences(preferences);
        try {
            localStorage.setItem("ak-preferences", JSON.stringify(preferences));
        } catch {}
    }, [preferences]);
    useEffect(() => {
        const listener = (event: StorageEvent) => {
            if (event.key === "ak-preferences")
                setRuntimePreferences(readPreferences());
        };
        window.addEventListener("storage", listener);
        return () => window.removeEventListener("storage", listener);
    }, []);
    return (
        <LocaleContext.Provider
            value={{
                ...preferences,
                dir: resolveDirection(preferences),
                setPreferences,
            }}
        >
            <IntlProvider
                locale={preferences.locale}
                messages={preferences.locale === "fa-IR" ? fa : en}
                timeZone={preferences.time_zone}
            >
                {children}
            </IntlProvider>
        </LocaleContext.Provider>
    );
}
export const useLocale = () => useContext(LocaleContext);
