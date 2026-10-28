import { randomBytes, randomUUID } from "node:crypto";
import { spawn } from "node:child_process";
const child = spawn(
    process.execPath,
    ["node_modules/@playwright/test/cli.js", "test", ...process.argv.slice(2)],
    {
        stdio: "inherit",
        env: {
            ...process.env,
            AHANG_E2E: "1",
            AHANG_E2E_RUN_ID: randomUUID(),
            AHANG_E2E_PASSWORD: randomBytes(32).toString("base64url"),
        },
    },
);
child.on("exit", (code) => process.exit(code ?? 1));
