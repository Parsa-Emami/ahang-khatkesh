import { useDraftGuard } from "../features/drafts/DraftNavigation";
import { PreferenceControls } from "../i18n/PreferenceControls";
import { asciiDigits } from "../i18n/runtime";
import { tr } from "../i18n/runtime";
import { useLocale } from "../i18n/LocaleProvider";
import { useState } from "react";
import { useMutation } from "@tanstack/react-query";
import { Moon, ShieldCheck, Sun } from "lucide-react";
import { api, json } from "../lib/api";
import { useSession, useTheme } from "../context/AppContext";
import { Artwork, Brand, IconButton } from "../components/UI";
import type { User } from "../types";

export function LoginPage() {
    useLocale();
    const session = useSession(),
        theme = useTheme();
    const [challenge, setChallenge] = useState(false),
        [recovery, setRecovery] = useState(false),
        [code, setCode] = useState("");
    const [email, setEmail] = useState(""),
        [password, setPassword] = useState("");
    useDraftGuard(!!(password || code), () => {
        setPassword("");
        setCode("");
    });
    const login = useMutation({
        mutationFn: () =>
            api<{
                data: {
                    user?: User;
                    two_factor_required?: boolean;
                    csrf_token: string;
                };
            }>(challenge ? "/login/challenge" : "/login", {
                method: "POST",
                body: json(
                    challenge
                        ? recovery
                            ? { recovery_code: code }
                            : { code: asciiDigits(code) }
                        : { email, password },
                ),
            }),
        onSuccess: (value) => {
            setPassword("");
            setCode("");
            if (value.data.two_factor_required) {
                setChallenge(true);
                return;
            }
            if (value.data.user)
                session.setSession({
                    data: {
                        user: value.data.user,
                        csrf_token: value.data.csrf_token,
                    },
                });
        },
    });
    return (
        <main className="login-page">
            <section className="login-art">
                <Brand />
                <div>
                    <span className="eyebrow">{tr("editorial.login")}</span>
                    <h1>
                        {tr("m3be76b5516")}
                        <br />
                        {tr("mb25d89b4df")}
                    </h1>
                    <p>
                        {tr("m3748958dc4")}
                        <br />
                        {tr("ma21bea4045")}
                    </p>
                </div>
                <Artwork seed={0} />
                <div className="login-art-footer">
                    <span>{tr("mb3a2330f7d")}</span>
                    <span>{tr("editorial.private")}</span>
                </div>
            </section>
            <section className="login-form-panel">
                <div className="login-preferences">
                    <PreferenceControls />
                </div>
                <IconButton
                    className="login-theme"
                    label={tr("mbdb0f33b8c")}
                    onClick={() =>
                        theme.setTheme(theme.dark ? "light" : "dark")
                    }
                >
                    {theme.dark ? <Sun size={21} /> : <Moon size={21} />}
                </IconButton>
                <form
                    className="login-form"
                    onSubmit={(event) => {
                        event.preventDefault();
                        login.mutate();
                    }}
                >
                    <span className="eyebrow">{tr("m696ef9ec3e")}</span>
                    <h2>{challenge ? tr("m382fc09e37") : tr("m138a2273e8")}</h2>
                    <p>{challenge ? tr("mb4d344a386") : tr("m427cf173c9")}</p>
                    {!challenge && (
                        <>
                            <label>
                                {tr("mf1ad423d68")}
                                <input
                                    name="email"
                                    type="email"
                                    dir="ltr"
                                    autoComplete="username"
                                    value={email}
                                    onChange={(e) => setEmail(e.target.value)}
                                    required
                                    maxLength={255}
                                    placeholder="you@example.com"
                                />
                            </label>
                            <label>
                                {tr("m9cf5bce9a4")}
                                <input
                                    name="password"
                                    type="password"
                                    dir="ltr"
                                    autoComplete="current-password"
                                    value={password}
                                    onChange={(e) =>
                                        setPassword(e.target.value)
                                    }
                                    required
                                    maxLength={128}
                                />
                            </label>
                        </>
                    )}
                    {challenge && (
                        <>
                            <label>
                                {recovery
                                    ? tr("m89ecb7b676")
                                    : tr("m91b332b9ef")}
                                <input
                                    name="code"
                                    type="text"
                                    autoComplete="one-time-code"
                                    inputMode={recovery ? "text" : "numeric"}
                                    dir="ltr"
                                    required
                                    maxLength={recovery ? 64 : 6}
                                    pattern={recovery ? undefined : "[0-9]{6}"}
                                    value={code}
                                    onChange={(e) => setCode(e.target.value)}
                                />
                            </label>
                            <button
                                type="button"
                                className="text-link"
                                onClick={() => {
                                    setRecovery(!recovery);
                                    setCode("");
                                    login.reset();
                                }}
                            >
                                {recovery
                                    ? tr("m2d65ff8f54")
                                    : tr("m71a4f897d9")}
                            </button>
                        </>
                    )}
                    {login.error && (
                        <p role="alert" className="form-error">
                            {login.error.message}
                        </p>
                    )}
                    <button className="button" disabled={login.isPending}>
                        {login.isPending
                            ? tr("m9ab9801b16")
                            : tr("m3df4ea534f")}
                        <span aria-hidden="true">←</span>
                    </button>
                    {challenge && (
                        <button
                            type="button"
                            className="text-link"
                            onClick={() => {
                                setChallenge(false);
                                setCode("");
                                login.reset();
                            }}
                        >
                            {tr("m9aa81b51a8")}
                        </button>
                    )}
                    <span className="login-private">
                        <ShieldCheck size={16} />
                        {tr("m9410c42241")}
                    </span>
                    <small>{tr("me73108c582")}</small>
                </form>
                <p className="login-copyright" lang="en">
                    AHANG KHATKESH — MUSIC, IN GOOD MEASURE.
                </p>
            </section>
        </main>
    );
}
