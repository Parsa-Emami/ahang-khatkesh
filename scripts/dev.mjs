import { spawn } from "node:child_process";
import process from "node:process";
import { existsSync } from "node:fs";
if (existsSync(".env")) process.loadEnvFile(".env");
const args = process.argv.slice(2);
const portIndex = args.indexOf("--port");
const port = portIndex >= 0 ? args[portIndex + 1] : process.env.PORT || "5173";

const children = [];
const php = process.env.PHP_BINARY || "php";
const franken = process.env.FRANKENPHP_BINARY;
function run(command, args) {
    const child = spawn(command, args, { stdio: "inherit", env: process.env });
    children.push(child);
    child.on("error", (error) => {
        console.error(error.message);
        stop(1);
    });
    child.on("exit", (code) => {
        if (!stopping) stop(code || 0);
    });
}
let stopping = false;
function stop(code = 0) {
    if (stopping) return;
    stopping = true;
    for (const child of children) child.kill("SIGTERM");
    process.exitCode = code;
}
process.on("SIGINT", () => stop());
process.on("SIGTERM", () => stop());
if (franken)
    run(franken, [
        "php-server",
        "--listen",
        "127.0.0.1:8000",
        "--root",
        "public",
    ]);
else
    run(php, [
        "artisan",
        "serve",
        "--host=127.0.0.1",
        "--port=8000",
        "--no-reload",
    ]);
run(php, [
    "artisan",
    "queue:work",
    "database",
    "--sleep=1",
    "--tries=3",
    "--timeout=120",
]);
run(php, [
    "artisan",
    "queue:work",
    "media_database",
    "--queue=media",
    "--sleep=1",
    "--tries=1",
    "--timeout=800",
]);
run(process.execPath, [
    "node_modules/vite/bin/vite.js",
    "--host",
    "0.0.0.0",
    "--port",
    port,
]);
