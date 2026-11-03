export const SHELL_CACHE = "ak-shell-v1";

export function shellWorkerSource(version: string): string {
    return `/* ahang-khatkesh public shell SW ${version} */
const CACHE = '${SHELL_CACHE}-${version}';
const PRECACHE = ['/', '/favicon.svg', '/preferences.js'];
self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(PRECACHE)).then(() => self.skipWaiting()));
});
self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(keys.filter((key) => key.startsWith('${SHELL_CACHE}-') && key !== CACHE).map((key) => caches.delete(key)));
    await self.clients.claim();
  })());
});
self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);
  if (url.origin !== self.location.origin) return;
  if (url.pathname.startsWith('/api/') || url.pathname.includes('/stream') || event.request.method !== 'GET') {
    return;
  }
  if (url.pathname.startsWith('/build/')) {
    event.respondWith(caches.open(CACHE).then(async (cache) => {
      const cached = await cache.match(event.request);
      if (cached) return cached;
      const response = await fetch(event.request);
      if (response.ok) cache.put(event.request, response.clone());
      return response;
    }));
    return;
  }
  if (PRECACHE.includes(url.pathname) || url.pathname === '/') {
    event.respondWith(caches.open(CACHE).then(async (cache) => {
      try {
        const response = await fetch(event.request);
        if (response.ok) cache.put(event.request, response.clone());
        return response;
      } catch {
        const cached = await cache.match(event.request);
        if (cached) return cached;
        throw new Error('offline');
      }
    }));
  }
});
`;
}

export async function registerShellWorker(version: string): Promise<void> {
    if (!("serviceWorker" in navigator)) return;
    const source = shellWorkerSource(version);
    const blob = new Blob([source], { type: "text/javascript" });
    const url = URL.createObjectURL(blob);
    try {
        await navigator.serviceWorker.register(url, { scope: "/" });
    } finally {
        URL.revokeObjectURL(url);
    }
}

export function shouldCacheUrl(pathname: string): boolean {
    if (pathname.startsWith("/api/")) return false;
    if (pathname.includes("/stream")) return false;
    return (
        pathname.startsWith("/build/") ||
        pathname === "/" ||
        pathname === "/favicon.svg" ||
        pathname === "/preferences.js"
    );
}
