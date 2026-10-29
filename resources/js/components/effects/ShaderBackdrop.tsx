import {
    lazy,
    Suspense,
    useCallback,
    useEffect,
    useRef,
    useState,
    useSyncExternalStore,
} from "react";
import { useTheme } from "../../context/AppContext";
import { useLocale } from "../../i18n/LocaleProvider";
import { EffectBoundary } from "./EffectBoundary";
import { EffectCoordinator } from "./budget";
import type { EffectFailure, Palette } from "./renderer";

const Canvas = lazy(() => import("./DitherFlowCanvas"));
const coordinator = new EffectCoordinator();
const palettes: { dark: Palette; light: Palette } = {
    dark: ["#22271d", "#37432a", "#77884c", "#d8ef78"],
    light: ["#eceee4", "#d6dfc0", "#a4b67b", "#6b813b"],
};
type Connection = EventTarget & { saveData?: boolean };
export function ShaderBackdrop({
    surface,
}: {
    surface: "library-hero" | "expanded-now-playing";
}) {
    const { motion } = useLocale();
    const { dark } = useTheme();
    const host = useRef<HTMLDivElement>(null);
    const [key] = useState(() => Symbol(surface));
    const [visible, setVisible] = useState(false);
    const [conditions, setConditions] = useState({
        reduced: true,
        saveData: false,
        hidden: true,
        blocked: false,
    });
    const [failed, setFailed] = useState<EffectFailure | "module" | null>(null);
    const onFailure = useCallback(
        (reason: EffectFailure) => setFailed(reason),
        [],
    );
    const moduleFailure = useCallback(() => setFailed("module"), []);
    useEffect(() => {
        const query = matchMedia("(prefers-reduced-motion: reduce)");
        const forcedColors = matchMedia("(forced-colors: active)");
        const connection = (
            navigator as Navigator & { connection?: Connection }
        ).connection;
        const update = () =>
            setConditions({
                reduced: query.matches || forcedColors.matches,
                saveData: connection?.saveData === true,
                hidden: document.hidden,
                blocked: !!document.querySelector(
                    '[role="dialog"]:not(.dialog-now-playing)',
                ),
            });
        const mutations = new MutationObserver(update);
        mutations.observe(document.body, { childList: true, subtree: true });
        query.addEventListener("change", update);
        forcedColors.addEventListener("change", update);
        connection?.addEventListener("change", update);
        document.addEventListener("visibilitychange", update);
        update();
        return () => {
            mutations.disconnect();
            query.removeEventListener("change", update);
            forcedColors.removeEventListener("change", update);
            connection?.removeEventListener("change", update);
            document.removeEventListener("visibilitychange", update);
        };
    }, []);
    useEffect(() => {
        if (!host.current || typeof IntersectionObserver === "undefined")
            return;
        const observer = new IntersectionObserver(([entry]) =>
            setVisible(entry.isIntersecting),
        );
        observer.observe(host.current);
        return () => observer.disconnect();
    }, []);
    const reason =
        failed ??
        (motion === "reduced" || conditions.reduced
            ? "motion"
            : conditions.saveData
              ? "save-data"
              : conditions.hidden
                ? "hidden"
                : conditions.blocked
                  ? "dialog"
                  : !visible
                    ? "offscreen"
                    : null);
    useEffect(() => {
        if (reason) return;
        return coordinator.enter(
            key,
            surface === "expanded-now-playing" ? 10 : 1,
        );
    }, [key, reason, surface]);
    const winner = useSyncExternalStore(
        coordinator.subscribe,
        coordinator.winner,
        () => null,
    );
    const active = !reason && winner === key;
    return (
        <div
            ref={host}
            className="shader-backdrop"
            aria-hidden="true"
            data-effect-surface={surface}
            data-effect-state={active ? "active" : "static"}
            data-effect-reason={reason ?? (active ? "" : "capacity")}
        >
            {active && (
                <EffectBoundary onFailure={moduleFailure}>
                    <Suspense fallback={null}>
                        <Canvas
                            palette={dark ? palettes.dark : palettes.light}
                            onFailure={onFailure}
                        />
                    </Suspense>
                </EffectBoundary>
            )}
        </div>
    );
}
