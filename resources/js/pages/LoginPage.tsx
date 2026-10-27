import { useState } from "react";
import { useMutation } from "@tanstack/react-query";
import { Moon, ShieldCheck, Sun } from "lucide-react";
import { api, json } from "../lib/api";
import { useSession, useTheme } from "../context/AppContext";
import { Artwork, Brand, IconButton } from "../components/UI";
import type { User } from "../types";

export function LoginPage() {
    const session = useSession(),
        theme = useTheme();
    const [challenge, setChallenge] = useState(false),
        [recovery, setRecovery] = useState(false),
        [code, setCode] = useState("");
    const [email, setEmail] = useState(""),
        [password, setPassword] = useState("");
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
                            : { code }
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
                    <span className="eyebrow">
                        A LITTLE LESS NOISE. A LOT MORE YOU.
                    </span>
                    <h1>
                        صدای خودت
                        <br />
                        را پیدا کن.
                    </h1>
                    <p>
                        موسیقی‌هایی که دوست داری،
                        <br />
                        در یک جای کوچک و شخصی.
                    </p>
                </div>
                <Artwork seed={0} />
                <div className="login-art-footer">
                    <span>روی موج خودت.</span>
                    <span lang="en">AK / PRIVATE LISTENING</span>
                </div>
            </section>
            <section className="login-form-panel">
                <IconButton
                    className="login-theme"
                    label="تغییر تم"
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
                    <span className="eyebrow">خوش برگشتی</span>
                    <h2>
                        {challenge ? "یک قدم تا ورود" : "بیا یک آهنگ گوش کنیم."}
                    </h2>
                    <p>
                        {challenge
                            ? "کد رمزساز یا یکی از کدهای بازیابی را وارد کن. فرصت این مرحله ۱۰ دقیقه است."
                            : "برای ورود به کتابخانهٔ خصوصی، وارد حساب خودت شو."}
                    </p>
                    {!challenge && (
                        <>
                            <label>
                                ایمیل
                                <input
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
                                رمز عبور
                                <input
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
                                {recovery ? "کد بازیابی" : "کد رمزساز"}
                                <input
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
                                    ? "استفاده از رمزساز"
                                    : "استفاده از کد بازیابی"}
                            </button>
                        </>
                    )}
                    {login.error && (
                        <p role="alert" className="form-error">
                            {login.error.message}
                        </p>
                    )}
                    <button className="button" disabled={login.isPending}>
                        {login.isPending ? "در حال ورود…" : "ورود به خط‌کش"}
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
                            بازگشت به ورود با رمز
                        </button>
                    )}
                    <span className="login-private">
                        <ShieldCheck size={16} /> فقط برای اعضای دعوت‌شده
                    </span>
                    <small>
                        حساب و بازیابی دسترسی را مدیر کتابخانه انجام می‌دهد.
                    </small>
                </form>
                <p className="login-copyright" lang="en">
                    AHANG KHATKESH — MUSIC, IN GOOD MEASURE.
                </p>
            </section>
        </main>
    );
}
