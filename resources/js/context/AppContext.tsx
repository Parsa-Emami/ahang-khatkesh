import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useState,
    type PropsWithChildren,
} from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import {
    api,
    getCsrfToken,
    setSessionIdentity,
    invalidateSessionIdentity,
} from "../lib/api";
import type { Theme, User } from "../types";

type SessionResult = { data: { user: User | null; csrf_token: string } };
const SessionContext = createContext<{
    user: User | null;
    pending: boolean;
    error: Error | null;
    refresh: () => void;
    setSession: (value: SessionResult) => void;
} | null>(null);
export function SessionProvider({ children }: PropsWithChildren) {
    const client = useQueryClient();
    const session = useQuery({
        queryKey: ["session"],
        queryFn: () => api<SessionResult>("/session"),
        retry: 1,
        staleTime: Infinity,
    });
    useEffect(() => {
        const clearPrivate = () => {
            void client.cancelQueries({
                predicate: (query) => query.queryKey[0] !== "session",
            });
            client.removeQueries({
                predicate: (query) => query.queryKey[0] !== "session",
            });
        };
        const expired = () => {
            invalidateSessionIdentity();
            clearPrivate();
            client.setQueryData(["session"], {
                data: { user: null, csrf_token: "" },
            });
        };
        const changed = () => {
            expired();
            void client.invalidateQueries({ queryKey: ["session"] });
        };
        const received = (event: Event) => {
            const user = (event as CustomEvent<User | null>).detail;
            const previous = client.getQueryData<SessionResult>(["session"])
                ?.data.user;
            if (
                previous?.id !== user?.id ||
                previous?.access_version !== user?.access_version
            )
                clearPrivate();
            client.setQueryData(["session"], {
                data: { user, csrf_token: getCsrfToken() },
            });
        };
        const channel =
            typeof BroadcastChannel === "undefined"
                ? null
                : new BroadcastChannel("ak-session-v1");
        const announce = () => channel?.postMessage("session-changed");
        if (channel)
            channel.onmessage = (event) => {
                if (event.data === "session-changed") changed();
            };
        window.addEventListener("session-expired", expired);
        window.addEventListener("session-context-changed", changed);
        window.addEventListener("session-identity", received);
        window.addEventListener("session-auth-change", announce);
        return () => {
            window.removeEventListener("session-expired", expired);
            window.removeEventListener("session-context-changed", changed);
            window.removeEventListener("session-identity", received);
            window.removeEventListener("session-auth-change", announce);
            channel?.close();
        };
    }, [client]);
    return (
        <SessionContext.Provider
            value={{
                user: session.data?.data.user || null,
                pending: session.isPending,
                error: session.error,
                refresh: () => void session.refetch(),
                setSession: (value) => {
                    setSessionIdentity(value.data.user);
                    client.setQueryData(["session"], value);
                },
            }}
        >
            {children}
        </SessionContext.Provider>
    );
}
export function useSession() {
    const context = useContext(SessionContext);
    if (!context) throw new Error("SessionProvider required");
    return context;
}

const ThemeContext = createContext<{
    theme: Theme;
    dark: boolean;
    setTheme: (theme: Theme) => void;
} | null>(null);
export function ThemeProvider({ children }: PropsWithChildren) {
    const [theme, setTheme] = useState<Theme>(() => {
        try {
            const saved = localStorage.getItem("ak-theme");
            if (saved === "light" || saved === "dark") return saved;
        } catch {}
        return "system";
    });
    const [systemDark, setSystemDark] = useState(
        () => matchMedia("(prefers-color-scheme: dark)").matches,
    );
    useEffect(() => {
        const query = matchMedia("(prefers-color-scheme: dark)");
        const listener = () => setSystemDark(query.matches);
        query.addEventListener("change", listener);
        return () => query.removeEventListener("change", listener);
    }, []);
    const dark = theme === "system" ? systemDark : theme === "dark";
    useEffect(() => {
        document.documentElement.dataset.theme = dark ? "dark" : "light";
        document.documentElement.style.colorScheme = dark ? "dark" : "light";
        try {
            localStorage.setItem("ak-theme", theme);
        } catch {}
    }, [theme, dark]);
    return (
        <ThemeContext.Provider value={{ theme, dark, setTheme }}>
            {children}
        </ThemeContext.Provider>
    );
}
export function useTheme() {
    const context = useContext(ThemeContext);
    if (!context) throw new Error("ThemeProvider required");
    return context;
}

const ToastContext = createContext<(message: string, error?: boolean) => void>(
    () => {},
);
export function ToastProvider({ children }: PropsWithChildren) {
    const [toast, setToast] = useState<{
        message: string;
        error: boolean;
        id: number;
    } | null>(null);
    const notify = useCallback(
        (message: string, error = false) =>
            setToast({ message, error, id: Date.now() }),
        [],
    );
    useEffect(() => {
        if (!toast) return;
        const timer = window.setTimeout(() => setToast(null), 6500);
        return () => clearTimeout(timer);
    }, [toast]);
    return (
        <ToastContext.Provider value={notify}>
            {children}
            <div className="toast-region" aria-live="polite">
                {toast && (
                    <div
                        className={`toast ${toast.error ? "is-error" : ""}`}
                        role={toast.error ? "alert" : "status"}
                    >
                        {toast.message}
                        <button
                            aria-label="بستن پیام"
                            onClick={() => setToast(null)}
                        >
                            ×
                        </button>
                    </div>
                )}
            </div>
        </ToastContext.Provider>
    );
}
export const useToast = () => useContext(ToastContext);
