import type {
  PlaybackSnapshot,
  Playlist,
  Track,
  User,
} from "../resources/js/types";
import type { ListeningSettings } from "../resources/js/features/listening/runtime";

const key = "ahang-khatkesh-public-demo-v1";
type Store = Pick<Storage, "getItem" | "setItem" | "removeItem">;
type Proposal = {
  id: string;
  kind: string;
  summary: string;
  note: string | null;
  status: string;
  revision: number;
};
type HistoryItem = {
  id: string;
  track_id: number;
  started_at: string;
  ended_at: string | null;
  listened_seconds: number;
  outcome: string;
};
type State = {
  version: 1;
  preferences: Partial<User>;
  liked: number[];
  saved: number[];
  playback: Omit<PlaybackSnapshot, "queue"> & { queue: number[] };
  listening: ListeningSettings;
  proposals: Proposal[];
  history: HistoryItem[];
};
const durations = [24, 28, 24, 32, 28, 24];
const titles = [
  "مدار آرام · Quiet Orbit",
  "پس از باران · After Rain",
  "خط افق · Horizon",
  "نور نیمه‌شب · Midnight Light",
  "قدم‌های آهسته · Slow Steps",
  "اولین پرتو · First Light",
];

function initialState(): State {
  return {
    version: 1,
    preferences: {},
    liked: [2, 5],
    saved: [1, 3],
    playback: {
      track_id: null,
      queue: [],
      position_seconds: 0,
      volume: 0.45,
      shuffle: false,
      repeat_mode: "off",
      revision: 0,
    },
    listening: {
      history_enabled: false,
      recommendations_enabled: false,
      retention_days: 30,
      private_mode: false,
      private_until: null,
      revision: 0,
    },
    proposals: [],
    history: [],
  };
}

export function createDemoApi(storage: Store | null, base: string) {
  let state = initialState();
  try {
    const saved = JSON.parse(storage?.getItem(key) || "null");
    if (
      saved?.version === 1 &&
      Array.isArray(saved.liked) &&
      Array.isArray(saved.saved) &&
      Array.isArray(saved.playback?.queue) &&
      saved.listening &&
      Array.isArray(saved.proposals) &&
      Array.isArray(saved.history)
    )
      state = saved;
  } catch {}
  const commit = () => {
    try {
      storage?.setItem(key, JSON.stringify(state));
    } catch {}
  };
  const user = (): User => ({
    id: 1,
    access_version: 0,
    name: "مهمان",
    email: "demo@example.invalid",
    is_admin: false,
    role: "listener",
    role_label: "عضو نمایشی",
    permissions: [],
    mfa_enabled: false,
    is_active: true,
    theme: "dark",
    locale: "fa-IR",
    direction: "auto",
    motion: "system",
    time_zone: "Asia/Tehran",
    crossfade_enabled: false,
    crossfade_seconds: 3,
    crossfade_acknowledged: false,
    loudness_normalization: false,
    offline_opt_in: false,
    ...state.preferences,
  });
  const tracks = (): Track[] =>
    titles.map((title, index) => ({
      id: index + 1,
      title,
      artist: "Ahang Studio · صدای مصنوعی",
      album: "Public Demo Sessions",
      revision: 1,
      primary_release_id: null,
      duration_seconds: durations[index],
      stream_url: `${base}audio/sample-${index + 1}.wav`,
      is_liked: state.liked.includes(index + 1),
      artwork_seed: index + 1,
      cover_url: null,
    }));
  const definitions = [
    {
      name: "ساعت‌های آبی",
      description:
        "ریتم‌های آرام برای زمانی که شهر به خواب می‌رود. یک تجربه کوتاه با صداهای مصنوعی.",
      mood: "night",
      ids: [4, 1, 2, 5],
    },
    {
      name: "تمرکز، یک خط جلوتر",
      description: "فضایی خلوت برای فکرکردن، نوشتن و ساختن.",
      mood: "focus",
      ids: [1, 3, 5, 2],
    },
    {
      name: "آرام‌تر از همیشه",
      description: "چند دقیقه مکث، دور از شلوغی روز.",
      mood: "calm",
      ids: [2, 5, 1],
    },
    {
      name: "شروع روشن",
      description: "اولین صداهای یک روز تازه؛ سبک و پرانرژی.",
      mood: "energy",
      ids: [6, 3, 4],
    },
    {
      name: "مسیر خانه",
      description: "ملودی‌های کوتاه برای قدم‌های آهسته.",
      mood: "calm",
      ids: [5, 2, 6, 1],
    },
    {
      name: "فضای بین خطوط",
      description: "شش قطعه نمونه برای کشف جزئیات پلیر.",
      mood: "other",
      ids: [1, 2, 3, 4, 5, 6],
    },
  ] as const;
  const playlists = (): Playlist[] =>
    definitions.map((p, index) => ({
      id: index + 1,
      name: p.name,
      description: p.description,
      mood: p.mood,
      status: "published",
      revision: 1,
      artwork_seed: index + 2,
      cover_url: null,
      tracks_count: p.ids.length,
      is_saved: state.saved.includes(index + 1),
      tracks: p.ids.map((id) => tracks().find((t) => t.id === id)!),
    }));
  const page = (items: unknown[]) => ({
    data: items,
    meta: { current_page: 1, last_page: 1, total: items.length },
    links: {},
  });
  const respond = (payload: unknown, status = 200) =>
    new Response(JSON.stringify(payload), {
      status,
      headers: {
        "Content-Type": "application/json",
        "Cache-Control": "no-store",
      },
    });
  const data = (value: unknown) => respond({ data: value });
  const normalize = (value: string) =>
    value
      .normalize("NFKC")
      .replace(/ي/g, "ی")
      .replace(/ك/g, "ک")
      .toLocaleLowerCase();
  let deviceName = "Browser · Demo";
  const deviceId = "demo-browser";
  const lease = () => ({
    device_id: deviceId,
    generation: 1,
    lease_token: "local-demo-only",
    state: "active",
    revision: 1,
    transfer_target_id: null,
    transfer_expires_at: null,
    lease_expires_at: new Date(Date.now() + 60000).toISOString(),
  });
  const grants = new Map<string, { trackId: number; started: string }>();

  return async (
    path: string,
    method = "GET",
    body: Record<string, unknown> = {},
  ): Promise<Response> => {
    const url = new URL(path, "https://demo.invalid");
    const route = url.pathname;
    const query = normalize(url.searchParams.get("q") || "");
    if (route === "/session" && method === "GET")
      return data({ user: user(), csrf_token: "public-demo-only" });
    if (route === "/stats" && method === "GET")
      return data({ tracks: tracks().length, playlists: playlists().length });
    if (route === "/playlists" && method === "GET")
      return respond(
        page(
          playlists().filter(
            (p) =>
              (!url.searchParams.has("saved") || p.is_saved) &&
              (!url.searchParams.get("mood") ||
                p.mood === url.searchParams.get("mood")) &&
              normalize(`${p.name} ${p.description}`).includes(query),
          ),
        ),
      );
    if (route === "/tracks" && method === "GET")
      return respond(
        page(
          tracks().filter(
            (t) =>
              (!url.searchParams.has("liked") || t.is_liked) &&
              normalize(`${t.title} ${t.artist} ${t.album}`).includes(query),
          ),
        ),
      );
    const playlist = route.match(/^\/playlists\/(\d+)(\/save)?$/);
    if (playlist) {
      const id = Number(playlist[1]);
      const item = playlists().find((p) => p.id === id);
      if (!item)
        return respond({ message: "این فهرست نمونه وجود ندارد." }, 404);
      if (!playlist[2] && method === "GET") return data(item);
      if (playlist[2] && ["PUT", "DELETE"].includes(method)) {
        state.saved = state.saved.filter((value) => value !== id);
        if (method === "PUT") state.saved.push(id);
        commit();
        return data({ is_saved: method === "PUT" });
      }
    }
    const track = route.match(/^\/tracks\/(\d+)\/(like|lyrics)$/);
    if (track) {
      const id = Number(track[1]);
      if (!tracks().some((t) => t.id === id))
        return respond({ message: "این قطعه نمونه وجود ندارد." }, 404);
      if (track[2] === "lyrics" && method === "GET")
        return data({
          format: "lrc",
          source: "manual",
          license_note:
            "Original demo text for synthetic audio; no recorded vocals.",
          body: "[00:00.00]این یک صدای مصنوعی برای نمایش پلیر است\n[00:06.00]چند لحظه مکث، میان صداها\n[00:12.00]ریتمی آرام برای کشف جزئیات\n[00:18.00]آهنگ خط‌کش · نسخه نمایشی",
        });
      if (track[2] === "like" && ["PUT", "DELETE"].includes(method)) {
        state.liked = state.liked.filter((value) => value !== id);
        if (method === "PUT") state.liked.push(id);
        commit();
        return data({ is_liked: method === "PUT" });
      }
    }
    if (route === "/me" && method === "PATCH") {
      if (body.offline_opt_in)
        return respond(
          { message: "دانلود آفلاین در نسخه نمایشی عمومی فعال نیست." },
          403,
        );
      for (const field of [
        "theme",
        "locale",
        "direction",
        "motion",
        "time_zone",
        "crossfade_enabled",
        "crossfade_seconds",
        "crossfade_acknowledged",
        "loudness_normalization",
      ] as const) {
        if (field in body)
          Object.assign(state.preferences, { [field]: body[field] });
      }
      commit();
      return data(user());
    }
    if (route === "/playback") {
      if (method === "GET")
        return data({
          ...state.playback,
          queue: state.playback.queue
            .map((id) => tracks().find((t) => t.id === id))
            .filter(Boolean),
        });
      if (method === "PUT") {
        const b = body as unknown as State["playback"];
        state.playback = {
          track_id: tracks().some((t) => t.id === b.track_id)
            ? b.track_id
            : null,
          queue: Array.isArray(b.queue)
            ? b.queue
                .filter((id) => tracks().some((t) => t.id === id))
                .slice(0, 500)
            : [],
          position_seconds: Math.max(0, Number(b.position_seconds) || 0),
          volume: Math.min(1, Math.max(0, Number(b.volume) || 0)),
          shuffle: !!b.shuffle,
          repeat_mode: ["all", "one"].includes(b.repeat_mode)
            ? b.repeat_mode
            : "off",
          revision: state.playback.revision + 1,
        };
        commit();
        return data({ revision: state.playback.revision });
      }
    }
    if (route === "/playback/devices") {
      const device = {
        id: deviceId,
        device_key: "public-demo-tab",
        name: deviceName,
        user_agent: null,
        revision: 1,
        last_seen_at: new Date().toISOString(),
        revoked_at: null,
        is_active: true,
      };
      if (method === "POST") {
        deviceName = String(body.name || deviceName);
        return data({ ...device, name: deviceName });
      }
      if (method === "GET") return data({ devices: [device], lease: lease() });
    }
    if (
      /^\/playback\/devices\/demo-browser\/(claim|heartbeat)$/.test(route) &&
      method === "POST"
    )
      return data(lease());
    if (route === "/playback/devices/demo-browser/commands" && method === "GET")
      return data({ commands: [], lease: lease() });
    if (route === "/listening/settings") {
      if (method === "GET") return data(state.listening);
      if (method === "PATCH") {
        for (const field of [
          "history_enabled",
          "recommendations_enabled",
        ] as const)
          if (field in body) state.listening[field] = !!body[field];
        if ([7, 30, 90].includes(Number(body.retention_days)))
          state.listening.retention_days = Number(body.retention_days) as
            7 | 30 | 90;
        if ("private_session" in body) {
          state.listening.private_mode = !!body.private_session;
          state.listening.private_until = body.private_session
            ? new Date(Date.now() + 21600000).toISOString()
            : null;
        }
        if (!state.listening.history_enabled) state.history = [];
        state.listening.revision++;
        commit();
        return data(state.listening);
      }
    }
    if (route === "/listening/mix" && method === "GET") {
      const mood = url.searchParams.get("mood");
      const list = mood
        ? playlists().find((p) => p.mood === mood)?.tracks || []
        : tracks();
      return respond({
        basis: "curated",
        data: list.map((t) => ({
          track: t,
          score: 1,
          reasons: [{ code: "curated", weight: 1 }],
        })),
      });
    }
    if (route === "/listening/plays" && method === "POST") {
      if (!state.listening.history_enabled || state.listening.private_mode)
        return data({ recording: false });
      const token = crypto.randomUUID();
      grants.set(token, {
        trackId: Number(body.track_id),
        started: new Date().toISOString(),
      });
      return data({
        recording: true,
        token,
        minimum_seconds: 5,
        expires_at: new Date(Date.now() + 3600000).toISOString(),
      });
    }
    if (route === "/listening/events" && method === "POST") {
      const id = String(body.token);
      const grant = grants.get(id);
      if (
        grant &&
        state.listening.history_enabled &&
        !state.listening.private_mode
      ) {
        state.history = state.history.filter((item) => item.id !== id);
        state.history.unshift({
          id,
          track_id: grant.trackId,
          started_at: grant.started,
          ended_at: body.kind === "progress" ? null : new Date().toISOString(),
          listened_seconds: Number(body.listened_seconds) || 0,
          outcome: body.kind === "progress" ? "started" : String(body.kind),
        });
        state.history = state.history.slice(0, 100);
        commit();
      }
      return data({ recorded: !!grant });
    }
    if (route.startsWith("/listening/history")) {
      if (method === "GET")
        return respond(
          page(
            state.history.map((item) => ({
              ...item,
              track: tracks().find((t) => t.id === item.track_id),
            })),
          ),
        );
      if (method === "DELETE") {
        const id = route.split("/")[3];
        state.history = id
          ? state.history.filter((item) => item.id !== id)
          : [];
        state.listening.revision++;
        commit();
        return data(state.listening);
      }
    }
    if (route === "/proposals") {
      if (method === "GET") return data(state.proposals);
      if (method === "POST") {
        const title = String(body.title || "")
          .trim()
          .slice(0, 255);
        if (!tracks().some((t) => t.id === Number(body.track_id)) || !title)
          return respond(
            { message: "شناسه قطعه نمونه و عنوان پیشنهادی معتبر وارد کنید." },
            422,
          );
        const proposal: Proposal = {
          id: crypto.randomUUID(),
          kind: "track_metadata",
          summary: title,
          note: String(body.note || "").slice(0, 2000) || null,
          status: "pending",
          revision: 1,
        };
        state.proposals = [proposal, ...state.proposals].slice(0, 50);
        commit();
        return data(proposal);
      }
    }
    if (route.startsWith("/proposals/") && method === "DELETE") {
      const proposal = state.proposals.find(
        (p) => p.id === route.split("/")[2],
      );
      if (proposal) {
        proposal.status = "withdrawn";
        proposal.revision++;
        commit();
        return data(proposal);
      }
    }
    return respond(
      {
        message:
          "این قابلیت به سرور اصلی نیاز دارد و در نسخه نمایشی عمومی فعال نیست. This action requires the full application server.",
        code: "demo_server_required",
      },
      403,
    );
  };
}

export function installDemoTransport() {
  let storage: Storage | null = null;
  try {
    storage = window.localStorage;
  } catch {}
  const api = createDemoApi(storage, import.meta.env.BASE_URL);
  const nativeFetch = window.fetch.bind(window);
  window.fetch = async (input, init) => {
    const request = input instanceof Request ? input : null;
    const url = new URL(request?.url || String(input), window.location.href);
    if (
      url.origin !== window.location.origin ||
      !/^\/api\/v1(?:\/|$)/.test(url.pathname)
    )
      return nativeFetch(input, init);
    const signal = init?.signal || request?.signal;
    if (signal?.aborted) throw new DOMException("Aborted", "AbortError");
    let body: Record<string, unknown> = {};
    try {
      const raw =
        typeof init?.body === "string"
          ? init.body
          : request
            ? await request.clone().text()
            : "";
      if (raw) body = JSON.parse(raw);
    } catch {
      return new Response(JSON.stringify({ message: "Invalid demo input" }), {
        status: 422,
        headers: { "Content-Type": "application/json" },
      });
    }
    return api(
      url.pathname.replace(/^\/api\/v1/, "") + url.search,
      (init?.method || request?.method || "GET").toUpperCase(),
      body,
    );
  };
}

export function resetDemo() {
  try {
    window.localStorage.removeItem(key);
  } catch {}
  window.location.reload();
}
