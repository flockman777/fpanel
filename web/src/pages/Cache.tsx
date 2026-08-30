import { askConfirm } from "../askConfirm";
import { Database, RefreshCw, Trash2, Zap } from "lucide-react";
import { useCallback, useEffect, useState } from "react";
import { api } from "../App";

interface CacheInfo {
  connected: boolean;
  version?: string | null;
  uptime_seconds?: number | null;
  used_memory?: number | null;
  used_memory_human?: string | null;
  peak_memory_human?: string | null;
  maxmemory?: number | null;
  maxmemory_human?: string | null;
  maxmemory_policy?: string | null;
  connected_clients?: number | null;
  total_connections?: number | null;
  total_commands?: number | null;
  total_keys?: number | null;
}

const fmtUptime = (sec?: number | null) => {
  if (!sec) return "-";
  const d = Math.floor(sec / 86400);
  const h = Math.floor((sec % 86400) / 3600);
  const m = Math.floor((sec % 3600) / 60);
  if (d > 0) return `${d}d ${h}h`;
  if (h > 0) return `${h}h ${m}m`;
  return `${m}m`;
};

export default function CacheManager() {
  const [info, setInfo] = useState<CacheInfo | null>(null);
  const [busy, setBusy] = useState(false);
  const [mb, setMb] = useState("128");
  const [error, setError] = useState("");

  const load = useCallback(async () => {
    setError("");
    try {
      const res = await api<CacheInfo>("/cache/info");
      setInfo(res);
      if (res.maxmemory && res.maxmemory > 0) setMb(String(Math.round(res.maxmemory / 1048576)));
    } catch (e: any) {
      setError(String(e.message || e));
    }
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  const flush = async () => {
    if (!(await askConfirm("Flush ALL cached keys in Valkey? This cannot be undone."))) return;
    setBusy(true);
    try {
      await api("/cache/flush", { method: "POST" });
      load();
    } catch (e: any) {
      setError(String(e.message || e));
    } finally {
      setBusy(false);
    }
  };

  const applyMax = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError("");
    try {
      await api("/cache/maxmemory", {
        method: "POST",
        body: JSON.stringify({ mb: Number(mb) }),
      });
      load();
    } catch (err: any) {
      setError(String(err.message || err));
    } finally {
      setBusy(false);
    }
  };

  const Card = ({ label, value, sub }: { label: string; value: string; sub?: string }) => (
    <div className="rounded-xl border border-gray-200 bg-white p-4">
      <div className="text-xs font-medium uppercase tracking-wide text-gray-500">{label}</div>
      <div className="mt-1 text-xl font-semibold text-gray-800">{value}</div>
      {sub && <div className="text-xs text-gray-400">{sub}</div>}
    </div>
  );

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="flex items-center gap-2 text-xl font-semibold text-gray-800">
            <Zap className="h-5 w-5 text-brand-600" /> Cache Manager
          </h2>
          <p className="text-sm text-gray-500">
            Valkey in-memory cache daemon (127.0.0.1:6379)
          </p>
        </div>
        <div className="flex items-center gap-2">
          <button
            onClick={load}
            disabled={busy}
            className="flex items-center gap-2 rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-600 transition hover:bg-gray-50 disabled:opacity-60"
          >
            <RefreshCw className={`h-4 w-4 ${busy ? "animate-spin" : ""}`} />
            Refresh
          </button>
          <button
            onClick={flush}
            disabled={busy || !info?.connected}
            className="flex items-center gap-2 rounded-lg bg-red-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-red-700 disabled:opacity-50"
          >
            <Trash2 className="h-4 w-4" />
            Flush Cache
          </button>
        </div>
      </div>

      {error && (
        <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
          {error}
        </div>
      )}

      {!info ? (
        <section className="rounded-xl border border-gray-200 bg-white p-10 text-center text-sm text-gray-400">
          Loading cache status...
        </section>
      ) : !info.connected ? (
        <section className="rounded-xl border border-amber-200 bg-amber-50 p-10 text-center">
          <div className="text-3xl">🥶</div>
          <div className="mt-2 font-semibold text-amber-800">Valkey is not reachable</div>
          <p className="mt-1 text-sm text-amber-700">
            The cache daemon is not running on 127.0.0.1:6379. Check{" "}
            <span className="font-mono">systemctl status valkey</span>.
          </p>
        </section>
      ) : (
        <>
          <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
            <Card label="Version" value={info.version || "-"} />
            <Card label="Uptime" value={fmtUptime(info.uptime_seconds)} />
            <Card
              label="Memory Used"
              value={info.used_memory_human || "-"}
              sub={`peak ${info.peak_memory_human || "-"}`}
            />
            <Card
              label="Memory Limit"
              value={info.maxmemory_human || "-"}
              sub={`policy ${info.maxmemory_policy || "-"}`}
            />
            <Card label="Keys" value={String(info.total_keys ?? "-")} />
            <Card label="Clients" value={String(info.connected_clients ?? "-")} />
            <Card label="Connections" value={String(info.total_connections ?? "-")} />
            <Card label="Commands" value={String(info.total_commands ?? "-")} />
          </div>

          <section className="rounded-xl border border-gray-200 bg-white p-5">
            <div className="mb-1 flex items-center gap-2 text-sm font-semibold text-gray-700">
              <Database className="h-4 w-4 text-brand-600" /> Max Memory Limit
            </div>
            <p className="mb-4 text-sm text-gray-500">
              Set how much RAM Valkey may use for cached keys before evicting old entries.
            </p>
            <form onSubmit={applyMax} className="flex flex-wrap items-end gap-3">
              <div>
                <label className="mb-1.5 block text-sm font-medium text-gray-700">MB</label>
                <input
                  type="number"
                  min={8}
                  max={2048}
                  value={mb}
                  onChange={(e) => setMb(e.target.value)}
                  className="w-32 rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none"
                />
              </div>
              <button
                type="submit"
                disabled={busy}
                className="rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-700 disabled:opacity-60"
              >
                {busy ? "Saving..." : "Apply"}
              </button>
            </form>
          </section>
        </>
      )}
    </div>
  );
}