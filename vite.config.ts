import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import laravel from "laravel-vite-plugin";

export default defineConfig(({ command }) => ({
    plugins: [
        react(),
        command === "build" ? laravel({ input: ["resources/js/main.tsx"], refresh: true }) : undefined,
    ],
    server: {
        host: "0.0.0.0",
        port: Number(process.env.PORT || 5173),
        strictPort: true,
        allowedHosts: true,
        proxy: {
            "/api": { target: "http://127.0.0.1:8000", changeOrigin: false },
            "/up": "http://127.0.0.1:8000",
        },
    },
}));
