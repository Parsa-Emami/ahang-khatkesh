let csrfToken = "";
let confirmSession: (() => Promise<boolean>) | null = null;
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
    ) {
        super(message);
    }
}
export const getCsrfToken = () => csrfToken;
export function setCsrfToken(token: string) {
    csrfToken = token;
}
export async function api<T>(
    path: string,
    options: RequestInit = {},
    retryCsrf = true,
    retryConfirmation = true,
): Promise<T> {
    const headers = new Headers(options.headers);
    headers.set("Accept", "application/json");
    headers.set("X-Requested-With", "XMLHttpRequest");
    if (csrfToken) headers.set("X-CSRF-TOKEN", csrfToken);
    if (options.body && !(options.body instanceof FormData))
        headers.set("Content-Type", "application/json");
    let response: Response;
    try {
        response = await fetch(`/api/v1${path}`, {
            ...options,
            headers,
            credentials: "same-origin",
        });
    } catch {
        throw new ApiError(
            0,
            "ارتباط برقرار نشد. اتصال اینترنت و سرور را بررسی کنید.",
        );
    }
    const payload = await response
        .json()
        .catch(() => ({ message: "پاسخ سرور قابل خواندن نیست." }));
    if (response.status === 419 && retryCsrf) {
        const session = await api<{ data: { csrf_token: string } }>(
            "/session",
            {},
            false,
        );
        setCsrfToken(session.data.csrf_token);
        return api<T>(path, options, false, retryConfirmation);
    }
    if (
        response.status === 423 &&
        payload.code === "password_confirmation_required" &&
        retryConfirmation &&
        confirmSession
    ) {
        if (await confirmSession())
            return api<T>(path, options, retryCsrf, false);
        throw new ApiError(423, "تأیید هویت لغو شد؛ تغییری ثبت نشد.");
    }
    if (!response.ok) {
        if (response.status === 401)
            window.dispatchEvent(new Event("session-expired"));
        throw new ApiError(
            response.status,
            Object.values(payload.errors || {})
                .flat()
                .join(" · ") ||
                payload.message ||
                "عملیات انجام نشد.",
            payload.errors,
        );
    }
    if (payload.data?.csrf_token) setCsrfToken(payload.data.csrf_token);
    return payload as T;
}
export const json = (value: unknown) => JSON.stringify(value);
