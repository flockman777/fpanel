import { useEffect, useState } from "react";
import { api } from "../App";
import { MailOpen, MousePointerClick, Image, Link2, RefreshCw } from "lucide-react";

interface Tracking {
  token: string;
  msgid: string;
  from_addr: string;
  to_addr: string;
  subject: string;
  ts: string;
  html: boolean;
  opens: number;
  clicks: number;
  first_open: string | null;
  first_click: string | null;
}

export default function Tracking() {
  const [rows, setRows] = useState<Tracking[]>([]);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);

  const load = async () => {
    setLoading(true);
    setError("");
    try {
      const res = await api<Tracking[]>("/tracking");
      if (Array.isArray(res)) setRows(res);
    } catch (e: any) {
      setError(String(e.message || e));
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    load();
  }, []);

  const time = (ts: string) => {
    const m = ts?.match(/([A-Z][a-z]{2} \d{1,2} \d{2}:\d{2}:\d{2})/);
    return m ? m[1] : ts;
  };

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-xl font-semibold text-gray-800">Email Tracking</h2>
          <p className="text-sm text-gray-500">
            Open pixel and link click tracking injected by the mail filter
            (mail.fpanel.my.id/t/o and /t/c). Tracked for HTML messages only.
          </p>
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
              <th className="px-5 py-3">Subject</th>
              <th className="px-5 py-3">From</th>
              <th className="px-5 py-3">To</th>
              <th className="px-5 py-3">Format</th>
              <th className="px-5 py-3">Opens</th>
              <th className="px-5 py-3">Clicks</th>
              <th className="px-5 py-3">First open / click</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-50">
            {rows.map((r, i) => (
              <tr key={i} className="hover:bg-gray-50/60">
                <td className="whitespace-nowrap px-5 py-3 text-xs text-gray-500">
                  {time(r.ts)}
                </td>
                <td className="max-w-[220px] truncate px-5 py-3 text-sm font-medium text-gray-800">
                  {r.subject || <span className="text-gray-400">(no subject)</span>}
                </td>
                <td className="max-w-[160px] truncate px-5 py-3 text-xs text-gray-500">
                  {r.from_addr || "–"}
                </td>
                <td className="max-w-[160px] truncate px-5 py-3 text-xs text-gray-600">
                  {r.to_addr}
                </td>
                <td className="px-5 py-3">
                  {r.html ? (
                    <span className="inline-flex items-center gap-1 rounded-full bg-blue-50 px-2 py-0.5 text-[11px] font-medium text-blue-700">
                      <Image className="h-3 w-3" /> HTML
                    </span>
                  ) : (
                    <span className="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-medium text-gray-500">
                      text
                    </span>
                  )}
                </td>
                <td className="px-5 py-3">
                  <span className="inline-flex items-center gap-1.5 text-sm text-gray-700">
                    <MailOpen
                      className={`h-4 w-4 ${r.opens > 0 ? "text-green-600" : "text-gray-300"}`}
                    />
                    <span className={`font-semibold ${r.opens > 0 ? "text-green-700" : ""}`}>
                      {r.opens}
                    </span>
                  </span>
                </td>
                <td className="px-5 py-3">
                  <span className="inline-flex items-center gap-1.5 text-sm text-gray-700">
                    <MousePointerClick
                      className={`h-4 w-4 ${r.clicks > 0 ? "text-blue-600" : "text-gray-300"}`}
                    />
                    <span className={`font-semibold ${r.clicks > 0 ? "text-blue-700" : ""}`}>
                      {r.clicks}
                    </span>
                  </span>
                </td>
                <td className="whitespace-nowrap px-5 py-3 text-xs text-gray-500">
                  {r.first_open || r.first_click ? (
                    <span className="inline-flex items-center gap-2">
                      {r.first_open ? (
                        <span className="flex items-center gap-1">
                          <MailOpen className="h-3 w-3 text-green-600" /> {time(r.first_open)}
                        </span>
                      ) : null}
                      {r.first_click ? (
                        <span className="flex items-center gap-1">
                          <Link2 className="h-3 w-3 text-blue-600" /> {time(r.first_click)}
                        </span>
                      ) : null}
                    </span>
                  ) : (
                    <span className="text-gray-300">–</span>
                  )}
                </td>
              </tr>
            ))}
            {rows.length === 0 && (
              <tr>
                <td colSpan={8} className="px-5 py-10 text-center text-sm text-gray-400">
                  No tracked messages yet.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}