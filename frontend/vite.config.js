import { defineConfig } from "vite";
export default defineConfig({
  server: {
    port: 5173,
    proxy: { "/api": process.env.API_PROXY || "http://localhost:8000" },
  },
});
