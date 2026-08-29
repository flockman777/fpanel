import { Braces, Pencil, RotateCcw, Layers } from "lucide-react";
import { useEffect, useRef, useState } from "react";
import { api } from "../App";

interface Domain {
  id: number;
  account_id: number;
  username: string;
  name: string;
  kind: string;
  status: string;
}

interface PhpRow {
  id: number;
  account_id: number;
  domain_id: number;
  domain: string;
  version: string;
  ini: Record<string, string>;
  handler: string;
}

interface PhpResp {
  rows: PhpRow[];
  versions: string[];
}

const INI_DEFS: { key: string; label: string; type: "size" | "int" | "bool"; def: string }[] = [
  { key: "memory_limit", label: "Memory limit", type: "size", def: "128M" },
  { key: "upload_max_filesize", label: "Upload max size", type: "size", def: "32M" },
  { key: "post_max_size", label: "POST max size", type: "size", def: "32M" },
  { key: "realpath_cache_size", label: "Realpath cache", type: "size", def: "4M" },
  { key: "max_execution_time", label: "Max execution time (s)", type: "int", def: "60" },
  { key: "max_input_time", label: "Max input time (s)", type: "int", def: "60" },
  { key: "max_input_vars", label: "Max input vars", type: "int", def: "1000" },
  { key: "opcache.enable", label: "OPcache", type: "bool", def: "Off" },
];

export default function Php() {
  const [rows, setRows] = useState<PhpRow[]>([]);
  const [versions, setVersions] = useState<string[]>(["system"]);
  const [domains, setDomains] = useState<Domain[]>([]);
  const [toast, setToast] = useState<{ type: "ok" | "err"; msg: string } | null>(null);
  const toastTimer = useRef<number>();
  const [editing, setEditing] = useState<PhpRow | null>(null);
  const [ini, setIni] = useState<Record<string, string>>({});
  const [version, setVersion] = useState("system");
  const [handler, setHandler] = useState("system");
  const [busy, setBusy] = useState(false);

  const notify = (msg: string, type: "ok" | "err" = "ok") => {
    setToast({ type, msg });
    window.clearTimeout(toastTimer.current);
    toastTimer.current = window.setTimeout(() => setToast(null), 4000);
  };

  const load = async () => {
    try {
      const r = await api<PhpResp>("/client/php");
      setRows(r.rows);
      if (r.versions.length) setVersions(["system", ...r.versions]);
    } catch (e: any) {
      notify(String(e.message || e), "err");
    }
  };

  useEffect(() => {
    load();
    api<Domain[]>("/client/domains")
      .then(setDomains)
      .catch((e: any) => notify(String(e.message || e), "err"));
  }, []);

  const open = (row: PhpRow | null) => {
    setEditing(row);
    setVersion(row?.version || "system");
    setHandler(row?.handler || "system");
    setIni(row?.ini || {});
  };

  const save = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!editing) return;
    setBusy(true);
    try {
      const clean: Record<string, string> = {};
      for (const d of INI_DEFS) {
        const v = ini[d.key];
        if (v !== undefined && v !== "") clean[d.key] = v;
      }
      await api("/client/php", {
        method: "PUT",
        body: JSON.stringify({ domain_id: editing.domain_id, version, handler, ini: clean }),
      });
      notify("PHP configuration saved");
      setEditing(null);
      load();
    } catch (err: any) {
      notify(String(err.message || err), "err");
    } finally {
      setBusy(false);
    }
  };

  const reset = async (r: PhpRow) => {
    if (!confirm(`Reset PHP for "${r.domain}" to server defaults?`)) return;
    try {
      await api(`/client/php/${r.domain_id}`, { method: "DELETE" });
      notify("Reset to server defaults");
      load();
    } catch (e: any) {
      notify(String(e.message || e), "err");
    }
  };

  const cfgCount = (r: PhpRow) => Object.keys(r.ini || {}).length;
  const iniSummary = (r: PhpRow) =>
    Object.entries(r.ini || {})
      .map(([k, v]) => `${k}=${v}`)
      .join(", ");

  const base = "w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none";
  const btn = "flex items-center gap-2 rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-brand-700";
  const btnGhost = "flex items-center gap-2 rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50";

  const merged = domains.map((d) => {
    const r = rows.find((x) => x.domain_id === d.id);
    return r || ({ id: 0, account_id: d.account_id, domain_id: d.id, domain: d.name, version: "system", ini: {}, handler: "system" } as PhpRow);
  });

  return (
    <div className="space-y-6">
      {toast && (
        <div
          className={`fixed top-4 right-4 z-[60] rounded-lg px-4 py-3 text-sm font-medium text-white shadow-lg ${
            toast.type === "ok" ? "bg-green-600" : "bg-red-600"
          }`}
        >
          {toast.msg}
        </div>
      )}

      <div>
        <h2 className="text-xl font-semibold text-gray-800">MultiPHP</h2>
        <p className="text-sm text-gray-500">
          Choose a PHP version and runtime directives per domain
        </p>
      </div>

      <section className="rounded-xl border border-gray-200 bg-white p-5">
        <div className="mb-3 flex flex-wrap items-center gap-3">
          <span className="font-semibold text-gray-800">PHP per domain</span>
          <span className="rounded-full bg-brand-50 px-3 py-1 text-xs text-brand-700">{versions.join(", ")}</span>
        </div>

        {merged.length === 0 ? (
          <p className="text-sm text-gray-500">No domains yet.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead>
                <tr className="border-b border-gray-200 text-xs uppercase tracking-wider text-gray-500">
                  <th className="px-3 py-2">Domain</th>
                  <th className="px-3 py-2">PHP version</th>
                  <th className="px-3 py-2">Handler</th>
                  <th className="px-3 py-2">Directives</th>
                  <th className="px-3 py-2 text-right">Actions</th>
                </tr>
              </thead>
              <tbody>
                {merged.map((r) => (
                  <tr key={r.domain_id} className="border-b border-gray-100">
                    <td className="px-3 py-2.5 font-medium text-gray-800">{r.domain}</td>
                    <td className="px-3 py-2.5">
                      <span className={`rounded-full px-2.5 py-1 text-xs font-medium ${r.version === "system" ? "bg-gray-100 text-gray-500" : "bg-indigo-50 text-indigo-700"}`}>
                        {r.version === "system" ? "Server default (" + versions.filter((v) => v !== "system").join("") + ")" : "PHP " + r.version}
                      </span>
                    </td>
                    <td className="px-3 py-2.5 text-xs text-gray-600">
                      {r.handler === "system" ? "Apache/CGI" : r.handler.toUpperCase()}
                    </td>
                    <td className="px-3 py-2.5">
                      {cfgCount(r) === 0 ? (
                        <span className="text-xs text-gray-400">none</span>
                      ) : (
                        <span className="max-w-[18rem] truncate font-mono text-xs text-gray-600" title={iniSummary(r)}>
                          {iniSummary(r)}
                        </span>
                      )}
                    </td>
                    <td className="px-3 py-2.5">
                      <div className="flex justify-end gap-1.5">
                        <button onClick={() => open(r)} className={btnGhost}>
                          <Pencil className="h-3.5 w-3.5" /> Configure
                        </button>
                        {r.version !== "system" && (
                          <button
                            onClick={() => reset(r)}
                            className="rounded-lg p-1.5 text-gray-500 transition hover:bg-red-50 hover:text-red-600"
                            title="Reset to server default"
                          >
                            <RotateCcw className="h-4 w-4" />
                          </button>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

      {editing && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
          <form onSubmit={save} className="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl">
            <div className="mb-4 flex items-center gap-2">
              <Layers className="h-4 w-4 text-brand-600" />
              <h3 className="text-lg font-semibold text-gray-800">PHP · {editing.domain}</h3>
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div>
                <label className="mb-1 block text-xs font-medium text-gray-600">PHP version</label>
                <select value={version} onChange={(e) => setVersion(e.target.value)} className={base}>
                  {versions.map((v) => (
                    <option key={v} value={v}>
                      {v === "system" ? "Server default" : "PHP " + v}
                    </option>
                  ))}
                </select>
              </div>
              <div>
                <label className="mb-1 block text-xs font-medium text-gray-600">Handler</label>
                <select value={handler} onChange={(e) => setHandler(e.target.value)} className={base}>
                  <option value="system">Apache/CGI</option>
                  <option value="fpm">PHP-FPM</option>
                </select>
              </div>
            </div>
            <div className="mt-4 space-y-3">
              <div className="flex items-center gap-2 text-xs text-gray-500">
                <Braces className="h-3.5 w-3.5" /> Runtime directives (leave empty to keep server default)
              </div>
              {INI_DEFS.map((d) => (
                <div key={d.key} className="grid grid-cols-2 items-center gap-3">
                  <label className="text-xs font-medium text-gray-600">{d.label}</label>
                  {d.type === "bool" ? (
                    <select
                      value={ini[d.key] ?? ""}
                      onChange={(e) => setIni({ ...ini, [d.key]: e.target.value })}
                      className={base}
                    >
                      <option value="">Server default</option>
                      <option value="On">On</option>
                      <option value="Off">Off</option>
                    </select>
                  ) : (
                    <input
                      value={ini[d.key] ?? ""}
                      onChange={(e) => setIni({ ...ini, [d.key]: e.target.value })}
                      className={base}
                      placeholder={d.type === "size" ? d.def : d.def}
                    />
                  )}
                </div>
              ))}
            </div>
            <div className="mt-5 flex justify-end gap-2">
              <button type="button" onClick={() => setEditing(null)} className={btnGhost}>
                Cancel
              </button>
              <button disabled={busy} className={btn + " disabled:opacity-60"}>
                {busy ? "Saving..." : "Save configuration"}
              </button>
            </div>
          </form>
        </div>
      )}
    </div>
  );
}