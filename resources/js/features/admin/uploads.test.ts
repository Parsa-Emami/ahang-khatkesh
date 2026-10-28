import { beforeEach, describe, expect, it, vi } from "vitest";
const transport = vi.hoisted(() => ({ context: "1:0", request: vi.fn() }));
vi.mock("../../lib/api", () => ({
    api: transport.request,
    json: JSON.stringify,
    sessionHeaders: () => ({ "X-Session-Context": transport.context }),
}));
import { createUploadBatch, uploadWithReservation } from "./uploads";
const file = () =>
    new File(["synthetic"], "source.flac", { type: "audio/flac" });
beforeEach(() => {
    transport.context = "1:0";
    transport.request.mockReset();
});
describe("session-bound upload batch", () => {
    it("reserves storage before sending each file for the initiating account", async () => {
        transport.request
            .mockResolvedValueOnce({ data: { id: "reservation-a" } })
            .mockResolvedValueOnce({ data: { id: "asset-a" } });
        const upload = createUploadBatch();
        await expect(upload(file(), "audio", "/admin/media")).resolves.toEqual({
            data: { id: "asset-a" },
        });
        expect(transport.request.mock.calls[0][0]).toBe(
            "/admin/storage-reservations",
        );
        const form = transport.request.mock.calls[1][1].body as FormData;
        expect(form.get("reservation_id")).toBe("reservation-a");
        expect(form.get("audio")).toBeInstanceOf(File);
    });
    it("does not reserve or upload the next selected file after an account or epoch change", async () => {
        transport.request
            .mockResolvedValueOnce({ data: { id: "reservation-a" } })
            .mockResolvedValueOnce({ data: { id: "asset-a" } });
        const upload = createUploadBatch();
        await upload(file(), "audio", "/admin/media");
        transport.context = "2:1";
        await expect(upload(file(), "audio", "/admin/media")).rejects.toThrow(
            "نشست تغییر کرد",
        );
        expect(transport.request).toHaveBeenCalledTimes(2);
    });
    it("refuses the file body when the session changes while storage is being reserved", async () => {
        transport.request.mockImplementationOnce(async () => {
            transport.context = "1:1";
            return { data: { id: "reservation-a" } };
        });
        const upload = createUploadBatch();
        await expect(upload(file(), "audio", "/admin/media")).rejects.toThrow(
            "نشست تغییر کرد",
        );
        expect(transport.request).toHaveBeenCalledTimes(1);
    });
    it("cancels only its own unused reservation after a failed upload in the same context", async () => {
        transport.request
            .mockResolvedValueOnce({ data: { id: "reservation-a" } })
            .mockRejectedValueOnce(new Error("upload failed"))
            .mockResolvedValueOnce({ data: { released: true } });
        await expect(
            uploadWithReservation(file(), "audio", "/admin/media"),
        ).rejects.toThrow("upload failed");
        expect(transport.request.mock.calls[2]).toEqual([
            "/admin/storage-reservations/reservation-a",
            { method: "DELETE" },
            false,
            false,
        ]);
    });
});
