import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import tailwindcss from "@tailwindcss/vite";

export default defineConfig({
  plugins: [react(), tailwindcss()],
  server: {
    port: 2087,
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
