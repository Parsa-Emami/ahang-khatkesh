import { describe, expect, it } from "vitest";
import { createDemoApi } from "./transport";

function memoryStore() {
  const values = new Map<string, string>();
  return {
    getItem: (key: string) => values.get(key) ?? null,
    setItem: (key: string, value: string) => {
      values.set(key, value);
    },
    removeItem: (key: string) => {
      values.delete(key);
    },
  };
}

describe("public demo boundary", () => {
  it("uses sample audio beneath the deployment base and exposes a listener only", async () => {
    const api = createDemoApi(null, "/test-base/");
    const session = await (await api("/session")).json();
    expect(session.data.user.permissions).toEqual([]);
    const library = await (await api("/playlists/1")).json();
    expect(library.data.tracks_count).toBe(library.data.tracks.length);
    expect(
      library.data.tracks.every((track: { stream_url: string }) =>
        track.stream_url.startsWith("/test-base/audio/"),
      ),
    ).toBe(true);
  });
  it("persists local likes and saved playlists without changing the sample catalog", async () => {
    const store = memoryStore();
    const first = createDemoApi(store, "/");
    await first("/tracks/1/like", "PUT");
    await first("/playlists/2/save", "PUT");
    const second = createDemoApi(store, "/");
    const tracks = await (await second("/tracks?liked=1")).json();
    const lists = await (await second("/playlists?saved=1")).json();
    expect(tracks.data.some((track: { id: number }) => track.id === 1)).toBe(
      true,
    );
    expect(
      lists.data.some((playlist: { id: number }) => playlist.id === 2),
    ).toBe(true);
    expect((await (await second("/stats")).json()).data.tracks).toBe(6);
  });
  it("blocks credentials, administration, uploads, and unsupported mutations", async () => {
    const api = createDemoApi(null, "/");
    for (const path of [
      "/login",
      "/me/password",
      "/admin/users",
      "/admin/media",
      "/playlists",
      "/tracks/1/lyrics",
    ]) {
      const result = await api(path, "POST", { password: "unused" });
      expect(result.status).toBe(403);
      expect((await result.json()).code).toBe("demo_server_required");
    }
    await api("/me", "PATCH", {
      role: "owner",
      permissions: ["catalog.manage"],
    });
    expect((await (await api("/session")).json()).data.user.role).toBe(
      "listener",
    );
    expect((await api("/me", "PATCH", { offline_opt_in: true })).status).toBe(
      403,
    );
  });
  it("restores the playback queue and preserves privacy defaults", async () => {
    const store = memoryStore();
    const api = createDemoApi(store, "/");
    expect(
      (await (await api("/listening/plays", "POST", { track_id: 1 })).json())
        .data.recording,
    ).toBe(false);
    await api("/playback", "PUT", {
      queue: [2, 6, 99],
      track_id: 2,
      position_seconds: 8,
      volume: 0.3,
      repeat_mode: "all",
    });
    const restored = await (
      await createDemoApi(store, "/")("/playback")
    ).json();
    expect(
      restored.data.queue.map((track: { id: number }) => track.id),
    ).toEqual([2, 6]);
    expect(restored.data.position_seconds).toBe(8);
  });
});
