import {
    createContext,
    useContext,
    useEffect,
    useLayoutEffect,
    useRef,
    useState,
    useId,
    type PropsWithChildren,
} from "react";
import { useBlocker } from "react-router-dom";
import { Dialog } from "../../components/UI";
import { tr } from "../../i18n/runtime";
import { useLocale } from "../../i18n/LocaleProvider";
type Draft = {
    dirty: boolean;
    scope: string;
    retainedWithinAdmin?: boolean;
    discard: () => void;
};
type Request = { proceed: () => void; cancel: () => void; scope?: string };
export const DraftScope = createContext("page");
const Context = createContext({
    register: (_key: string, _draft: Draft) => () => {},
    request: (_request: Request) => {},
});
export function DraftNavigationProvider({ children }: PropsWithChildren) {
    useLocale();
    const entries = useRef(new Map<string, Draft>());
    const [pending, setPending] = useState<Request | null>(null);
    const blocker = useBlocker(
        ({ currentLocation, nextLocation }) =>
            (currentLocation.pathname !== nextLocation.pathname ||
                currentLocation.search !== nextLocation.search ||
                currentLocation.hash !== nextLocation.hash) &&
            [...entries.current.values()].some(
                (item) =>
                    item.dirty &&
                    !(
                        item.retainedWithinAdmin &&
                        currentLocation.pathname === "/admin" &&
                        nextLocation.pathname === "/admin"
                    ),
            ),
    );
    const register = (key: string, draft: Draft) => {
        entries.current.set(key, draft);
        return () => {
            entries.current.delete(key);
        };
    };
    const dirty = (scope?: string) =>
        [...entries.current.values()].some(
            (item) => item.dirty && (!scope || item.scope === scope),
        );
    const request = (value: Request) => {
        if (!dirty(value.scope)) value.proceed();
        else setPending((old) => old || value);
    };
    useEffect(() => {
        if (blocker.state === "blocked")
            setPending(
                (old) =>
                    old || { proceed: blocker.proceed, cancel: blocker.reset },
            );
    }, [blocker]);
    useEffect(() => {
        const unload = (event: BeforeUnloadEvent) => {
            if (!dirty()) return;
            event.preventDefault();
            event.returnValue = "";
        };
        window.addEventListener("beforeunload", unload);
        return () => window.removeEventListener("beforeunload", unload);
    }, []);
    const cancel = () => {
        pending?.cancel();
        setPending(null);
    };
    const discard = () => {
        if (!pending) return;
        const action = pending;
        for (const item of entries.current.values())
            if (item.dirty && (!action.scope || item.scope === action.scope)) {
                item.dirty = false;
                item.discard();
            }
        setPending(null);
        action.proceed();
    };
    return (
        <Context.Provider value={{ register, request }}>
            {children}
            <Dialog
                priority
                guard={false}
                open={!!pending}
                onOpenChange={(open) => {
                    if (!open) cancel();
                }}
                title={tr("draft.title")}
                description={tr("draft.description")}
            >
                <div className="dialog-actions">
                    <button className="button secondary" onClick={cancel}>
                        {tr("draft.stay")}
                    </button>
                    <button className="button danger" onClick={discard}>
                        {tr("draft.discard")}
                    </button>
                </div>
            </Dialog>
        </Context.Provider>
    );
}
export function useDraftGuard(
    dirty: boolean,
    discard: () => void = () => {},
    explicitScope?: string,
    retainedWithinAdmin = false,
) {
    const context = useContext(Context);
    const inheritedScope = useContext(DraftScope);
    const key = useId();
    const latest = useRef(discard);
    latest.current = discard;
    const scope = explicitScope || inheritedScope;
    useLayoutEffect(
        () =>
            context.register(key, {
                dirty,
                scope,
                retainedWithinAdmin,
                discard: () => latest.current(),
            }),
        [context.register, key, dirty, scope, retainedWithinAdmin],
    );
}
export const useDraftNavigation = () => useContext(Context);
export function useDraftValue<T>(value: T, discard: () => void) {
    const baseline = useRef(JSON.stringify(value));
    useDraftGuard(JSON.stringify(value) !== baseline.current, discard);
}
