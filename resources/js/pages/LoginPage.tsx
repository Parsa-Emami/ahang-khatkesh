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
    const [email, setEmail] = useState(""),
        [password, setPassword] = useState("");
    const login = useMutation({
        mutationFn: () =>
            api<{ data: { user: User; csrf_token: string } }>("/login", {
                method: "POST",
                body: json({ email, password }),
            }),
        onSuccess: (value) => {
            setPassword("");
            session.setSession(value);
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
                    <h2>بیا یک آهنگ گوش کنیم.</h2>
                    <p>برای ورود به کتابخانهٔ خصوصی، وارد حساب خودت شو.</p>
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
                            onChange={(e) => setPassword(e.target.value)}
                            required
                            maxLength={128}
                        />
                    </label>
                    {login.error && (
                        <p role="alert" className="form-error">
                            {login.error.message}
                        </p>
                    )}
                    <button className="button" disabled={login.isPending}>
                        {login.isPending ? "در حال ورود…" : "ورود به خط‌کش"}
                        <span aria-hidden="true">←</span>
                    </button>
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
