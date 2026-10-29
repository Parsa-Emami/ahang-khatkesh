import { PrivateListeningToggle } from "../features/listening/ListeningPages";
import { captureSessionIntent, type SessionIntent } from "../lib/api";
import { tr } from "../i18n/runtime";
import { useLocale } from "../i18n/LocaleProvider";
import { useEffect, useState, useRef } from "react";
import { useMutation, useQuery } from "@tanstack/react-query";
import {
    History,
    Sparkles,
    Bookmark,
    ChevronDown,
    Heart,
    House,
    Menu,
    Moon,
    Search,
    Settings2,
    Sun,
    Upload,
    X,
} from "lucide-react";
import {
    NavLink,
    Outlet,
    useLocation,
    useNavigate,
    useSearchParams,
} from "react-router-dom";
import { api, getCsrfToken, json } from "../lib/api";
import { useSession, useTheme, useToast } from "../context/AppContext";
import { Artwork, Brand, IconButton } from "../components/UI";
import { NowPlaying, PlayerBar } from "../components/Music";
import { SettingsDialog } from "../components/SettingsDialog";
import type { Page, Playlist, Theme, User } from "../types";

export function AppLayout() {
    const locale = useLocale();
    const menuButton = useRef<HTMLButtonElement>(null);
    const [mobile, setMobile] = useState(
        () => matchMedia("(max-width: 760px)").matches,
    );
    const session = useSession(),
        theme = useTheme(),
        notify = useToast(),
        navigate = useNavigate(),
        location = useLocation();
    const [params] = useSearchParams();
    const [search, setSearch] = useState(params.get("q") || ""),
        [menu, setMenu] = useState(false),
        [settings, setSettings] = useState(false);
    useEffect(() => {
        const query = matchMedia("(max-width: 760px)");
        const changed = () => setMobile(query.matches);
        query.addEventListener("change", changed);
        return () => query.removeEventListener("change", changed);
    }, []);
    useEffect(() => {
        if (!menu || !mobile) return;
        const sidebar = document.querySelector<HTMLElement>(".sidebar");
        sidebar?.querySelector<HTMLElement>("button, a")?.focus();
        const keyboard = (event: KeyboardEvent) => {
            if (event.key === "Escape") {
                event.preventDefault();
                setMenu(false);
            }
            if (event.key !== "Tab") return;
            const focusable = [
                ...(sidebar?.querySelectorAll<HTMLElement>("button, a[href]") ||
                    []),
            ].filter((node) => node.getClientRects().length > 0);
            const first = focusable[0],
                last = focusable.at(-1);
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last?.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first?.focus();
            }
        };
        window.addEventListener("keydown", keyboard);
        return () => {
            window.removeEventListener("keydown", keyboard);
            menuButton.current?.focus();
        };
    }, [menu, mobile]);
    useEffect(() => {
        const closePrivateDialogs = () => {
            setSettings(false);
            setMenu(false);
        };
        window.addEventListener("session-context-changed", closePrivateDialogs);
        window.addEventListener("session-expired", closePrivateDialogs);
        return () => {
            window.removeEventListener(
                "session-context-changed",
                closePrivateDialogs,
            );
            window.removeEventListener("session-expired", closePrivateDialogs);
        };
    }, []);
    const playlists = useQuery({
        queryKey: ["playlists", "sidebar"],
        queryFn: () => api<Page<Playlist>>("/playlists"),
    });
    useEffect(() => {
        if (session.user) {
            theme.setTheme(session.user.theme);
        }
    }, [session.user?.id, session.user?.theme]);
    useEffect(() => {
        if (session.user) locale.setPreferences(session.user);
    }, [
        session.user?.id,
        session.user?.locale,
        session.user?.direction,
        session.user?.motion,
        session.user?.time_zone,
    ]);
    useEffect(() => {
        setMenu(false);
    }, [location.pathname]);
    const saveTheme = useMutation({
        scope: { id: "interface-preferences" },
        mutationFn: (intent: SessionIntent<Theme>) =>
            api<{ data: User }>(
                "/me",
                {
                    method: "PATCH",
                    body: json({ theme: intent.value }),
                },
                true,
                true,
                intent,
            ),
        onSuccess: ({ data }) =>
            session.setSession({
                data: { user: data, csrf_token: getCsrfToken() },
            }),
        onError: (error) => notify(error.message, true),
    });
    return (
        <div className="app-shell">
            {menu && (
                <button
                    className="mobile-scrim"
                    aria-label={tr("m15537a8870")}
                    onClick={() => setMenu(false)}
                />
            )}
            <aside
                id="primary-navigation"
                inert={mobile && !menu}
                role={mobile && menu ? "dialog" : undefined}
                aria-modal={mobile && menu ? true : undefined}
                aria-label={tr("m6cade26874")}
                className={`sidebar ${menu ? "is-open" : ""}`}
            >
                <div className="sidebar-brand">
                    <Brand />
                    <IconButton
                        label={tr("mf6839701dc")}
                        className="mobile-only"
                        onClick={() => setMenu(false)}
                    >
                        <X size={19} />
                    </IconButton>
                </div>
                <nav className="main-nav" aria-label={tr("m6cade26874")}>
                    <NavLink to="/" end>
                        <House size={20} />
                        {tr("m1e0e4ac2e8")}
                    </NavLink>
                    <NavLink to="/search">
                        <Search size={20} />
                        {tr("mc711e53d4d")}
                    </NavLink>
                    <NavLink to="/library">
                        <Bookmark size={20} />
                        {tr("mbbe9bd4598")}
                    </NavLink>
                    <NavLink to="/liked">
                        <Heart size={20} />
                        {tr("m75f44e8bed")}
                    </NavLink>
                    <NavLink to="/history">
                        <History size={20} />
                        {tr("listening.history")}
                    </NavLink>
                    <NavLink to="/mix">
                        <Sparkles size={20} />
                        {tr("listening.mix")}
                    </NavLink>
                </nav>
                <div className="sidebar-divider" />
                <div className="sidebar-section-title">
                    <span>{tr("m123f7a4a31")}</span>
                    <span className="tiny-label">
                        {tr("editorial.collection")}
                    </span>
                </div>
                <nav
                    className="sidebar-playlists"
                    aria-label={tr("m810b71a913")}
                >
                    {playlists.data?.data.slice(0, 6).map((playlist) => (
                        <NavLink
                            to={`/playlists/${playlist.id}`}
                            key={playlist.id}
                        >
                            <Artwork
                                seed={playlist.artwork_seed}
                                url={playlist.cover_url}
                            />
                            <span dir="auto">
                                {playlist.name}
                                <small>
                                    {playlist.status === "draft"
                                        ? tr("m7d739ea1cc")
                                        : tr("ma906ac387c")}
                                </small>
                            </span>
                        </NavLink>
                    ))}
                    {!playlists.data?.data.length && <p>{tr("mafa5ad89db")}</p>}
                </nav>
                <div className="sidebar-bottom">
                    {session.user?.permissions.includes("admin.access") && (
                        <NavLink to="/admin" className="admin-link">
                            <Upload size={18} />
                            {tr("m637bbcc24d")}
                            <span
                                className="admin-label"
                                aria-hidden="true"
                                lang="en"
                            >
                                ADMIN
                            </span>
                        </NavLink>
                    )}
                    <button
                        className="user-button"
                        aria-label={tr("mb9c7f76721")}
                        onClick={() => setSettings(true)}
                    >
                        <span className="avatar">
                            {session.user?.name.charAt(0)}
                        </span>
                        <span>
                            {session.user?.name}
                            <small>
                                {session.user?.permissions.includes(
                                    "admin.access",
                                )
                                    ? tr("m8baa813d52")
                                    : tr("md603d77bf4")}
                            </small>
                        </span>
                        <ChevronDown size={15} />
                    </button>
                </div>
            </aside>
            <div className="workspace">
                <header className="app-header">
                    <IconButton
                        ref={menuButton}
                        aria-expanded={menu}
                        aria-controls="primary-navigation"
                        label={tr("m94b8c10e7e")}
                        className="mobile-only"
                        onClick={() => setMenu(true)}
                    >
                        <Menu size={22} />
                    </IconButton>
                    <span className="header-label">{tr("md7e5b3a0cc")}</span>
                    <form
                        className="global-search"
                        role="search"
                        onSubmit={(e) => {
                            e.preventDefault();
                            navigate(
                                `/search?q=${encodeURIComponent(search.trim())}`,
                            );
                        }}
                    >
                        <Search size={19} />
                        <input
                            dir="auto"
                            name="search"
                            aria-label={tr("meffa573678")}
                            placeholder={tr("m5514824801")}
                            maxLength={120}
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                        />
                        <kbd aria-hidden="true">↵</kbd>
                    </form>
                    <PrivateListeningToggle />
                    <div className="header-actions">
                        <IconButton
                            label={
                                theme.dark
                                    ? tr("m45d377d036")
                                    : tr("m6a9a4a677e")
                            }
                            disabled={saveTheme.isPending}
                            onClick={() => {
                                const value = theme.dark ? "light" : "dark";
                                theme.setTheme(value);
                                saveTheme.mutate(captureSessionIntent(value));
                            }}
                        >
                            {theme.dark ? (
                                <Sun size={20} />
                            ) : (
                                <Moon size={20} />
                            )}
                        </IconButton>
                        <IconButton
                            label={tr("mb9c7f76721")}
                            onClick={() => setSettings(true)}
                        >
                            <Settings2 size={20} />
                        </IconButton>
                    </div>
                </header>
                <div
                    className={`workspace-columns ${location.pathname === "/admin" ? "workspace-admin" : ""}`}
                >
                    <main
                        id="main-content"
                        className="main-content"
                        tabIndex={-1}
                    >
                        <Outlet />
                    </main>
                    {location.pathname !== "/admin" && <NowPlaying />}
                </div>
            </div>
            <PlayerBar />
            <SettingsDialog open={settings} onOpenChange={setSettings} />
        </div>
    );
}
