import { api } from "../../lib/api";
import type {
    Overview,
    Operations,
    AuditEvent,
    CursorPage,
    Artist,
    Release,
    CatalogPage,
} from "./contracts";
import type { Track } from "../../types";
export const adminKeys = {
    root: ["admin"] as const,
    overview: ["admin", "overview"] as const,
    operations: ["admin", "operations"] as const,
    catalog: (kind: string, q: string, page: number) =>
        ["admin", "catalog", kind, q, page] as const,
    audit: (action: string, since: string, until: string, cursor: string) =>
        ["admin", "audit", action, since, until, cursor] as const,
};
export const adminApi = {
    overview: () => api<{ data: Overview }>("/admin/overview"),
    operations: () => api<{ data: Operations }>("/admin/operations"),
    artists: (q: string, page = 1) =>
        api<CatalogPage<Artist>>(
            `/admin/artists?${new URLSearchParams({ q, page: String(page) })}`,
        ),
    releases: (q: string, page = 1) =>
        api<CatalogPage<Release>>(
            `/admin/releases?${new URLSearchParams({ q, page: String(page) })}`,
        ),
    tracks: (q: string, page = 1) =>
        api<CatalogPage<Track>>(
            `/admin/tracks?${new URLSearchParams({ q, page: String(page) })}`,
        ),
    audit: (action: string, since: string, until: string, cursor: string) =>
        api<CursorPage<AuditEvent>>(
            `/admin/audit-events?${new URLSearchParams(Object.fromEntries(Object.entries({ action, since, until, cursor }).filter(([, v]) => v)))}`,
        ),
};
