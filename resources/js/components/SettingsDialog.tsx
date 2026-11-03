import { captureSessionIntent, type SessionIntent } from "../lib/api";
import { PreferenceControls } from "../i18n/PreferenceControls";
import { useDraftNavigation } from "../features/drafts/DraftNavigation";
import type { Preferences } from "../i18n/locales";
import { tr } from "../i18n/runtime";
import { useLocale } from "../i18n/LocaleProvider";
import { useState, useEffect } from "react";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { LogOut } from "lucide-react";
import { api, getCsrfToken, json } from "../lib/api";
import { wipeOfflineStore } from "../lib/offline-store";
import { useSession, useTheme, useToast } from "../context/AppContext";
import { usePlayer } from "../context/PlayerContext";
import { DevicePicker } from "../features/playback/DevicePicker";
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
    useLocale();
    const navigation = useDraftNavigation();
    const theme = useTheme(),
        session = useSession(),
        player = usePlayer(),
        notify = useToast(),
        client = useQueryClient();
    const [passwords, setPasswords] = useState({
        current_password: "",
        password: "",
        password_confirmation: "",
    });
    useEffect(() => {
        if (!open)
            setPasswords({
                current_password: "",
                password: "",
                password_confirmation: "",
            });
    }, [open, session.user?.id, session.user?.access_version]);
    const changeTheme = useMutation({
        scope: { id: "interface-preferences" },
        mutationFn: (intent: SessionIntent<Theme>) =>
            api<{ data: User }>(
                "/me",
                {
                    method: "PATCH",
                    body: json({ theme: intent.value }),
                },
                true,
                true,
                intent,
            ),
        onSuccess: ({ data }) =>
            session.setSession({
                data: { user: data, csrf_token: getCsrfToken() },
            }),
        onError: (error) => notify(error.message, true),
    });
    const preferences = useMutation({
        scope: { id: "interface-preferences" },
        mutationFn: (
            intent: SessionIntent<
                Partial<Preferences> & Record<string, unknown>
            >,
        ) =>
            api<{ data: User }>(
                "/me",
                { method: "PATCH", body: json(intent.value) },
                true,
                true,
                intent,
            ),
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
            notify(tr("m8405c8cc4b"));
        },
        onError: (error) => notify(error.message, true),
    });
    const logout = useMutation({
        mutationFn: async () => {
            await wipeOfflineStore();
            return api<{ data: { csrf_token: string } }>("/logout", {
                method: "POST",
            });
        },
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
            dirty={
                !!(
                    passwords.current_password ||
                    passwords.password ||
                    passwords.password_confirmation
                )
            }
            onDiscard={() =>
                setPasswords({
                    current_password: "",
                    password: "",
                    password_confirmation: "",
                })
            }
            onOpenChange={(value) => {
                onOpenChange(value);
                if (!value)
                    setPasswords({
                        current_password: "",
                        password: "",
                        password_confirmation: "",
                    });
            }}
            title={tr("m3019e60fe5")}
            description={session.user?.email || tr("mb9c7f76721")}
        >
            <PreferenceControls
                onChange={(value) =>
                    preferences.mutate(captureSessionIntent(value))
                }
            />
            <div className="settings-theme">
                <h3>{tr("playback.crossfade")}</h3>
                <p className="muted">{tr("playback.crossfadeHelp")}</p>
                <label className="stack-form">
                    <input
                        type="checkbox"
                        checked={!!session.user?.crossfade_acknowledged}
                        disabled={preferences.isPending}
                        onChange={(event) =>
                            preferences.mutate(
                                captureSessionIntent({
                                    crossfade_acknowledged:
                                        event.target.checked,
                                    crossfade_enabled:
                                        event.target.checked &&
                                        !!session.user?.crossfade_enabled,
                                } as never),
                            )
                        }
                    />
                    {tr("playback.crossfadeAck")}
                </label>
                <label className="stack-form">
                    <input
                        type="checkbox"
                        checked={!!session.user?.crossfade_enabled}
                        disabled={
                            preferences.isPending ||
                            !session.user?.crossfade_acknowledged
                        }
                        onChange={(event) =>
                            preferences.mutate(
                                captureSessionIntent({
                                    crossfade_enabled: event.target.checked,
                                    crossfade_acknowledged: true,
                                    crossfade_seconds:
                                        session.user?.crossfade_seconds ?? 3,
                                } as never),
                            )
                        }
                    />
                    {tr("playback.crossfade")}
                </label>
                <label>
                    {tr("playback.crossfadeSeconds")}
                    <input
                        dir="ltr"
                        type="number"
                        min={1}
                        max={12}
                        value={session.user?.crossfade_seconds ?? 3}
                        disabled={preferences.isPending}
                        onChange={(event) =>
                            preferences.mutate(
                                captureSessionIntent({
                                    crossfade_seconds: Number(
                                        event.target.value,
                                    ),
                                } as never),
                            )
                        }
                    />
                </label>
                <h3>{tr("playback.loudness")}</h3>
                <p className="muted">{tr("playback.loudnessHelp")}</p>
                <label className="stack-form">
                    <input
                        type="checkbox"
                        checked={session.user?.loudness_normalization !== false}
                        disabled={preferences.isPending}
                        onChange={(event) =>
                            preferences.mutate(
                                captureSessionIntent({
                                    loudness_normalization:
                                        event.target.checked,
                                } as never),
                            )
                        }
                    />
                    {tr("playback.loudness")}
                </label>
                <h3>{tr("offline.title")}</h3>
                <p className="muted">{tr("offline.help")}</p>
                <label className="stack-form">
                    <input
                        type="checkbox"
                        checked={
                            !!session.user?.offline_revocation_acknowledged
                        }
                        disabled={preferences.isPending}
                        onChange={(event) =>
                            preferences.mutate(
                                captureSessionIntent({
                                    offline_revocation_acknowledged:
                                        event.target.checked,
                                    offline_opt_in:
                                        event.target.checked &&
                                        !!session.user?.offline_opt_in,
                                } as never),
                            )
                        }
                    />
                    {tr("offline.ack")}
                </label>
                <label className="stack-form">
                    <input
                        type="checkbox"
                        checked={!!session.user?.offline_opt_in}
                        disabled={
                            preferences.isPending ||
                            !session.user?.offline_revocation_acknowledged
                        }
                        onChange={(event) =>
                            preferences.mutate(
                                captureSessionIntent({
                                    offline_opt_in: event.target.checked,
                                    offline_revocation_acknowledged: true,
                                } as never),
                            )
                        }
                    />
                    {tr("offline.enable")}
                </label>
                <DevicePicker
                    currentDeviceId={player.deviceId}
                    leaseToken={player.leaseToken}
                    onClaimed={player.applyLease}
                />
            </div>
            <div className="settings-theme">
                <h3>{tr("madd184f910")}</h3>
                <div className="filter-chips">
                    {(["light", "dark", "system"] as Theme[]).map((value) => (
                        <button
                            key={value}
                            className={theme.theme === value ? "selected" : ""}
                            aria-pressed={theme.theme === value}
                            disabled={changeTheme.isPending}
                            onClick={() => {
                                theme.setTheme(value);
                                changeTheme.mutate(captureSessionIntent(value));
                            }}
                        >
                            {value === "light"
                                ? tr("m7bc6e9e17e")
                                : value === "dark"
                                  ? tr("m96bdbd3e35")
                                  : tr("m28f13a01ab")}
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
                <h3>{tr("md9cbe1b5f0")}</h3>
                <label>
                    {tr("m6b87803653")}
                    <input
                        name="current_password"
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
                    {tr("m2fdd0ee685")}
                    <input
                        name="password"
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
                    <small>{tr("m4f6ac0d22a")}</small>
                </label>
                <label>
                    {tr("mafc7aa3dd9")}
                    <input
                        name="password_confirmation"
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
                    {tr("m1f2a4ae9b9")}
                </button>
            </form>
            {open && <MfaPanel />}
            <button
                className="button danger logout-button"
                disabled={logout.isPending}
                onClick={() =>
                    navigation.request({
                        proceed: () => logout.mutate(),
                        cancel: () => {},
                    })
                }
            >
                <LogOut size={17} />
                {tr("m3e6ad2c2f8")}
            </button>
        </Dialog>
    );
}
