import { tr } from "../../i18n/runtime";
import { useLocale } from "../../i18n/LocaleProvider";
import { useState } from "react";
import { Dialog } from "../../components/UI";
import { api, json } from "../../lib/api";
import { useToast } from "../../context/AppContext";
import type { User } from "../../types";
export function MemberPasswordResetDialog({
    user,
    close,
}: {
    user: User;
    close: () => void;
}) {
    useLocale();
    const [password, setPassword] = useState(""),
        [confirmation, setConfirmation] = useState(""),
        [pending, setPending] = useState(false),
        [error, setError] = useState("");
    const notify = useToast();
    return (
        <Dialog
            dirty={!!(password || confirmation)}
            open
            onOpenChange={(value) => {
                if (!value && !pending) close();
            }}
            title={tr("mf1c6d211f3", { v0: user.name })}
            description={tr("m49338b1920")}
        >
            <form
                className="stack-form"
                onSubmit={async (event) => {
                    event.preventDefault();
                    setPending(true);
                    setError("");
                    try {
                        await api(`/admin/users/${user.id}/password`, {
                            method: "PUT",
                            body: json({
                                password,
                                password_confirmation: confirmation,
                            }),
                        });
                        setPassword("");
                        setConfirmation("");
                        notify(tr("mc7c6f590d5"));
                        close();
                    } catch (e) {
                        setError(
                            e instanceof Error ? e.message : tr("m1d3314ddc8"),
                        );
                    } finally {
                        setPending(false);
                    }
                }}
            >
                <label>
                    {tr("mca9e8b3c7c")}
                    <input
                        name="password"
                        type="password"
                        autoComplete="new-password"
                        dir="ltr"
                        required
                        minLength={12}
                        maxLength={128}
                        value={password}
                        onChange={(e) => setPassword(e.target.value)}
                    />
                </label>
                <label>
                    {tr("mafc7aa3dd9")}
                    <input
                        name="confirmation"
                        type="password"
                        autoComplete="new-password"
                        dir="ltr"
                        required
                        maxLength={128}
                        value={confirmation}
                        onChange={(e) => setConfirmation(e.target.value)}
                    />
                </label>
                {error && (
                    <p role="alert" className="form-error">
                        {error}
                    </p>
                )}
                <button className="button" disabled={pending}>
                    {pending ? tr("m03e4d57d5a") : tr("m5d6e25a8bf")}
                </button>
            </form>
        </Dialog>
    );
}
