import "../css/catalog-workflows.css";
import { FieldValidation } from "./i18n/FieldValidation";
import { createRoot } from "react-dom/client";
import { createBrowserRouter, RouterProvider } from "react-router-dom";
import { LocaleProvider, applyPreferences } from "./i18n/LocaleProvider";
import { readPreferences } from "./i18n/locales";
import { DraftNavigationProvider } from "./features/drafts/DraftNavigation";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import "@fontsource/vazirmatn/400.css";
import "@fontsource/vazirmatn/500.css";
import "@fontsource/vazirmatn/600.css";
import "@fontsource/vazirmatn/700.css";
import "@fontsource-variable/manrope";
import "../css/app.css";
import { App } from "./App";
import { ErrorBoundary } from "./components/ErrorBoundary";
import {
    SessionProvider,
    ThemeProvider,
    ToastProvider,
} from "./context/AppContext";
applyPreferences(readPreferences());
try {
    const theme = localStorage.getItem("ak-theme");
    document.documentElement.dataset.theme =
        theme === "light" || theme === "dark"
            ? theme
            : matchMedia("(prefers-color-scheme: dark)").matches
              ? "dark"
              : "light";
} catch {}
const client = new QueryClient({
    defaultOptions: {
        queries: { staleTime: 30000, retry: 1, refetchOnWindowFocus: false },
        mutations: { retry: false },
    },
});
const router = createBrowserRouter([
    {
        path: "*",
        element: (
            <ThemeProvider>
                <ToastProvider>
                    <SessionProvider>
                        <DraftNavigationProvider>
                            <App />
                        </DraftNavigationProvider>
                    </SessionProvider>
                </ToastProvider>
            </ThemeProvider>
        ),
    },
]);
createRoot(document.getElementById("root")!).render(
    <LocaleProvider>
        <FieldValidation />
        <ErrorBoundary>
            <QueryClientProvider client={client}>
                <RouterProvider router={router} />
            </QueryClientProvider>
        </ErrorBoundary>
    </LocaleProvider>,
);
