const STORAGE_KEY = "ak-playback-device-key-v1";

export type PlaybackDevice = {
    id: string;
    device_key: string;
    name: string;
    user_agent: string | null;
    revision: number;
    last_seen_at: string | null;
    revoked_at: string | null;
    is_active: boolean;
};

export type PlaybackLease = {
    device_id: string | null;
    generation: number;
    lease_token: string | null;
    state: "idle" | "active" | "transferring";
    revision: number;
    transfer_target_id: string | null;
    transfer_expires_at: string | null;
    lease_expires_at: string | null;
};

export type DeviceCommand = {
    id: number;
    sequence: number;
    lease_generation: number;
    type: string;
    payload: Record<string, unknown>;
    expires_at: string;
    source_device_id: string | null;
};

export function deviceKey(): string {
    try {
        const existing = sessionStorage.getItem(STORAGE_KEY);
        if (existing && existing.length >= 16) return existing;
        const key =
            typeof crypto !== "undefined" && "randomUUID" in crypto
                ? crypto.randomUUID().replace(/-/g, "")
                : `d${Date.now().toString(36)}${Math.random().toString(36).slice(2, 14)}`;
        sessionStorage.setItem(STORAGE_KEY, key);
        return key;
    } catch {
        return `ephemeral-${Date.now()}`;
    }
}

export function deviceDisplayName(): string {
    const ua =
        typeof navigator !== "undefined" ? navigator.userAgent : "device";
    if (/iPhone|iPad/i.test(ua)) return "iOS browser";
    if (/Android/i.test(ua)) return "Android browser";
    if (/Firefox/i.test(ua)) return "Firefox";
    if (/Edg/i.test(ua)) return "Edge";
    if (/Chrome/i.test(ua)) return "Chrome";
    if (/Safari/i.test(ua)) return "Safari";
    return "Browser tab";
}

export function isControlling(
    lease: PlaybackLease | null,
    deviceId: string | null,
): boolean {
    return !!(
        lease &&
        deviceId &&
        lease.state === "active" &&
        lease.device_id === deviceId &&
        lease.lease_token
    );
}
