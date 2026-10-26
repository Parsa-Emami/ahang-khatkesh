import { useEffect, useState } from "react";
import { useMutation, useQuery } from "@tanstack/react-query";
import {
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
    const session = useSession(),
        theme = useTheme(),
        notify = useToast(),
        navigate = useNavigate(),
        location = useLocation();
    const [params] = useSearchParams();
    const [search, setSearch] = useState(params.get("q") || ""),
        [menu, setMenu] = useState(false),
        [settings, setSettings] = useState(false);
    const playlists = useQuery({
        queryKey: ["playlists", "sidebar"],
        queryFn: () => api<Page<Playlist>>("/playlists"),
    });
    useEffect(() => {
        if (session.user) theme.setTheme(session.user.theme);
    }, [session.user?.id, session.user?.theme]);
    useEffect(() => {
        setMenu(false);
    }, [location.pathname]);
    const saveTheme = useMutation({
        mutationFn: (value: Theme) =>
            api<{ data: User }>("/me", {
                method: "PATCH",
                body: json({ theme: value }),
            }),
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
                    aria-label="بستن فهرست"
                    onClick={() => setMenu(false)}
                />
            )}
            <aside className={`sidebar ${menu ? "is-open" : ""}`}>
                <div className="sidebar-brand">
                    <Brand />
                    <IconButton
                        label="بستن منو"
                        className="mobile-only"
                        onClick={() => setMenu(false)}
                    >
                        <X size={19} />
                    </IconButton>
                </div>
                <nav className="main-nav" aria-label="ناوبری اصلی">
                    <NavLink to="/" end>
                        <House size={20} />
                        خانه
                    </NavLink>
                    <NavLink to="/search">
                        <Search size={20} />
                        کشف و جستجو
                    </NavLink>
                    <NavLink to="/library">
                        <Bookmark size={20} />
                        کتابخانهٔ من
                    </NavLink>
                    <NavLink to="/liked">
                        <Heart size={20} />
                        پسندیده‌ها
                    </NavLink>
                </nav>
                <div className="sidebar-divider" />
                <div className="sidebar-section-title">
                    <span>پلی‌لیست‌های شما</span>
                    <span className="tiny-label">COLLECTION</span>
                </div>
                <nav className="sidebar-playlists" aria-label="پلی‌لیست‌ها">
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
                                        ? "پیش‌نویس"
                                        : "پلی‌لیست اختصاصی"}
                                </small>
                            </span>
                        </NavLink>
                    ))}
                    {!playlists.data?.data.length && (
                        <p>اولین پلی‌لیست، شروع یک حال تازه است.</p>
                    )}
                </nav>
                <div className="sidebar-bottom">
                    {session.user?.is_admin && (
                        <NavLink to="/admin" className="admin-link">
                            <Upload size={18} />
                            مدیریت کتابخانه
                            <span className="admin-label">ADMIN</span>
                        </NavLink>
                    )}
                    <button
                        className="user-button"
                        onClick={() => setSettings(true)}
                    >
                        <span className="avatar">
                            {session.user?.name.charAt(0)}
                        </span>
                        <span>
                            {session.user?.name}
                            <small>
                                {session.user?.is_admin
                                    ? "مدیر کتابخانه"
                                    : "عضو کتابخانه"}
                            </small>
                        </span>
                        <ChevronDown size={15} />
                    </button>
                </div>
            </aside>
            <div className="workspace">
                <header className="app-header">
                    <IconButton
                        label="باز کردن منو"
                        className="mobile-only"
                        onClick={() => setMenu(true)}
                    >
                        <Menu size={22} />
                    </IconButton>
                    <span className="header-label">روی موجِ خودت.</span>
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
                            aria-label="جستجوی آهنگ، هنرمند یا پلی‌لیست"
                            placeholder="آهنگ، هنرمند یا پلی‌لیست…"
                            maxLength={120}
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                        />
                        <kbd aria-hidden="true">↵</kbd>
                    </form>
                    <div className="header-actions">
                        <IconButton
                            label={
                                theme.dark
                                    ? "فعال کردن حالت روشن"
                                    : "فعال کردن حالت تیره"
                            }
                            disabled={saveTheme.isPending}
                            onClick={() => {
                                const value = theme.dark ? "light" : "dark";
                                theme.setTheme(value);
                                saveTheme.mutate(value);
                            }}
                        >
                            {theme.dark ? (
                                <Sun size={20} />
                            ) : (
                                <Moon size={20} />
                            )}
                        </IconButton>
                        <IconButton
                            label="تنظیمات حساب"
                            onClick={() => setSettings(true)}
                        >
                            <Settings2 size={20} />
                        </IconButton>
                    </div>
                </header>
                <div className="workspace-columns">
                    <main
                        id="main-content"
                        className="main-content"
                        tabIndex={-1}
                    >
                        <Outlet />
                    </main>
                    <NowPlaying />
                </div>
            </div>
            <PlayerBar />
            <SettingsDialog open={settings} onOpenChange={setSettings} />
        </div>
    );
}
