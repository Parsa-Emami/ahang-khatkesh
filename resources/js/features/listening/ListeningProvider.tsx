import {
    createContext,
    useContext,
    useEffect,
    useLayoutEffect,
    useRef,
    useState,
    type PropsWithChildren,
} from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useSession, useToast } from "../../context/AppContext";
import {
    api,
    captureSessionIntent,
    json,
    type SessionIntent,
} from "../../lib/api";
import { setListeningRuntime, type ListeningSettings } from "./runtime";
const Context = createContext<{
    settings: ListeningSettings | null;
    pending: boolean;
    error: Error | null;
    change: (
        patch: Partial<ListeningSettings> & { private_session?: boolean },
    ) => void;
    erase: (id?: string) => void;
    refresh: () => void;
} | null>(null);
export function ListeningProvider({ children }: PropsWithChildren) {
    const { user } = useSession(),
        client = useQueryClient(),
        notify = useToast();
    const [blocked, setBlocked] = useState(false);
    const channelRef = useRef<BroadcastChannel | null>(null);
    const key = ["listening-settings", user!.id, user!.access_version];
    const query = useQuery({
        queryKey: key,
        queryFn: ({ signal }) =>
            api<{ data: ListeningSettings }>("/listening/settings", { signal }),
        staleTime: 5000,
        refetchInterval: 30000,
        refetchOnWindowFocus: true,
    });
    const clear = () => {
        void client.cancelQueries({
            predicate: (query) =>
                ["listening-history", "listening-mix"].includes(
                    String(query.queryKey[0]),
                ),
        });
        client.removeQueries({
            predicate: (query) =>
                ["listening-history", "listening-mix"].includes(
                    String(query.queryKey[0]),
                ),
        });
    };
    const block = () => {
        void client.cancelQueries({ queryKey: key });
        setBlocked(true);
        setListeningRuntime(user!.id, query.data?.data ?? null, true);
        clear();
    };
    const refresh = () => {
        block();
        void query.refetch().finally(() => setBlocked(false));
    };
    useLayoutEffect(() => {
        setListeningRuntime(
            user!.id,
            query.data?.data ?? null,
            blocked || query.isPending || !!query.error,
        );
    }, [user!.id, query.data, query.isPending, query.error, blocked]);
    useEffect(() => () => setListeningRuntime(null, null, true), []);
    useEffect(() => {
        const channel =
            typeof BroadcastChannel === "undefined"
                ? null
                : new BroadcastChannel("ak-listening-v1");
        channelRef.current = channel;
        if (channel)
            channel.onmessage = (event) => {
                if (event.data === user!.id) refresh();
            };
        return () => {
            if (channelRef.current === channel) channelRef.current = null;
            channel?.close();
        };
    }, [user!.id, query.data]);
    const mutation = useMutation({
        mutationFn: (
            intent: SessionIntent<{
                path: string;
                method: string;
                body: unknown;
            }>,
        ) =>
            api<{ data: ListeningSettings }>(
                intent.value.path,
                { method: intent.value.method, body: json(intent.value.body) },
                true,
                true,
                intent,
            ),
        onSuccess: (result) => {
            clear();
            client.setQueryData(key, result);
            channelRef.current?.postMessage(user!.id);
        },
        onError: (error) => {
            notify(error.message, true);
            void query.refetch();
        },
        onSettled: () => setBlocked(false),
    });
    const submit = (path: string, method: string, patch: object) => {
        if (!query.data || mutation.isPending) return;
        const body = { ...patch, revision: query.data.data.revision };
        block();
        mutation.mutate(captureSessionIntent({ path, method, body }));
    };
    return (
        <Context.Provider
            value={{
                settings: query.data?.data ?? null,
                pending: blocked || query.isPending || mutation.isPending,
                error: query.error,
                refresh,
                change: (patch) =>
                    submit("/listening/settings", "PATCH", patch),
                erase: (id) =>
                    submit(
                        `/listening/history${id ? `/${id}` : ""}`,
                        "DELETE",
                        {},
                    ),
            }}
        >
            {children}
        </Context.Provider>
    );
}
export function useListening() {
    const context = useContext(Context);
    if (!context) throw new Error("ListeningProvider required");
    return context;
}
