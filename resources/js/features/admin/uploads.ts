import { api, json, sessionHeaders } from "../../lib/api";
export async function uploadWithReservation<T>(
    file: File,
    purpose: "audio" | "cover",
    path: string,
    fields: Record<string, string> = {},
): Promise<T> {
    const context = sessionHeaders()["X-Session-Context"];
    const reserved = await api<{ data: { id: string } }>(
        "/admin/storage-reservations",
        { method: "POST", body: json({ size_bytes: file.size, purpose }) },
    );
    try {
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
