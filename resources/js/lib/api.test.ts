import { beforeEach, afterEach, describe, expect, it, vi } from "vitest";
import {
    api,
    ApiError,
    invalidateSessionIdentity,
    setCsrfToken,
    setSessionConfirmation,
    setSessionIdentity,
} from "./api";
const response = (status: number, data: unknown) =>
    new Response(JSON.stringify(data), {
        status,
        headers: {
            "Content-Type": "application/json",
            "X-Request-ID": "test-request",
        },
    });
const actor = { id: 1, access_version: 3 };
beforeEach(() => {
    vi.stubGlobal("window", new EventTarget());
    setSessionIdentity(actor);
    setCsrfToken("csrf-one");
    setSessionConfirmation(null);
});
afterEach(() => vi.unstubAllGlobals());
describe("session-bound transport", () => {
    it("signs business requests and preserves safe error details", async () => {
        const fetcher = vi.fn().mockResolvedValue(
            response(422, {
                message: "Invalid",
                errors: { name: ["Required"] },
                code: "validation",
            }),
        );
        vi.stubGlobal("fetch", fetcher);
        await expect(
            api("/admin/playlists", { method: "POST", body: "{}" }),
        ).rejects.toMatchObject({
            status: 422,
            message: "Required",
            requestId: "test-request",
            code: "validation",
        });
        const headers = fetcher.mock.calls[0][1].headers as Headers;
        expect(headers.get("X-Session-Context")).toBe("1:3");
        expect(headers.get("X-CSRF-TOKEN")).toBe("csrf-one");
    });
    it("retries a CSRF failure once for the same actor and epoch", async () => {
        const fetcher = vi
            .fn()
            .mockResolvedValueOnce(response(419, { message: "CSRF" }))
            .mockResolvedValueOnce(
                response(200, {
                    data: { user: actor, csrf_token: "csrf-two" },
                }),
            )
            .mockResolvedValueOnce(response(200, { data: { saved: true } }));
        vi.stubGlobal("fetch", fetcher);
        await expect(
            api("/playback", { method: "PUT", body: "{}" }),
        ).resolves.toEqual({ data: { saved: true } });
        expect(fetcher).toHaveBeenCalledTimes(3);
        expect(
            (fetcher.mock.calls[2][1].headers as Headers).get("X-CSRF-TOKEN"),
        ).toBe("csrf-two");
    });
    it.each([
        { id: 2, access_version: 3 },
        { id: 1, access_version: 4 },
    ])(
        "never replays an old intent after a different actor or epoch",
        async (user) => {
            const fetcher = vi
                .fn()
                .mockResolvedValueOnce(response(419, {}))
                .mockResolvedValueOnce(
                    response(200, { data: { user, csrf_token: "changed" } }),
                );
            vi.stubGlobal("fetch", fetcher);
            await expect(
                api("/playback", { method: "PUT", body: "{}" }),
            ).rejects.toMatchObject({ code: "session_context_changed" });
            expect(fetcher).toHaveBeenCalledTimes(2);
        },
    );
    it("does not replay when the confirmation dialog is cancelled", async () => {
        const fetcher = vi
            .fn()
            .mockResolvedValue(
                response(423, { code: "password_confirmation_required" }),
            );
        vi.stubGlobal("fetch", fetcher);
        setSessionConfirmation(async () => false);
        await expect(
            api("/admin/playlists", { method: "POST" }),
        ).rejects.toBeInstanceOf(ApiError);
        expect(fetcher).toHaveBeenCalledTimes(1);
    });
    it("fences a pending confirmation when another account becomes current", async () => {
        const fetcher = vi
            .fn()
            .mockResolvedValue(
                response(423, { code: "password_confirmation_required" }),
            );
        vi.stubGlobal("fetch", fetcher);
        setSessionConfirmation(async () => {
            setSessionIdentity({ id: 2, access_version: 0 });
            return true;
        });
        await expect(
            api("/admin/playlists", { method: "POST" }),
        ).rejects.toMatchObject({ code: "session_context_changed" });
        expect(fetcher).toHaveBeenCalledTimes(1);
    });
    it("rejects a late session response after invalidation", async () => {
        let finish!: (response: Response) => void;
        vi.stubGlobal(
            "fetch",
            vi.fn(
                () =>
                    new Promise<Response>((resolve) => {
                        finish = resolve;
                    }),
            ),
        );
        const pending = api("/session");
        invalidateSessionIdentity();
        setSessionIdentity({ id: 2, access_version: 0 });
        finish(response(200, { data: { user: actor, csrf_token: "stale" } }));
        await expect(pending).rejects.toMatchObject({
            code: "session_context_changed",
        });
    });
    it("preserves abort errors rather than reporting connectivity loss", async () => {
        vi.stubGlobal(
            "fetch",
            vi
                .fn()
                .mockRejectedValue(new DOMException("Cancelled", "AbortError")),
        );
        await expect(api("/tracks")).rejects.toMatchObject({
            name: "AbortError",
        });
    });
});
