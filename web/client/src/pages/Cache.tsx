import { Database, RefreshCw, Zap } from "lucide-react";
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

export default function Cache() {
  const [info, setInfo] = useState<CacheInfo | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  const load = useCallback(async () => {
    setError("");
    try {
      setInfo(await api<CacheInfo>("/client/cache/info"));
    } catch (e: any) {
      setError(String(e.message || e));
    }
  }, []);

  useEffect(() => {
    load();
  }, [load]);

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
            Status mesin cache shared (Valkey 127.0.0.1:6379). Setting hanya oleh admin.
          </p>
        </div>
        <button
          onClick={load}
          disabled={busy}
          className="flex items-center gap-2 rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-600 transition hover:bg-gray-50 disabled:opacity-60"
        >
          <RefreshCw className={`h-4 w-4 ${busy ? "animate-spin" : ""}`} />
          Refresh
        </button>
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
            Hubungi administrator untuk menyalakan daemon cache.
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
          <section className="flex items-center gap-3 rounded-xl border border-gray-200 bg-white p-5">
            <Database className="h-5 w-5 text-brand-600" />
            <p className="text-sm text-gray-600">
              Cache adalah sumber daya bersama server. Pembatasan memori diatur oleh{" "}
              <span className="font-semibold text-gray-800">administrator</span> di panel admin &gt; Cache
              Manager.
            </p>
          </section>
        </>
      )}
    </div>
  );
}