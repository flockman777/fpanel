import { useEffect, useState } from "react";
import { api } from "../App";
import { Send, RefreshCw } from "lucide-react";

interface Event {
  ts: string;
  qid: string;
  from_addr: string;
  to_addr: string;
  relay: string;
  status: string;
  detail: string;
}

const STATUS_COLOR: Record<string, string> = {
  sent: "bg-green-100 text-green-700",
  bounced: "bg-red-100 text-red-700",
  deferred: "bg-amber-100 text-amber-700",
  expired: "bg-red-100 text-red-700",
};

export default function Delivery() {
  const [events, setEvents] = useState<Event[]>([]);
  const [filter, setFilter] = useState("");
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);

  const load = async () => {
    setLoading(true);
    setError("");
    try {
      const qs = filter ? `?status=${filter}` : "";
      const res = await api<Event[]>(`/delivery${qs}`);
      if (Array.isArray(res)) setEvents(res);
    } catch (e: any) {
      setError(String(e.message || e));
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    load();
  }, [filter]);

  const time = (ts: string) => {
    const m = ts.match(/([A-Z][a-z]{2} \d{1,2} \d{2}:\d{2}:\d{2})/);
    return m ? m[1] : ts;
  };

  const counts = events.reduce<Record<string, number>>((acc, e) => {
    acc[e.status] = (acc[e.status] || 0) + 1;
    return acc;
  }, {});

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-xl font-semibold text-gray-800">Delivery Tracking</h2>
          <p className="text-sm text-gray-500">
            Mail delivery events parsed from postfix logs. Shows sent, bounced and deferred
            deliveries across all accounts.
          </p>
        </div>
        <div className="flex items-center gap-2">
          <div className="flex overflow-hidden rounded-lg border border-gray-200">
            {["", "sent", "bounced", "deferred"].map((s) => (
              <button
                key={s || "all"}
                onClick={() => setFilter(s)}
                className={`px-3 py-1.5 text-xs font-medium transition ${
                  filter === s
                    ? "bg-brand-600 text-white"
                    : "bg-white text-gray-600 hover:bg-gray-50"
                }`}
              >
                {s ? `${s}${counts[s] ? ` (${counts[s]})` : ""}` : "All"}
              </button>
            ))}
          </div>
          <button
            onClick={load}
            disabled={loading}
            className="flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
          >
            <RefreshCw className={`h-3.5 w-3.5 ${loading ? "animate-spin" : ""}`} />
            Refresh
          </button>
        </div>
      </div>

      {error && (
        <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
          {error}
        </div>
      )}

      <div className="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
        <table className="min-w-full divide-y divide-gray-100">
          <thead className="bg-gray-50">
            <tr className="text-left text-xs font-semibold uppercase tracking-wider text-gray-500">
              <th className="px-5 py-3">Time</th>
              <th className="px-5 py-3">Status</th>
              <th className="px-5 py-3">From</th>
              <th className="px-5 py-3">To</th>
              <th className="px-5 py-3">Relay</th>
              <th className="px-5 py-3">Detail</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-50">
            {events.map((e, i) => (
              <tr key={i} className="hover:bg-gray-50/60">
                <td className="whitespace-nowrap px-5 py-3 text-xs text-gray-500">
                  {time(e.ts)}
                </td>
                <td className="px-5 py-3">
                  <span
                    className={`inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-medium ${
                      STATUS_COLOR[e.status] || "bg-gray-100 text-gray-600"
                    }`}
                  >
                    {e.status === "sent" && <Send className="h-3 w-3" />}
                    {e.status}
                  </span>
                </td>
                <td className="max-w-[180px] truncate px-5 py-3 text-sm text-gray-700">
                  {e.from_addr || <span className="text-gray-400">–</span>}
                </td>
                <td className="max-w-[180px] truncate px-5 py-3 text-sm font-medium text-gray-800">
                  {e.to_addr}
                </td>
                <td className="max-w-[160px] truncate px-5 py-3 text-xs text-gray-500">
                  {e.relay || "–"}
                </td>
                <td className="max-w-[260px] truncate px-5 py-3 text-xs text-gray-500">
                  {e.detail}
                </td>
              </tr>
            ))}
            {events.length === 0 && (
              <tr>
                <td colSpan={6} className="px-5 py-10 text-center text-sm text-gray-400">
                  No delivery events yet.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}