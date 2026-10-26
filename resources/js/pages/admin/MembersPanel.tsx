import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Plus, Users } from "lucide-react";
import { api, json } from "../../lib/api";
import { useSession, useToast } from "../../context/AppContext";
import { digits, type User } from "../../types";
import { Dialog, ErrorState, Loading } from "../../components/UI";

export function MembersPanel() {
    const session = useSession(),
        client = useQueryClient(),
        notify = useToast();
    const [open, setOpen] = useState(false),
        [form, setForm] = useState({
            name: "",
            email: "",
            password: "",
            is_admin: false,
        });
    const users = useQuery({
        queryKey: ["users"],
        queryFn: () =>
            api<{ data: User[]; meta: { capacity: number } }>("/admin/users"),
    });
    const create = useMutation({
        mutationFn: () =>
            api("/admin/users", { method: "POST", body: json(form) }),
        onSuccess: () => {
            setOpen(false);
            setForm({ name: "", email: "", password: "", is_admin: false });
            void client.invalidateQueries({ queryKey: ["users"] });
            notify("حساب عضو ساخته شد.");
        },
        onError: (error) => notify(error.message, true),
    });
    const toggle = useMutation({
        mutationFn: (user: User) =>
            api(`/admin/users/${user.id}`, {
                method: "PATCH",
                body: json({ is_active: !user.is_active }),
            }),
        onSuccess: () => void client.invalidateQueries({ queryKey: ["users"] }),
        onError: (error) => notify(error.message, true),
    });
    if (users.isPending) return <Loading />;
    if (users.error)
        return (
            <ErrorState
                error={users.error}
                retry={() => void users.refetch()}
            />
        );
    return (
        <>
            <div className="members-banner">
                <Users size={29} />
                <div>
                    <h2>
                        {digits(users.data.data.length)} نفر از{" "}
                        {digits(users.data.meta.capacity)} نفر
                    </h2>
                    <p>ظرفیت شامل مدیرها و حساب‌های غیرفعال هم می‌شود.</p>
                </div>
                <button
                    className="button"
                    disabled={
                        users.data.data.length >= users.data.meta.capacity
                    }
                    onClick={() => setOpen(true)}
                >
                    <Plus size={17} />
                    عضو جدید
                </button>
            </div>
            <div className="members-list">
                {users.data.data.map((user) => (
                    <div className="member-row" key={user.id}>
                        <span className="avatar">{user.name.charAt(0)}</span>
                        <span>
                            <strong>{user.name}</strong>
                            <small dir="ltr">{user.email}</small>
                        </span>
                        <span className="member-role">
                            {user.is_admin ? "مدیر" : "عضو"}
                        </span>
                        <button
                            className={`status-pill ${user.is_active ? "status-completed" : "status-failed"}`}
                            disabled={
                                toggle.isPending || user.id === session.user?.id
                            }
                            onClick={() => toggle.mutate(user)}
                            aria-label={`${user.is_active ? "غیرفعال کردن" : "فعال کردن"} ${user.name}`}
                        >
                            {user.is_active ? "فعال" : "غیرفعال"}
                        </button>
                    </div>
                ))}
            </div>
            <Dialog
                open={open}
                onOpenChange={(value) => {
                    if (!create.isPending) {
                        setOpen(value);
                        if (!value)
                            setForm({
                                name: "",
                                email: "",
                                password: "",
                                is_admin: false,
                            });
                    }
                }}
                title="یک عضو تازه"
                description="دسترسی این کتابخانه با دعوت و ساخت حساب توسط مدیر است."
            >
                <form
                    className="stack-form"
                    onSubmit={(e) => {
                        e.preventDefault();
                        create.mutate();
                    }}
                >
                    <label>
                        نام
                        <input
                            required
                            maxLength={100}
                            value={form.name}
                            onChange={(e) =>
                                setForm((s) => ({ ...s, name: e.target.value }))
                            }
                        />
                    </label>
                    <label>
                        ایمیل
                        <input
                            required
                            type="email"
                            dir="ltr"
                            maxLength={255}
                            autoComplete="off"
                            value={form.email}
                            onChange={(e) =>
                                setForm((s) => ({
                                    ...s,
                                    email: e.target.value,
                                }))
                            }
                        />
                    </label>
                    <label>
                        رمز عبور اولیه
                        <input
                            required
                            type="password"
                            dir="ltr"
                            minLength={12}
                            maxLength={128}
                            autoComplete="new-password"
                            value={form.password}
                            onChange={(e) =>
                                setForm((s) => ({
                                    ...s,
                                    password: e.target.value,
                                }))
                            }
                        />
                        <small>
                            حداقل ۱۲ نویسه شامل حرف و عدد؛ عضو می‌تواند آن را از
                            تنظیمات عوض کند.
                        </small>
                    </label>
                    <label className="checkbox-label">
                        <input
                            type="checkbox"
                            checked={form.is_admin}
                            onChange={(e) =>
                                setForm((s) => ({
                                    ...s,
                                    is_admin: e.target.checked,
                                }))
                            }
                        />
                        دسترسی مدیر کتابخانه
                    </label>
                    <button className="button" disabled={create.isPending}>
                        {create.isPending ? "در حال ساخت…" : "ساخت حساب"}
                    </button>
                </form>
            </Dialog>
        </>
    );
}
