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
    const [password, setPassword] = useState(""),
        [confirmation, setConfirmation] = useState(""),
        [pending, setPending] = useState(false),
        [error, setError] = useState("");
    const notify = useToast();
    return (
        <Dialog
            open
            onOpenChange={(value) => {
                if (!value && !pending) close();
            }}
            title={`بازنشانی رمز ${user.name}`}
            description="تمام نشست‌های قبلی عضو باطل می‌شوند. رمزساز فعال او حفظ می‌شود. رمز جدید را از مسیر امن به خودش بده."
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
                        notify("رمز بازنشانی و نشست‌های قبلی باطل شدند.");
                        close();
                    } catch (e) {
                        setError(
                            e instanceof Error ? e.message : "ثبت انجام نشد.",
                        );
                    } finally {
                        setPending(false);
                    }
                }}
            >
                <label>
                    رمز جدید عضو
                    <input
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
                    تکرار رمز جدید
                    <input
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
                    {pending
                        ? "در حال ثبت…"
                        : "بازنشانی رمز و خروج از دستگاه‌ها"}
                </button>
            </form>
        </Dialog>
    );
}
