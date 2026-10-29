import { currentPreferences, localizeText } from "../i18n/runtime";
import { tr } from "../i18n/runtime";
import type { User } from "../types";

let csrfToken = "";
let confirmSession: (() => Promise<boolean>) | null = null;
let identity: Pick<User, "id" | "access_version"> | null = null;
let generation = 0;
const identityKey = (user: typeof identity) =>
    user ? `${user.id}:${user.access_version}` : null;
export function setSessionIdentity(user: typeof identity) {
    if (identityKey(user) !== identityKey(identity)) generation++;
    identity = user;
}
export function invalidateSessionIdentity() {
    generation++;
    identity = null;
}
export type SessionIntent<T> = {
    value: T;
    generation: number;
    identity: string | null;
};
export function captureSessionIntent<T>(value: T): SessionIntent<T> {
    return { value, generation, identity: identityKey(identity) };
}
export function sessionHeaders(): Record<string, string> {
    const key = identityKey(identity);
    return key ? { "X-Session-Context": key } : {};
}
export function setSessionConfirmation(
    handler: (() => Promise<boolean>) | null,
) {
    confirmSession = handler;
}
export class ApiError extends Error {
    constructor(
        public status: number,
        message: string,
        public fields: Record<string, string[]> = {},
        public code?: string,
        public requestId?: string,
    ) {
        super(message);
        Object.defineProperty(this, "message", {
            get: () => localizeText(message),
            configurable: true,
        });
    }
}
export const getCsrfToken = () => csrfToken;
export function setCsrfToken(token: string) {
    csrfToken = token;
}
function announce(user: User | null, broadcast: boolean) {
    setSessionIdentity(user);
    window.dispatchEvent(new CustomEvent("session-identity", { detail: user }));
    if (broadcast) window.dispatchEvent(new Event("session-auth-change"));
}
function contextError() {
    return new ApiError(409, tr("md7f5c71a4a"), {}, "session_context_changed");
}
export async function api<T>(
    path: string,
    options: RequestInit = {},
    retryCsrf = true,
    retryConfirmation = true,
    intent?: SessionIntent<unknown>,
): Promise<T> {
    const captured = generation;
    const capturedKey = identityKey(identity);
    if (
        intent &&
        (intent.generation !== captured || intent.identity !== capturedKey)
    )
        throw contextError();
    const identityRequest =
        path === "/session" || path === "/login" || path === "/login/challenge";
    const headers = new Headers(options.headers);
    headers.set("Accept", "application/json");
    headers.set("Accept-Language", currentPreferences().locale);
    headers.set("X-Requested-With", "XMLHttpRequest");
    if (csrfToken) headers.set("X-CSRF-TOKEN", csrfToken);
    if (!identityRequest && capturedKey)
        headers.set("X-Session-Context", capturedKey);
    if (options.body && !(options.body instanceof FormData))
        headers.set("Content-Type", "application/json");
    let response: Response;
    try {
        response = await fetch(`/api/v1${path}`, {
            ...options,
            headers,
            credentials: "same-origin",
        });
    } catch (error) {
        if (error instanceof Error && error.name === "AbortError") throw error;
        throw new ApiError(0, tr("m418cce799c"));
    }
    let payload;
    try {
        payload = await response.json();
    } catch (error) {
        if (error instanceof Error && error.name === "AbortError") throw error;
        payload = { message: tr("m7ea5b18f87") };
    }
    if (captured !== generation) throw contextError();
    const unchanged = () =>
        captured === generation && capturedKey === identityKey(identity);
    if (response.status === 419 && retryCsrf && !identityRequest) {
        await api("/session", {}, false, false);
        if (!unchanged()) throw contextError();
        return api<T>(path, options, false, retryConfirmation, intent);
    }
    if (
        response.status === 423 &&
        payload.code === "password_confirmation_required" &&
        retryConfirmation &&
        confirmSession
    ) {
        if (!(await confirmSession()))
            throw new ApiError(423, tr("mf1a9855d9d"));
        if (!unchanged()) throw contextError();
        return api<T>(path, options, retryCsrf, false, intent);
    }
    if (!response.ok) {
        if (response.status === 422 && payload.errors)
            window.dispatchEvent(
                new CustomEvent("api-field-errors", { detail: payload.errors }),
            );
        if (
            response.status === 401 ||
            payload.code === "session_context_changed" ||
            payload.code === "session_context_required"
        ) {
            invalidateSessionIdentity();
            window.dispatchEvent(
                new Event(
                    response.status === 401
                        ? "session-expired"
                        : "session-context-changed",
                ),
            );
        }
        throw new ApiError(
            response.status,
            Object.values(payload.errors || {})
                .flat()
                .join(" · ") ||
                payload.message ||
                tr("m68a6e1d0cd"),
            payload.errors,
            payload.code,
            response.headers.get("X-Request-ID") || undefined,
        );
    }
    if (payload.data?.csrf_token) setCsrfToken(payload.data.csrf_token);
    if (payload.data && Object.hasOwn(payload.data, "user")) {
        announce(payload.data.user, path !== "/session");
    }
    if (path === "/logout") {
        invalidateSessionIdentity();
        announce(null, true);
    }
    return payload as T;
}
export const json = (value: unknown) => JSON.stringify(value);
