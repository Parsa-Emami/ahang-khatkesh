import { Navigate, Route, Routes } from "react-router-dom";
import { useSession } from "./context/AppContext";
import { PlayerProvider } from "./context/PlayerContext";
import { Brand, ErrorState, Loading } from "./components/UI";
import { AppLayout } from "./layout/AppLayout";
import { LoginPage } from "./pages/LoginPage";
import {
    LibraryPage,
    LikedPage,
    PlaylistPage,
    SearchPage,
} from "./pages/LibraryPages";
import { AdminPage } from "./pages/AdminPage";

export function App() {
    const { user, pending, error, refresh } = useSession();
    if (pending)
        return (
            <div className="app-loading">
                <Brand />
                <Loading label="در حال تنظیم موج تو…" />
            </div>
        );
    if (error) return <ErrorState error={error} retry={refresh} />;
    if (!user) return <LoginPage />;
    return (
        <PlayerProvider key={user.id}>
            <a className="skip-link" href="#main-content">
                رفتن به محتوای اصلی
            </a>
            <Routes>
                <Route element={<AppLayout />}>
                    <Route index element={<LibraryPage />} />
                    <Route path="library" element={<LibraryPage saved />} />
                    <Route path="liked" element={<LikedPage />} />
                    <Route path="search" element={<SearchPage />} />
                    <Route path="playlists/:id" element={<PlaylistPage />} />
                    <Route
                        path="admin"
                        element={
                            user.is_admin ? (
                                <AdminPage />
                            ) : (
                                <Navigate to="/" replace />
                            )
                        }
                    />
                    <Route path="*" element={<Navigate to="/" replace />} />
                </Route>
            </Routes>
        </PlayerProvider>
    );
}
