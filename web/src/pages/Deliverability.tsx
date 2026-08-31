import { useEffect, useState } from "react";
import { api } from "../App";
import { CheckCircle2, XCircle, MailCheck } from "lucide-react";

interface Row {
  domain: string;
  spf: boolean;
  dmarc: boolean;
  dkim: boolean;
  dkim_signing: boolean;
}

function Badge({ ok, label }: { ok: boolean; label: string }) {
  return (
    <span
      className={`inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-medium ${
        ok ? "bg-green-50 text-green-700" : "bg-gray-100 text-gray-500"
      }`}
    >
      {ok ? <CheckCircle2 className="h-3.5 w-3.5" /> : <XCircle className="h-3.5 w-3.5" />}
      {label}
    </span>
  );
}

export default function Deliverability() {
  const [rows, setRows] = useState<Row[]>([]);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState("");

  const load = async () => {
    try {
      const res = await api<Row[]>("/deliverability");
      if (Array.isArray(res)) setRows(res);
    } catch (e: any) {
      setError(String(e.message || e));
    }
  };

  useEffect(() => {
    load();
  }, []);

  const enable = async (domain: string, action: string) => {
    setBusy(`${domain} ${action}`);
    setError("");
    try {
      await api(`/deliverability/${domain}`, {
        method: "POST",
        body: JSON.stringify({ action }),
      });
      await load();
    } catch (e: any) {
      setError(String(e.message || e));
    } finally {
      setBusy("");
    }
  };

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-xl font-semibold text-gray-800">Email Deliverability</h2>
          <p className="text-sm text-gray-500">
            SPF, DMARC and DKIM records for hosted mail domains. Changes are published to DNS
            automatically (nsd reload).
          </p>
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
              <th className="px-5 py-3">Domain</th>
              <th className="px-5 py-3">Records</th>
              <th className="px-5 py-3 text-right">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-50">
            {rows.map((r) => (
              <tr key={r.domain} className="hover:bg-gray-50/60">
                <td className="px-5 py-3 font-medium text-gray-800">
                  {r.domain}
                  {r.dkim_signing && (
                    <span className="ml-2 text-xs text-green-600">signing active</span>
                  )}
                </td>
                <td className="px-5 py-3">
                  <div className="flex flex-wrap gap-1.5">
                    <Badge ok={r.spf} label="SPF" />
                    <Badge ok={r.dmarc} label="DMARC" />
                    <Badge ok={r.dkim} label="DKIM" />
                  </div>
                </td>
                <td className="px-5 py-3 text-right">
                  <div className="flex items-center justify-end gap-1.5">
                    {!r.spf && (
                      <button
                        onClick={() => enable(r.domain, "spf")}
                        disabled={!!busy}
                        className="rounded-lg border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-700 transition hover:bg-gray-50 disabled:opacity-50"
                      >
                        {busy === `${r.domain} spf` ? "..." : "Add SPF"}
                      </button>
                    )}
                    {!r.dmarc && (
                      <button
                        onClick={() => enable(r.domain, "dmarc")}
                        disabled={!!busy}
                        className="rounded-lg border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-700 transition hover:bg-gray-50 disabled:opacity-50"
                      >
                        {busy === `${r.domain} dmarc` ? "..." : "Add DMARC"}
                      </button>
                    )}
                    {!r.dkim && (
                      <button
                        onClick={() => enable(r.domain, "dkim")}
                        disabled={!!busy}
                        className="flex items-center gap-1 rounded-lg bg-brand-600 px-2.5 py-1 text-xs font-semibold text-white transition hover:bg-brand-700 disabled:opacity-50"
                      >
                        {busy === `${r.domain} dkim` ? (
                          "..."
                        ) : (
                          <>
                            <MailCheck className="h-3.5 w-3.5" /> Enable DKIM
                          </>
                        )}
                      </button>
                    )}
                    {r.spf && r.dmarc && r.dkim && (
                      <span className="text-xs text-green-600">All set</span>
                    )}
                  </div>
                </td>
              </tr>
            ))}
            {rows.length === 0 && (
              <tr>
                <td colSpan={3} className="px-5 py-10 text-center text-sm text-gray-400">
                  No active domains.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}