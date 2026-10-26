import { createRoot } from "react-dom/client";
import { BrowserRouter } from "react-router-dom";
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
createRoot(document.getElementById("root")!).render(
    <ErrorBoundary>
        <QueryClientProvider client={client}>
            <BrowserRouter>
                <ThemeProvider>
                    <ToastProvider>
                        <SessionProvider>
                            <App />
                        </SessionProvider>
                    </ToastProvider>
                </ThemeProvider>
            </BrowserRouter>
        </QueryClientProvider>
    </ErrorBoundary>,
);
