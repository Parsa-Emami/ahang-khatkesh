import { useDraftGuard } from "../drafts/DraftNavigation";
import { asciiDigits } from "../../i18n/runtime";
import { tr } from "../../i18n/runtime";
import { useLocale } from "../../i18n/LocaleProvider";
import "../../../css/security.css";
import { useState } from "react";
import { ShieldCheck, KeyRound } from "lucide-react";
import { api, getCsrfToken, json } from "../../lib/api";
import { useSession } from "../../context/AppContext";
import type { User } from "../../types";

type Setup = { setup_key: string; qr_svg: string };
export function MfaPanel({ onReady }: { onReady?: () => void } = {}) {
    useLocale();
    const session = useSession();
    const [setup, setSetup] = useState<Setup | null>(null);
    const [codes, setCodes] = useState<string[] | null>(null);
    const [code, setCode] = useState("");
    const [pending, setPending] = useState(false);
    const [error, setError] = useState("");
    const [rotation, setRotation] = useState(false);
    useDraftGuard(!!(setup || codes || code), () => {
        setSetup(null);
        setCodes(null);
        setCode("");
        setRotation(false);
    });
    async function run(task: () => Promise<void>) {
        setPending(true);
        setError("");
        try {
            await task();
        } catch (e) {
            setError(e instanceof Error ? e.message : tr("m68a6e1d0cd"));
        } finally {
            setPending(false);
        }
    }
    return (
        <section className="security-panel stack-form">
            <div className="panel-heading">
                <ShieldCheck size={27} />
                <div>
                    <h2>{tr("m34a02d2297")}</h2>
                    <p className="muted">
                        {session.user?.mfa_enabled
                            ? tr("m9420648e05")
                            : tr("mcca2602353")}
                    </p>
                </div>
            </div>
            {!session.user?.mfa_enabled && !setup && (
                <>
                    <p className="muted">{tr("m414bc2f2fa")}</p>
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
                        {tr("md775168987")}
                    </button>
                </>
            )}
            {setup && (
                <>
                    <p>{tr("m00cfee7c22")}</p>
                    <img
                        className="mfa-qr"
                        alt={tr("m66125ed374")}
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
                                    body: json({ code: asciiDigits(code) }),
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
                            {tr("me73147e9c6")}
                            <input
                                name="code"
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
                            {pending ? tr("mcb16adca9a") : tr("mb777524491")}
                        </button>
                    </form>
                </>
            )}
            {codes && (
                <div className="recovery-box" role="status">
                    <h3>{tr("m22c2f3d982")}</h3>
                    <p>{tr("m4983470294")}</p>
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
                        {tr("mc6c7282184")}
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
                        {tr("m383871cfa7")}
                    </button>
                    <button
                        className="text-link"
                        onClick={() => setRotation(true)}
                    >
                        {tr("m6b6a0268d0")}
                    </button>
                </div>
            )}
            {rotation && (
                <div className="recovery-box">
                    <p>{tr("mfd55d3347c")}</p>
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
                            {tr("mf165c4ff0b")}
                        </button>
                        <button
                            className="button secondary"
                            onClick={() => setRotation(false)}
                        >
                            {tr("m106dfb4e0f")}
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
