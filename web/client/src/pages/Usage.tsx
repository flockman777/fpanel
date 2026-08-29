import { Activity, BarChart3, FileText, RefreshCw } from "lucide-react";
import { useEffect, useRef, useState } from "react";
import { api } from "../App";

interface Usage {
  account_id: number;
  username: string;
  disk_bytes: number;
  bandwidth_bytes: number;
  access_log_bytes: number;
  error_log_bytes: number;
  domains: number;
  databases: number;
}

interface LogSummary {
  domain: string;
  access_size: number;
  error_size: number;
  access_lines: number;
  error_lines: number;
  bandwidth: number;
  last_access: string | null;
}

const fmtBytes = (n: number) => {
  if (n >= 1 << 30) return `${(n / (1 << 30)).toFixed(2)} GB`;
  if (n >= 1 << 20) return `${(n / (1 << 20)).toFixed(1)} MB`;
  if (n >= 1 << 10) return `${(n / (1 << 10)).toFixed(1)} KB`;
  return `${n} B`;
};

export default function Usage() {
  const [usage, setUsage] = useState<Usage | null>(null);
  const [logs, setLogs] = useState<LogSummary[]>([]);
  const [domain, setDomain] = useState("");
  const [kind, setKind] = useState<"access" | "error">("access");
  const [lines, setLines] = useState<string[]>([]);
  const [busy, setBusy] = useState(false);
  const [toast, setToast] = useState<{ type: "ok" | "err"; msg: string } | null>(null);
  const toastTimer = useRef<number>();

  const notify = (msg: string, type: "ok" | "err" = "ok") => {
    setToast({ type, msg });
    window.clearTimeout(toastTimer.current);
    toastTimer.current = window.setTimeout(() => setToast(null), 4000);
  };

  const load = async () => {
    try {
      const [u, l] = await Promise.all([api<Usage>("/client/stats"), api<LogSummary[]>("/client/logs")]);
      setUsage(u);
      setLogs(l);
    } catch (e: any) {
      notify(String(e.message || e), "err");
    }
  };

  useEffect(() => {
    load();
  }, []);

  const view = async (d?: string, k?: "access" | "error") => {
    const dd = d || domain || logs[0]?.domain;
    const kk = k || kind;
    if (!dd) return notify("No domain available", "err");
    setDomain(dd);
    setKind(kk);
    setBusy(true);
    try {
      const r = await api<{ lines: string[] }>(`/client/logs/${kk}/${dd}?lines=200`);
      setLines(r.lines);
    } catch (e: any) {
      setLines([]);
      notify(String(e.message || e), "err");
    } finally {
      setBusy(false);
    }
  };

  useEffect(() => {
    if (logs.length > 0) view(logs[0].domain);
  }, [logs.length > 0]);

  return (
    <div className="space-y-6">
      {toast && (
        <div className={`fixed top-4 right-4 z-[60] rounded-lg px-4 py-3 text-sm font-medium text-white shadow-lg ${toast.type === "ok" ? "bg-green-600" : "bg-red-600"}`}>
          {toast.msg}
        </div>
      )}

      <div className="flex items-center justify-between">
        <div>
          <h2 className="flex items-center gap-2 text-xl font-semibold text-gray-800">
            <BarChart3 className="h-5 w-5 text-brand-600" /> Usage & Logs
          </h2>
          <p className="text-sm text-gray-500">Resource usage and access/error logs for your account</p>
        </div>
        <button onClick={load} className="flex items-center gap-2 rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-600 transition hover:bg-gray-50">
          <RefreshCw className="h-4 w-4" /> Refresh
        </button>
      </div>

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div className="rounded-xl border border-gray-200 bg-white p-5">
          <p className="text-xs uppercase tracking-wide text-gray-400">Disk Used</p>
          <p className="mt-2 text-2xl font-bold text-gray-800">{usage ? fmtBytes(usage.disk_bytes) : "-"}</p>
        </div>
        <div className="rounded-xl border border-gray-200 bg-white p-5">
          <p className="text-xs uppercase tracking-wide text-gray-400">Bandwidth</p>
          <p className="mt-2 text-2xl font-bold text-gray-800">{usage ? fmtBytes(usage.bandwidth_bytes) : "-"}</p>
        </div>
        <div className="rounded-xl border border-gray-200 bg-white p-5">
          <p className="text-xs uppercase tracking-wide text-gray-400">Domains</p>
          <p className="mt-2 text-2xl font-bold text-gray-800">{usage?.domains ?? "-"}</p>
        </div>
        <div className="rounded-xl border border-gray-200 bg-white p-5">
          <p className="text-xs uppercase tracking-wide text-gray-400">Databases</p>
          <p className="mt-2 text-2xl font-bold text-gray-800">{usage?.databases ?? "-"}</p>
        </div>
      </div>

      <section className="rounded-xl border border-gray-200 bg-white p-5">
        <h3 className="mb-4 flex items-center gap-2 text-sm font-semibold text-gray-700">
          <Activity className="h-4 w-4 text-brand-600" /> Log Details
        </h3>
        <div className="flex flex-wrap items-center gap-3">
          <select value={domain} onChange={(e) => view(e.target.value)} className="w-64 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none">
            {logs.map((l) => (
              <option key={l.domain} value={l.domain}>
                {l.domain}
              </option>
            ))}
          </select>
          <div className="flex overflow-hidden rounded-lg border border-gray-300">
            <button onClick={() => view(domain, "access")} className={`px-4 py-2 text-sm font-medium ${kind === "access" ? "bg-brand-600 text-white" : "bg-white text-gray-600 hover:bg-gray-50"}`}>
              Access
            </button>
            <button onClick={() => view(domain, "error")} className={`px-4 py-2 text-sm font-medium ${kind === "error" ? "bg-brand-600 text-white" : "bg-white text-gray-600 hover:bg-gray-50"}`}>
              Error
            </button>
          </div>
          <button onClick={() => view()} disabled={busy} className="flex items-center gap-2 rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-600 transition hover:bg-gray-50 disabled:opacity-60">
            <RefreshCw className="h-4 w-4" /> {busy ? "Loading..." : "Reload"}
          </button>
        </div>

        <div className="mt-4 overflow-hidden rounded-lg border border-gray-200">
          <pre className="max-h-[30rem] overflow-auto bg-slate-900 p-4 text-xs leading-relaxed text-slate-100">
            {lines.length === 0 ? (
              <span className="text-slate-500">
                {kind === "access"
                  ? "No access log lines yet. Requests to your site will be recorded here."
                  : "No error log lines yet. PHP errors/warnings will appear here."}
              </span>
            ) : (
              lines.map((l, i) => <div key={i}>{l}</div>)
            )}
          </pre>
        </div>
      </section>

      <section className="rounded-xl border border-gray-200 bg-white p-5">
        <h3 className="mb-3 flex items-center gap-2 text-sm font-semibold text-gray-700">
          <FileText className="h-4 w-4 text-brand-600" /> Current Log Sizes
        </h3>
        <table className="w-full text-left text-sm">
          <thead className="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
            <tr>
              <th className="px-4 py-2.5">Domain</th>
              <th className="px-4 py-2.5">Access</th>
              <th className="px-4 py-2.5">Error</th>
              <th className="px-4 py-2.5">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100">
            {logs.map((l) => (
              <tr key={l.domain} className="hover:bg-gray-50">
                <td className="px-4 py-3 font-medium text-gray-800">{l.domain}</td>
                <td className="px-4 py-3 text-gray-600">{fmtBytes(l.access_size)}</td>
                <td className="px-4 py-3 text-gray-600">{fmtBytes(l.error_size)}</td>
                <td className="px-4 py-3">
                  <button onClick={() => view(l.domain, "access")} className="text-xs font-medium text-brand-600 hover:underline">
                    view access log
                  </button>
                </td>
              </tr>
            ))}
            {logs.length === 0 && (
              <tr>
                <td colSpan={4} className="px-4 py-8 text-center text-gray-400">
                  No domains.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </section>
    </div>
  );
}