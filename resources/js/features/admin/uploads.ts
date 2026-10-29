import { tr } from "../../i18n/runtime";
import { api, json, sessionHeaders } from "../../lib/api";
export async function uploadWithReservation<T>(
    file: File,
    purpose: "audio" | "cover",
    path: string,
    fields: Record<string, string> = {},
    expectedContext: string | null = sessionHeaders()["X-Session-Context"] ??
        null,
): Promise<T> {
    const context = expectedContext;
    const assertContext = () => {
        if (!context || context !== sessionHeaders()["X-Session-Context"])
            throw new Error(tr("mb220616729"));
    };
    assertContext();
    const reserved = await api<{ data: { id: string } }>(
        "/admin/storage-reservations",
        { method: "POST", body: json({ size_bytes: file.size, purpose }) },
    );
    try {
        assertContext();
        const form = new FormData();
        form.append("reservation_id", reserved.data.id);
        form.append(purpose === "audio" ? "audio" : "cover", file);
        for (const [key, value] of Object.entries(fields))
            form.append(key, value);
        return await api<T>(path, { method: "POST", body: form });
    } catch (error) {
        if (context === sessionHeaders()["X-Session-Context"]) {
            try {
                await api(
                    `/admin/storage-reservations/${reserved.data.id}`,
                    { method: "DELETE" },
                    false,
                    false,
                );
            } catch {}
        }
        throw error;
    }
}

export function createUploadBatch() {
    const context = sessionHeaders()["X-Session-Context"] ?? null;
    return function upload<T>(
        file: File,
        purpose: "audio" | "cover",
        path: string,
        fields: Record<string, string> = {},
    ) {
        return uploadWithReservation<T>(file, purpose, path, fields, context);
    };
}
