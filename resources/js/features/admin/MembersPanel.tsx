import { roleLabel } from "../../i18n/runtime";
import { tr } from "../../i18n/runtime";
import { useLocale } from "../../i18n/LocaleProvider";
import { useState, useRef } from "react";
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
    useLocale();
    const editingBaseline = useRef("");
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
            notify(tr("m3e3f04286b"));
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
            notify(tr("m70d7f79476"));
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
                        {tr("m4e10b32a82", {
                            v0: digits(users.data.data.length),
                            v1: digits(users.data.meta.capacity),
                        })}
                    </h3>
                    <p>{tr("ma49fa4bbf7")}</p>
                </div>
                <button
                    className="button"
                    disabled={
                        users.data.data.length >= users.data.meta.capacity
                    }
                    onClick={() => setOpen(true)}
                >
                    <Plus size={17} />
                    {tr("mb11a12361e")}
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
                                {user.id === session.user?.id
                                    ? tr("m50cd43d6bf")
                                    : ""}
                            </strong>
                            <small dir="ltr">{user.email}</small>
                        </span>
                        <span className="member-role">
                            {roleLabel(user.role)}
                            <small>
                                <ShieldCheck size={12} />
                                {user.mfa_enabled
                                    ? tr("m389ea22819")
                                    : tr("m8be5dae1c2")}
                            </small>
                        </span>
                        <span
                            className={`status-pill status-${user.is_active ? "completed" : "failed"}`}
                        >
                            {user.is_active
                                ? tr("m6f63796667")
                                : tr("md9ba416811")}
                        </span>
                        <IconButton
                            label={tr("mf1c6d211f3", { v0: user.name })}
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
                            label={tr("m4b89d01675", { v0: user.name })}
                            disabled={
                                session.user?.role !== "owner" &&
                                (user.role === "owner" ||
                                    user.role === "administrator")
                            }
                            onClick={() => {
                                editingBaseline.current = JSON.stringify(user);
                                setEditing({ ...user });
                            }}
                        >
                            <Pencil size={17} />
                        </IconButton>
                    </article>
                ))}
            </div>
            <p className="muted admin-footnote">{tr("m30255ee738")}</p>
            {resetting && (
                <MemberPasswordResetDialog
                    user={resetting}
                    close={() => setResetting(null)}
                />
            )}
            <Dialog
                open={open}
                dirty={JSON.stringify(form) !== JSON.stringify(blank)}
                onDiscard={() => setForm(blank)}
                onOpenChange={(v) => {
                    if (!create.isPending) {
                        setOpen(v);
                        if (!v) setForm(blank);
                    }
                }}
                title={tr("mc3a7381f3c")}
                description={tr("mab7573b7af")}
            >
                <form
                    className="stack-form"
                    onSubmit={(e) => {
                        e.preventDefault();
                        create.mutate();
                    }}
                >
                    <label>
                        {tr("m45dd06ba40")}
                        <input
                            dir="auto"
                            name="name"
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
                        {tr("mf1ad423d68")}
                        <input
                            name="email"
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
                        {tr("m2f3b54d1c2")}
                        <input
                            name="password"
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
                        <small>{tr("m067d65f84d")}</small>
                    </label>
                    <label>
                        {tr("m9129ed2aed")}
                        <select
                            dir="auto"
                            name="role"
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
                                    {roleLabel(role.value)}
                                </option>
                            ))}
                        </select>
                    </label>
                    <p className="muted">{tr("m23883187d8")}</p>
                    <button className="button" disabled={create.isPending}>
                        {create.isPending
                            ? tr("m661bac03a9")
                            : tr("ma2c1fc69c3")}
                    </button>
                </form>
            </Dialog>
            <Dialog
                open={!!editing}
                dirty={
                    !!editing &&
                    JSON.stringify(editing) !== editingBaseline.current
                }
                onDiscard={() => setEditing(null)}
                onOpenChange={(v) => {
                    if (!v && !update.isPending) setEditing(null);
                }}
                title={tr("m1331d9510d")}
                description={tr("m7b09d7788c")}
            >
                {editing && (
                    <form
                        className="stack-form"
                        onSubmit={(e) => {
                            e.preventDefault();
                            update.mutate();
                        }}
                    >
                        <strong dir="auto">{editing.name}</strong>
                        <label>
                            {tr("m9129ed2aed")}
                            <select
                                dir="auto"
                                name="role"
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
                                        {roleLabel(role.value)}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="checkbox-label">
                            <input
                                dir="auto"
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
                            {tr("md4b53472c8")}
                        </label>
                        {editing.id === session.user?.id && (
                            <p className="muted">{tr("m8db7b1fda3")}</p>
                        )}
                        <button className="button" disabled={update.isPending}>
                            {tr("m10bae4bd20")}
                        </button>
                    </form>
                )}
            </Dialog>
        </>
    );
}
