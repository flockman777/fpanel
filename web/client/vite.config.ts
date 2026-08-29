import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import tailwindcss from "@tailwindcss/vite";

export default defineConfig({
  root: __dirname,
  plugins: [react(), tailwindcss()],
  server: {
    port: 2083,
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