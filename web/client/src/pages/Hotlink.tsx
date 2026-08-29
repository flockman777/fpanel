import { Link2Off, RotateCcw, Save } from "lucide-react";
import { useEffect, useRef, useState } from "react";
import { api } from "../App";

interface Domain {
  id: number;
  name: string;
}

interface Row {
  id: number;
  domain_id: number;
  domain: string;
  extensions: string;
  allow_empty: boolean;
  allowed_domains: string | null;
  status: boolean;
}

export default function Hotlink() {
  const [domains, setDomains] = useState<Domain[]>([]);
  const [rows, setRows] = useState<Row[]>([]);
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
      setRows(await api<Row[]>("/client/hotlink"));
      setDomains(await api<Domain[]>("/client/domains"));
    } catch (e: any) {
      notify(String(e.message || e), "err");
    }
  };

  useEffect(() => {
    load();
  }, []);

  const save = async (row: Row) => {
    setBusy(true);
    try {
      await api("/client/hotlink", {
        method: "PUT",
        body: JSON.stringify({
          domain_id: row.domain_id,
          extensions: row.extensions,
          allow_empty: row.allow_empty,
          allowed_domains: row.allowed_domains || "",
          status: row.status,
        }),
      });
      notify("Saved");
      load();
    } catch (e: any) {
      notify(String(e.message || e), "err");
    } finally {
      setBusy(false);
    }
  };

  const remove = async (row: Row) => {
    if (!confirm(`Disable hotlink protection for "${row.domain}"?`)) return;
    try {
      await api(`/client/hotlink/${row.id}`, { method: "DELETE" });
      notify("Hotlink protection disabled");
      load();
    } catch (e: any) {
      notify(String(e.message || e), "err");
    }
  };

  const setRow = (i: number, patch: Partial<Row>) =>
    setRows(rows.map((r, idx) => (idx === i ? { ...r, ...patch } : r)));

  const base = "w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none";
  const btn = "flex items-center gap-2 rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-brand-700 disabled:opacity-60";
  const btnGhost = "flex items-center gap-2 rounded-lg border border-gray-300 px-2.5 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50";

  const notProtected = domains.filter((d) => !rows.some((r) => r.domain_id === d.id));

  return (
    <div className="space-y-6">
      {toast && (
        <div className={`fixed top-4 right-4 z-[60] rounded-lg px-4 py-3 text-sm font-medium text-white shadow-lg ${toast.type === "ok" ? "bg-green-600" : "bg-red-600"}`}>
          {toast.msg}
        </div>
      )}
      <div>
        <h2 className="text-xl font-semibold text-gray-800">Hotlink Protection</h2>
        <p className="text-sm text-gray-500">Prevent other sites from embedding your images, CSS and other files</p>
      </div>

      {notProtected.length > 0 && (
        <section className="rounded-xl border border-dashed border-gray-300 bg-white p-5">
          <p className="mb-2 flex items-center gap-2 text-sm font-semibold text-gray-700">
            <Link2Off className="h-4 w-4 text-brand-600" /> Enable protection
          </p>
          <div className="flex flex-wrap gap-2">
            {notProtected.map((d) => (
              <button
                key={d.id}
                onClick={async () => {
                  try {
                    const r = await api<Row>("/client/hotlink", {
                      method: "PUT",
                      body: JSON.stringify({ domain_id: d.id }),
                    });
                    setRows([r, ...rows]);
                    notify("Enabled for " + d.name);
                  } catch (e: any) {
                    notify(String(e.message || e), "err");
                  }
                }}
                className="rounded-full border border-brand-200 bg-brand-50 px-3 py-1.5 text-xs font-medium text-brand-700 hover:bg-brand-100"
              >
                {d.name}
              </button>
            ))}
          </div>
        </section>
      )}

      <section className="rounded-xl border border-gray-200 bg-white p-5">
        {rows.length === 0 ? (
          <p className="py-4 text-center text-sm text-gray-400">No domains with hotlink protection.</p>
        ) : (
          <div className="space-y-4">
            {rows.map((row, i) => (
              <div key={row.domain_id} className="rounded-lg border border-gray-200 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <div className="flex items-center gap-2">
                    <span className="text-sm font-semibold text-gray-800">{row.domain}</span>
                    <label className="inline-flex cursor-pointer items-center gap-1.5 text-xs text-gray-600">
                      <input type="checkbox" checked={row.status} onChange={(e) => setRow(i, { status: e.target.checked })} />
                      {row.status ? "Enabled" : "Disabled"}
                    </label>
                  </div>
                  <div className="flex items-center gap-2">
                    <label className="inline-flex cursor-pointer items-center gap-1.5 text-xs text-gray-600">
                      <input type="checkbox" checked={row.allow_empty} onChange={(e) => setRow(i, { allow_empty: e.target.checked })} />
                      Allow empty referer
                    </label>
                    <button onClick={() => save(row)} disabled={busy} className={btn}>
                      <Save className="h-3.5 w-3.5" /> Save
                    </button>
                    <button onClick={() => remove(row)} className={btnGhost}>
                      <RotateCcw className="h-3.5 w-3.5" /> Disable
                    </button>
                  </div>
                </div>
                <div className="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2">
                  <div>
                    <label className="mb-1 block text-xs font-medium text-gray-600">Protected extensions</label>
                    <input value={row.extensions} onChange={(e) => setRow(i, { extensions: e.target.value })} className={base} />
                  </div>
                  <div>
                    <label className="mb-1 block text-xs font-medium text-gray-600">Allowed domains</label>
                    <input
                      value={row.allowed_domains || ""}
                      onChange={(e) => setRow(i, { allowed_domains: e.target.value || null })}
                      placeholder="partner.example.com"
                      className={base}
                    />
                  </div>
                </div>
              </div>
            ))}
          </div>
        )}
      </section>
    </div>
  );
}