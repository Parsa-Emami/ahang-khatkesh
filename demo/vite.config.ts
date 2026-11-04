import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import { fileURLToPath } from "node:url";

export default defineConfig({
  root: fileURLToPath(new URL(".", import.meta.url)),
  base: process.env.DEMO_BASE_PATH || "/ahang-khatkesh/",
  publicDir: false,
  plugins: [react()],
  build: {
    outDir: fileURLToPath(new URL("../dist-demo", import.meta.url)),
    emptyOutDir: true,
    sourcemap: false,
  },
});
