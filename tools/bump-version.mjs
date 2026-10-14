import { writeFileSync, readFileSync } from "node:fs";
const f = "web/mockup/assets/VERSION";
const cur = readFileSync(f, "utf8").trim();
const next = String(Date.now());
writeFileSync(f, next + "\n");
console.log("VERSION:", cur || "-", "->", next);
