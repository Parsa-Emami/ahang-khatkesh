import "../../../css/security.css";
import { useState } from "react";
import { ShieldCheck, KeyRound } from "lucide-react";
import { api, getCsrfToken, json } from "../../lib/api";
import { useSession } from "../../context/AppContext";
import type { User } from "../../types";

type Setup = { setup_key: string; qr_svg: string };
export function MfaPanel({ onReady }: { onReady?: () => void } = {}) {
    const session = useSession();
    const [setup, setSetup] = useState<Setup | null>(null);
    const [codes, setCodes] = useState<string[] | null>(null);
    const [code, setCode] = useState("");
    const [pending, setPending] = useState(false);
    const [error, setError] = useState("");
    const [rotation, setRotation] = useState(false);
    async function run(task: () => Promise<void>) {
        setPending(true);
        setError("");
        try {
            await task();
        } catch (e) {
            setError(e instanceof Error ? e.message : "عملیات انجام نشد.");
        } finally {
            setPending(false);
        }
    }
    return (
        <section className="security-panel stack-form">
            <div className="panel-heading">
                <ShieldCheck size={27} />
                <div>
                    <h2>امنیت حساب</h2>
                    <p className="muted">
                        {session.user?.mfa_enabled
                            ? "تأیید دومرحله‌ای فعال است."
                            : "برای دسترسی مدیریتی، رمزساز را فعال کن."}
                    </p>
                </div>
            </div>
            {!session.user?.mfa_enabled && !setup && (
                <>
                    <p className="muted">
                        یک برنامهٔ سازگار با TOTP مثل رمزساز گوشی لازم داری.
                        اتصال به سرویس خارجی انجام نمی‌شود. کلید و کدهای بازیابی
                        را در جای امن نگه دار.
                    </p>
                    <button
                        className="button"
                        disabled={pending}
                        onClick={() =>
                            void run(async () => {
                                const value = await api<{ data: Setup }>(
                                    "/me/mfa",
                                    { method: "POST" },
                                );
                                setSetup(value.data);
                            })
                        }
                    >
                        <KeyRound size={17} />
                        شروع فعال‌سازی رمزساز
                    </button>
                </>
            )}
            {setup && (
                <>
                    <p>
                        ۱. QR را با رمزساز اسکن کن، یا کلید زیر را دستی وارد کن.
                    </p>
                    <img
                        className="mfa-qr"
                        alt="کد QR راه‌اندازی رمزساز؛ کلید متنی در ادامه آمده است"
                        src={`data:image/svg+xml;charset=utf-8,${encodeURIComponent(setup.qr_svg)}`}
                    />
                    <code className="secret-key" dir="ltr">
                        {setup.setup_key}
                    </code>
                    <form
                        className="stack-form"
                        onSubmit={(e) => {
                            e.preventDefault();
                            void run(async () => {
                                const value = await api<{
                                    data: {
                                        user: User;
                                        recovery_codes: string[];
                                    };
                                }>("/me/mfa/confirm", {
                                    method: "POST",
                                    body: json({ code }),
                                });
                                setSetup(null);
                                setCode("");
                                setCodes(value.data.recovery_codes);
                                session.setSession({
                                    data: {
                                        user: value.data.user,
                                        csrf_token: getCsrfToken(),
                                    },
                                });
                            });
                        }}
                    >
                        <label>
                            ۲. کد شش‌رقمی رمزساز
                            <input
                                type="text"
                                autoComplete="one-time-code"
                                inputMode="numeric"
                                pattern="[0-9]{6}"
                                maxLength={6}
                                dir="ltr"
                                required
                                value={code}
                                onChange={(e) => setCode(e.target.value)}
                            />
                        </label>
                        <button className="button" disabled={pending}>
                            {pending ? "در حال بررسی…" : "تأیید و فعال‌سازی"}
                        </button>
                    </form>
                </>
            )}
            {codes && (
                <div className="recovery-box" role="status">
                    <h3>کدهای بازیابی را ذخیره کن</h3>
                    <p>
                        هر کد یک بار قابل استفاده است. این کدها را همراه رمز
                        عبور نگه ندار؛ با بستن این بخش از صفحه حذف می‌شوند.
                    </p>
                    <div className="recovery-codes" dir="ltr">
                        {codes.map((value) => (
                            <code key={value}>{value}</code>
                        ))}
                    </div>
                    <button
                        className="button"
                        onClick={() => {
                            setCodes(null);
                            onReady?.();
                        }}
                    >
                        ذخیره کردم؛ بستن کدها
                    </button>
                </div>
            )}
            {session.user?.mfa_enabled && !codes && (
                <div className="form-actions">
                    <button
                        className="button secondary"
                        disabled={pending}
                        onClick={() =>
                            void run(async () => {
                                const value = await api<{
                                    data: { recovery_codes: string[] };
                                }>("/me/mfa/recovery-codes");
                                setCodes(value.data.recovery_codes);
                            })
                        }
                    >
                        نمایش کدهای بازیابی
                    </button>
                    <button
                        className="text-link"
                        onClick={() => setRotation(true)}
                    >
                        جایگزینی کدهای بازیابی
                    </button>
                </div>
            )}
            {rotation && (
                <div className="recovery-box">
                    <p>با ساخت کدهای تازه، تمام کدهای قبلی نامعتبر می‌شوند.</p>
                    <div className="form-actions">
                        <button
                            className="button"
                            disabled={pending}
                            onClick={() =>
                                void run(async () => {
                                    const value = await api<{
                                        data: { recovery_codes: string[] };
                                    }>("/me/mfa/recovery-codes", {
                                        method: "POST",
                                    });
                                    setCodes(value.data.recovery_codes);
                                    setRotation(false);
                                })
                            }
                        >
                            ساخت و نمایش کدهای تازه
                        </button>
                        <button
                            className="button secondary"
                            onClick={() => setRotation(false)}
                        >
                            انصراف
                        </button>
                    </div>
                </div>
            )}
            {error && (
                <p className="form-error" role="alert">
                    {error}
                </p>
            )}
        </section>
    );
}
