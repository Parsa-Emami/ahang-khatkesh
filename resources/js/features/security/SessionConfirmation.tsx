import { localizeText } from "../../i18n/runtime";
import { asciiDigits } from "../../i18n/runtime";
import { tr } from "../../i18n/runtime";
import { useLocale } from "../../i18n/LocaleProvider";
import { useEffect, useRef, useState, type PropsWithChildren } from "react";
import { Dialog } from "../../components/UI";
import { api, json, setSessionConfirmation } from "../../lib/api";
import { useSession } from "../../context/AppContext";

export function SessionConfirmation({ children }: PropsWithChildren) {
    useLocale();
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
                title={tr("mceec555aed")}
                description={tr("m941e92096f")}
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
                                body: json({
                                    password,
                                    code: code ? asciiDigits(code) : null,
                                }),
                            });
                            finish(true);
                        } catch (e) {
                            setError(
                                e instanceof Error
                                    ? e.message
                                    : tr("mdd82db9f92"),
                            );
                            setPassword("");
                            setCode("");
                        } finally {
                            setPending(false);
                        }
                    }}
                >
                    <label>
                        {tr("m6b87803653")}
                        <input
                            name="password"
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
                            {tr("m91b332b9ef")}
                            <input
                                name="code"
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
                            <small>{tr("mc55bff396a")}</small>
                        </label>
                    )}
                    {error && (
                        <p className="form-error" role="alert">
                            {localizeText(error)}
                        </p>
                    )}
                    <button className="button" disabled={pending}>
                        {pending ? tr("mcb16adca9a") : tr("m36e8e73118")}
                    </button>
                    <button
                        className="button secondary"
                        type="button"
                        disabled={pending}
                        onClick={() => finish(false)}
                    >
                        {tr("m106dfb4e0f")}
                    </button>
                </form>
            </Dialog>
        </>
    );
}
