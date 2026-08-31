import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import tailwindcss from "@tailwindcss/vite";

export default defineConfig({
  root: __dirname,
  plugins: [react(), tailwindcss()],
  server: {
    host: true,
    port: 2083,
    allowedHosts: true,
    proxy: {
      "/s/": {
        target: "http://localhost:8181",
        changeOrigin: true,
      },
      "/api/": {
        target: "http://localhost:8181",
        changeOrigin: true,
      },
    },
  },
});