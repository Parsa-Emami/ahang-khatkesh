import { useEffect, useRef, useState, type PropsWithChildren } from "react";
import { Dialog } from "../../components/UI";
import { api, json, setSessionConfirmation } from "../../lib/api";
import { useSession } from "../../context/AppContext";

export function SessionConfirmation({ children }: PropsWithChildren) {
    const { user } = useSession();
    const [open, setOpen] = useState(false);
    const [password, setPassword] = useState("");
    const [code, setCode] = useState("");
    const [pending, setPending] = useState(false);
    const [error, setError] = useState("");
    const waiting = useRef<Array<(valid: boolean) => void>>([]);
    function finish(valid: boolean) {
        waiting.current.splice(0).forEach((resolve) => resolve(valid));
        setOpen(false);
        setPassword("");
        setCode("");
        setError("");
    }
    useEffect(() => {
        setSessionConfirmation(
            () =>
                new Promise<boolean>((resolve) => {
                    waiting.current.push(resolve);
                    setOpen(true);
                }),
        );
        return () => {
            setSessionConfirmation(null);
            waiting.current.splice(0).forEach((resolve) => resolve(false));
        };
    }, []);
    return (
        <>
            {children}
            <Dialog
                priority
                open={open}
                onOpenChange={(value) => {
                    if (!value && !pending) finish(false);
                }}
                title="تأیید دوبارهٔ هویت"
                description="برای ثبت این تغییر، رمز فعلی و در صورت فعال بودن، کد تازهٔ برنامهٔ رمزساز را وارد کن."
            >
                <form
                    className="stack-form"
                    onSubmit={async (e) => {
                        e.preventDefault();
                        setPending(true);
                        setError("");
                        try {
                            await api("/me/password/confirm", {
                                method: "POST",
                                body: json({ password, code: code || null }),
                            });
                            finish(true);
                        } catch (e) {
                            setError(
                                e instanceof Error
                                    ? e.message
                                    : "تأیید انجام نشد.",
                            );
                            setPassword("");
                            setCode("");
                        } finally {
                            setPending(false);
                        }
                    }}
                >
                    <label>
                        رمز فعلی
                        <input
                            type="password"
                            autoComplete="current-password"
                            dir="ltr"
                            required
                            maxLength={128}
                            value={password}
                            onChange={(e) => setPassword(e.target.value)}
                        />
                    </label>
                    {user?.mfa_enabled && (
                        <label>
                            کد رمزساز
                            <input
                                type="text"
                                inputMode="numeric"
                                autoComplete="one-time-code"
                                dir="ltr"
                                pattern="[0-9]{6}"
                                maxLength={6}
                                required
                                value={code}
                                onChange={(e) => setCode(e.target.value)}
                            />
                            <small>
                                کدی که قبلاً استفاده شده پذیرفته نمی‌شود؛ از کد
                                تازه استفاده کن.
                            </small>
                        </label>
                    )}
                    {error && (
                        <p className="form-error" role="alert">
                            {error}
                        </p>
                    )}
                    <button className="button" disabled={pending}>
                        {pending ? "در حال بررسی…" : "تأیید و ادامه"}
                    </button>
                    <button
                        className="button secondary"
                        type="button"
                        disabled={pending}
                        onClick={() => finish(false)}
                    >
                        انصراف
                    </button>
                </form>
            </Dialog>
        </>
    );
}
