import { spawn, spawnSync } from "node:child_process";
import { rmSync } from "node:fs";
import { resolve } from "node:path";
if (process.env.AHANG_E2E !== "1")
    throw new Error("Start browser tests through npm run test:browser.");
const runId = process.env.AHANG_E2E_RUN_ID;
if (!runId || !/^[a-f0-9-]{36}$/.test(runId))
    throw new Error("Isolated browser run id required.");
const runRoot = resolve(`storage/framework/testing/browser-${runId}`);
const env = {
    ...process.env,
    APP_ENV: "testing",
    APP_DEBUG: "false",
    APP_URL: "http://127.0.0.1:8000",
    DB_CONNECTION: "sqlite",
    DB_URL: "",
    DB_DATABASE: resolve(runRoot, "database.sqlite"),
    MUSIC_MEDIA_ROOT: resolve(runRoot, "media"),
    SESSION_DRIVER: "database",
    SESSION_SECURE_COOKIE: "false",
    CACHE_STORE: "database",
    QUEUE_CONNECTION: "database",
    BCRYPT_ROUNDS: "4",
};
const php = process.env.AHANG_PHP || "php";
const prepared = spawnSync(php, ["tests/Browser/prepare.php"], {
    env,
    encoding: "utf8",
});
if (
    prepared.status !== 0 ||
    !prepared.stdout.includes("Isolated browser fixtures prepared.")
) {
    process.stderr.write(prepared.stdout || "");
    process.stderr.write(prepared.stderr || "");
    process.exit(1);
}
const child = process.env.AHANG_FRANKENPHP
    ? spawn(
          process.env.AHANG_FRANKENPHP,
          ["php-server", "--listen", "127.0.0.1:8000", "--root", "public"],
          { env: { ...env, APP_ENV: "local" }, stdio: "inherit" },
      )
    : spawn(
          php,
          [
              "artisan",
              "serve",
              "--host=127.0.0.1",
              "--port=8000",
              "--no-reload",
          ],
          { env: { ...env, APP_ENV: "local" }, stdio: "inherit" },
      );
for (const signal of ["SIGTERM", "SIGINT"])
    process.on(signal, () => child.kill(signal));
child.on("exit", (code) => {
    rmSync(runRoot, { recursive: true, force: true });
    process.exit(code ?? 1);
});
