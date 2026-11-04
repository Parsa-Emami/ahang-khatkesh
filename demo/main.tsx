import { createRoot } from "react-dom/client";
import { createHashRouter, RouterProvider } from "react-router-dom";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import {
  LocaleProvider,
  applyPreferences,
  useLocale,
} from "../resources/js/i18n/LocaleProvider";
import { readPreferences } from "../resources/js/i18n/locales";
import { DraftNavigationProvider } from "../resources/js/features/drafts/DraftNavigation";
import {
  SessionProvider,
  ThemeProvider,
  ToastProvider,
} from "../resources/js/context/AppContext";
import { App } from "../resources/js/App";
import { ErrorBoundary } from "../resources/js/components/ErrorBoundary";
import { installDemoTransport, resetDemo } from "./transport";
import "@fontsource/vazirmatn/400.css";
import "@fontsource/vazirmatn/500.css";
import "@fontsource/vazirmatn/600.css";
import "@fontsource/vazirmatn/700.css";
import "@fontsource-variable/manrope";
import "../resources/css/app.css";
import "./demo.css";

installDemoTransport();
applyPreferences(readPreferences());

function DemoNotice() {
  const { locale } = useLocale();
  const english = locale === "en-US";
  return (
    <aside
      className="demo-notice"
      aria-label={english ? "Public demo notice" : "اطلاع نسخه نمایشی"}
    >
      <div>
        <strong>
          {english
            ? "PUBLIC DEMO · AHANG KHATKESH"
            : "نسخه نمایشی عمومی · آهنگ خط‌کش"}
        </strong>
        <span>
          {english
            ? "Sample data and synthetic audio. Changes stay in this browser. No real accounts or uploads."
            : "صدا و اطلاعات نمونه؛ تغییرات فقط در همین مرورگر ذخیره می‌شوند. حساب واقعی و آپلود فعال نیست."}
        </span>
      </div>
      <div className="demo-notice-actions">
        <a
          href="https://github.com/Parsa-Emami/ahang-khatkesh"
          target="_blank"
          rel="noreferrer"
        >
          GitHub ↗
        </a>
        <button type="button" onClick={resetDemo}>
          {english ? "Reset demo" : "شروع دوباره"}
        </button>
      </div>
    </aside>
  );
}

const client = new QueryClient({
  defaultOptions: {
    queries: { staleTime: 30000, retry: 0, refetchOnWindowFocus: false },
    mutations: { retry: false },
  },
});
const router = createHashRouter([
  {
    path: "*",
    element: (
      <ThemeProvider>
        <DemoNotice />
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
    <ErrorBoundary>
      <QueryClientProvider client={client}>
        <RouterProvider router={router} />
      </QueryClientProvider>
    </ErrorBoundary>
  </LocaleProvider>,
);
