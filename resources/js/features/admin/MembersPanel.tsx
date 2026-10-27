import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Plus, Users, Pencil, ShieldCheck, KeyRound } from "lucide-react";
import { api, json } from "../../lib/api";
import { useSession, useToast } from "../../context/AppContext";
import { digits, type User, type Role } from "../../types";
import { Dialog, ErrorState, Loading, IconButton } from "../../components/UI";
import { MemberPasswordResetDialog } from "./MemberPasswordResetDialog";
import { adminKeys } from "./api";
type Form = { name: string; email: string; password: string; role: Role };
const blank: Form = { name: "", email: "", password: "", role: "listener" };
export function MembersPanel() {
    const [resetting, setResetting] = useState<User | null>(null);
    const session = useSession(),
        client = useQueryClient(),
        notify = useToast();
    const [open, setOpen] = useState(false),
        [form, setForm] = useState<Form>(blank),
        [editing, setEditing] = useState<User | null>(null);
    const users = useQuery({
        queryKey: ["admin", "users"],
        queryFn: () =>
            api<{
                data: User[];
                meta: {
                    capacity: number;
                    assignable_roles: { value: Role; label: string }[];
                };
            }>("/admin/users"),
    });
    const invalidate = () => {
        void client.invalidateQueries({ queryKey: ["admin", "users"] });
        void client.invalidateQueries({ queryKey: adminKeys.overview });
    };
    const create = useMutation({
        mutationFn: () =>
            api("/admin/users", { method: "POST", body: json(form) }),
        onSuccess: () => {
            setOpen(false);
            setForm(blank);
            invalidate();
            notify("حساب ساخته شد. اطلاعات ورود را از مسیر امن به عضو بده.");
        },
        onError: (e) => notify(e.message, true),
    });
    const update = useMutation({
        mutationFn: () =>
            api(`/admin/users/${editing!.id}`, {
                method: "PATCH",
                body: json({
                    role: editing!.role,
                    is_active: editing!.is_active,
                }),
            }),
        onSuccess: () => {
            if (editing?.id === session.user?.id)
                window.dispatchEvent(new Event("session-expired"));
            setEditing(null);
            invalidate();
            notify("دسترسی ثبت و نشست‌های قبلی این عضو باطل شد.");
        },
        onError: (e) => notify(e.message, true),
    });
    if (users.isPending) return <Loading />;
    if (users.error)
        return (
            <ErrorState
                error={users.error}
                retry={() => void users.refetch()}
            />
        );
    const roles = users.data.meta.assignable_roles;
    return (
        <>
            <div className="members-banner">
                <Users size={29} />
                <div>
                    <h3>
                        {digits(users.data.data.length)} نفر از{" "}
                        {digits(users.data.meta.capacity)} نفر
                    </h3>
                    <p>سقف ظرفیت شامل مدیرها و حساب‌های غیرفعال است.</p>
                </div>
                <button
                    className="button"
                    disabled={
                        users.data.data.length >= users.data.meta.capacity
                    }
                    onClick={() => setOpen(true)}
                >
                    <Plus size={17} />
                    عضو تازه
                </button>
            </div>
            <div className="members-list">
                {users.data.data.map((user) => (
                    <article className="member-row" key={user.id}>
                        <span className="avatar" aria-hidden="true">
                            {user.name.charAt(0)}
                        </span>
                        <span>
                            <strong>
                                {user.name}
                                {user.id === session.user?.id ? " · شما" : ""}
                            </strong>
                            <small dir="ltr">{user.email}</small>
                        </span>
                        <span className="member-role">
                            {user.role_label}
                            <small>
                                <ShieldCheck size={12} />
                                {user.mfa_enabled
                                    ? "رمزساز فعال"
                                    : "بدون رمزساز"}
                            </small>
                        </span>
                        <span
                            className={`status-pill status-${user.is_active ? "completed" : "failed"}`}
                        >
                            {user.is_active ? "فعال" : "غیرفعال"}
                        </span>
                        <IconButton
                            label={`بازنشانی رمز ${user.name}`}
                            disabled={
                                user.id === session.user?.id ||
                                (session.user?.role !== "owner" &&
                                    (user.role === "owner" ||
                                        user.role === "administrator"))
                            }
                            onClick={() => setResetting(user)}
                        >
                            <KeyRound size={17} />
                        </IconButton>
                        <IconButton
                            label={`ویرایش دسترسی ${user.name}`}
                            disabled={
                                session.user?.role !== "owner" &&
                                (user.role === "owner" ||
                                    user.role === "administrator")
                            }
                            onClick={() => setEditing({ ...user })}
                        >
                            <Pencil size={17} />
                        </IconButton>
                    </article>
                ))}
            </div>
            <p className="muted admin-footnote">
                مالک نقش‌های مدیریتی را تعیین می‌کند. مدیر، اعضا و مدیران محتوا
                را مدیریت می‌کند. آخرین مالک فعال قابل تنزل یا غیرفعال‌سازی
                نیست.
            </p>
            {resetting && (
                <MemberPasswordResetDialog
                    user={resetting}
                    close={() => setResetting(null)}
                />
            )}
            <Dialog
                open={open}
                onOpenChange={(v) => {
                    if (!create.isPending) {
                        setOpen(v);
                        if (!v) setForm(blank);
                    }
                }}
                title="یک عضو تازه"
                description="حساب خصوصی با نقش مشخص بساز. ثبت‌نام عمومی بسته است."
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
                            autoComplete="off"
                            value={form.name}
                            onChange={(e) =>
                                setForm({ ...form, name: e.target.value })
                            }
                        />
                    </label>
                    <label>
                        ایمیل
                        <input
                            type="email"
                            dir="ltr"
                            required
                            maxLength={255}
                            autoComplete="off"
                            value={form.email}
                            onChange={(e) =>
                                setForm({ ...form, email: e.target.value })
                            }
                        />
                    </label>
                    <label>
                        رمز اولیه
                        <input
                            type="password"
                            dir="ltr"
                            required
                            minLength={12}
                            maxLength={128}
                            autoComplete="new-password"
                            value={form.password}
                            onChange={(e) =>
                                setForm({ ...form, password: e.target.value })
                            }
                        />
                        <small>
                            حداقل ۱۲ نویسه شامل حرف و عدد؛ از رمزهای تکراری
                            استفاده نکن.
                        </small>
                    </label>
                    <label>
                        نقش
                        <select
                            value={form.role}
                            onChange={(e) =>
                                setForm({
                                    ...form,
                                    role: e.target.value as Role,
                                })
                            }
                        >
                            {roles.map((role) => (
                                <option key={role.value} value={role.value}>
                                    {role.label}
                                </option>
                            ))}
                        </select>
                    </label>
                    <p className="muted">
                        نقش مدیریتی تا فعال‌کردن رمزساز به پنل دسترسی ندارد.
                    </p>
                    <button className="button" disabled={create.isPending}>
                        {create.isPending ? "در حال ساخت…" : "ساخت حساب"}
                    </button>
                </form>
            </Dialog>
            <Dialog
                open={!!editing}
                onOpenChange={(v) => {
                    if (!v && !update.isPending) setEditing(null);
                }}
                title="نقش و وضعیت حساب"
                description="تغییر نقش یا وضعیت، همهٔ نشست‌های قبلی این عضو را باطل می‌کند."
            >
                {editing && (
                    <form
                        className="stack-form"
                        onSubmit={(e) => {
                            e.preventDefault();
                            update.mutate();
                        }}
                    >
                        <strong>{editing.name}</strong>
                        <label>
                            نقش
                            <select
                                value={editing.role}
                                onChange={(e) =>
                                    setEditing({
                                        ...editing,
                                        role: e.target.value as Role,
                                    })
                                }
                            >
                                {roles.map((role) => (
                                    <option key={role.value} value={role.value}>
                                        {role.label}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="checkbox-label">
                            <input
                                type="checkbox"
                                checked={editing.is_active}
                                disabled={editing.id === session.user?.id}
                                onChange={(e) =>
                                    setEditing({
                                        ...editing,
                                        is_active: e.target.checked,
                                    })
                                }
                            />
                            حساب فعال باشد
                        </label>
                        {editing.id === session.user?.id && (
                            <p className="muted">
                                با تغییر نقش خودت، از حساب خارج می‌شوی.
                            </p>
                        )}
                        <button className="button" disabled={update.isPending}>
                            ثبت دسترسی
                        </button>
                    </form>
                )}
            </Dialog>
        </>
    );
}
