import { useState } from "react";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { LogOut } from "lucide-react";
import { api, getCsrfToken, json } from "../lib/api";
import { useSession, useTheme, useToast } from "../context/AppContext";
import { Dialog } from "./UI";
import { MfaPanel } from "../features/security/MfaPanel";
import type { Theme, User } from "../types";

export function SettingsDialog({
    open,
    onOpenChange,
}: {
    open: boolean;
    onOpenChange: (value: boolean) => void;
}) {
    const theme = useTheme(),
        session = useSession(),
        notify = useToast(),
        client = useQueryClient();
    const [passwords, setPasswords] = useState({
        current_password: "",
        password: "",
        password_confirmation: "",
    });
    const changeTheme = useMutation({
        mutationFn: (value: Theme) =>
            api<{ data: User }>("/me", {
                method: "PATCH",
                body: json({ theme: value }),
            }),
        onSuccess: ({ data }) =>
            session.setSession({
                data: { user: data, csrf_token: getCsrfToken() },
            }),
        onError: (error) => notify(error.message, true),
    });
    const password = useMutation({
        mutationFn: () =>
            api("/me/password", { method: "PUT", body: json(passwords) }),
        onSuccess: () => {
            setPasswords({
                current_password: "",
                password: "",
                password_confirmation: "",
            });
            notify("رمز عبور با موفقیت تغییر کرد.");
        },
        onError: (error) => notify(error.message, true),
    });
    const logout = useMutation({
        mutationFn: () =>
            api<{ data: { csrf_token: string } }>("/logout", {
                method: "POST",
            }),
        onSuccess: (value) => {
            onOpenChange(false);
            session.setSession({
                data: { user: null, csrf_token: value.data.csrf_token },
            });
            client.removeQueries({
                predicate: (q) => q.queryKey[0] !== "session",
            });
        },
        onError: (error) => notify(error.message, true),
    });
    return (
        <Dialog
            open={open}
            onOpenChange={(value) => {
                onOpenChange(value);
                if (!value)
                    setPasswords({
                        current_password: "",
                        password: "",
                        password_confirmation: "",
                    });
            }}
            title="حساب و تجربهٔ تو"
            description={session.user?.email || "تنظیمات حساب"}
        >
            <div className="settings-theme">
                <h3>ظاهر برنامه</h3>
                <div className="filter-chips">
                    {(["light", "dark", "system"] as Theme[]).map((value) => (
                        <button
                            key={value}
                            className={theme.theme === value ? "selected" : ""}
                            aria-pressed={theme.theme === value}
                            disabled={changeTheme.isPending}
                            onClick={() => {
                                theme.setTheme(value);
                                changeTheme.mutate(value);
                            }}
                        >
                            {value === "light"
                                ? "روشن"
                                : value === "dark"
                                  ? "تیره"
                                  : "هماهنگ با دستگاه"}
                        </button>
                    ))}
                </div>
            </div>
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    password.mutate();
                }}
                className="stack-form"
            >
                <h3>تغییر رمز عبور</h3>
                <label>
                    رمز فعلی
                    <input
                        type="password"
                        autoComplete="current-password"
                        dir="ltr"
                        required
                        value={passwords.current_password}
                        onChange={(e) =>
                            setPasswords((s) => ({
                                ...s,
                                current_password: e.target.value,
                            }))
                        }
                    />
                </label>
                <label>
                    رمز جدید
                    <input
                        type="password"
                        autoComplete="new-password"
                        minLength={12}
                        maxLength={128}
                        dir="ltr"
                        required
                        value={passwords.password}
                        onChange={(e) =>
                            setPasswords((s) => ({
                                ...s,
                                password: e.target.value,
                            }))
                        }
                    />
                    <small>حداقل ۱۲ نویسه، شامل حرف و عدد</small>
                </label>
                <label>
                    تکرار رمز جدید
                    <input
                        type="password"
                        autoComplete="new-password"
                        dir="ltr"
                        required
                        value={passwords.password_confirmation}
                        onChange={(e) =>
                            setPasswords((s) => ({
                                ...s,
                                password_confirmation: e.target.value,
                            }))
                        }
                    />
                </label>
                <button
                    className="button secondary"
                    disabled={password.isPending}
                >
                    ذخیرهٔ رمز عبور
                </button>
            </form>
            {open && <MfaPanel />}
            <button
                className="button danger logout-button"
                disabled={logout.isPending}
                onClick={() => logout.mutate()}
            >
                <LogOut size={17} />
                خروج از حساب
            </button>
        </Dialog>
    );
}
