/* Generate assets/js/features.js dari web/mockup/data/features.json (sumber data tunggal) */
import { readFileSync, writeFileSync } from "node:fs";
const src = JSON.parse(readFileSync("web/mockup/data/features.json", "utf8"));
const out = `/* DIBUAT OTOMATIS oleh tools/build-features.mjs - jangan diedit manual */
window.FPANEL_CATEGORIES = ${JSON.stringify(src.categories, null, 2)};
window.FPANEL_FEATURES = ${JSON.stringify(src.features, null, 2)};
`;
writeFileSync("web/mockup/assets/js/features.js", out);
console.log("features.js ditulis:", src.features.length, "fitur,", src.categories.length, "kategori");
