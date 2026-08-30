import { Globe, Pencil, Plus, Trash2 } from "lucide-react";
import { useEffect, useRef, useState } from "react";
import { useSearchParams } from "react-router-dom";
import { api } from "../App";

interface Domain {
  id: number;
  account_id: number;
  username: string;
  name: string;
  kind: string;
}

interface Record {
  id: number;
  account_id: number;
  username?: string;
  domain_id: number;
  domain: string;
  name: string;
  rtype: string;
  value: string;
  ttl: number;
  priority: number | null;
}

const TYPES = ["A", "AAAA", "CNAME", "MX", "TXT", "NS", "SRV", "CAA"];

export default function Dns() {
  const [domains, setDomains] = useState<Domain[]>([]);
  const [records, setRecords] = useState<Record[]>([]);
  const [domainId, setDomainId] = useState("");
  const [searchParams] = useSearchParams();
  const [editing, setEditing] = useState<Record | null>(null);
  const [form, setForm] = useState({ name: "@", rtype: "A", value: "", ttl: "3600", priority: "" });
  const [busy, setBusy] = useState(false);
  const [toast, setToast] = useState<{ type: "ok" | "err"; msg: string } | null>(null);
  const [confirmDel, setConfirmDel] = useState<Record | null>(null);
  const toastTimer = useRef<number>();

  const notify = (msg: string, type: "ok" | "err" = "ok") => {
    setToast({ type, msg });
    window.clearTimeout(toastTimer.current);
    toastTimer.current = window.setTimeout(() => setToast(null), 4000);
  };

  const loadDomains = async () => {
    try {
      const list = await api<Domain[]>("/domains");
      setDomains(list);
      const want = searchParams.get("domain");
      if (want) {
        const hit = list.find((d) => d.name === want);
        if (hit) setDomainId(String(hit.id));
      }
    } catch (e: any) {
      notify(String(e.message || e), "err");
    }
  };

  const loadRecords = async () => {
    if (!domainId) {
      setRecords([]);
      return;
    }
    try {
      setRecords(await api<Record[]>(`/dns?domain_id=${domainId}`));
    } catch (e: any) {
      notify(String(e.message || e), "err");
    }
  };

  useEffect(() => {
    loadDomains();
  }, []);

  useEffect(() => {
    loadRecords();
  }, [domainId]);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!domainId) return notify("Select a domain first", "err");
    setBusy(true);
    const body = {
      domain_id: Number(domainId),
      name: form.name.trim() || "@",
      rtype: form.rtype,
      value: form.value.trim(),
      ttl: Math.max(1, Number(form.ttl) || 3600),
      priority: form.priority ? Number(form.priority) : null,
    };
    try {
      if (editing) {
        await api(`/dns/${editing.id}`, { method: "PUT", body: JSON.stringify(body) });
        notify("Record updated");
      } else {
        await api("/dns", { method: "POST", body: JSON.stringify(body) });
        notify("Record added");
      }
      setForm({ name: "@", rtype: "A", value: "", ttl: "3600", priority: "" });
      setEditing(null);
      loadRecords();
    } catch (e: any) {
      notify(String(e.message || e), "err");
    } finally {
      setBusy(false);
    }
  };

  const edit = (r: Record) => {
    setEditing(r);
    setForm({
      name: r.name,
      rtype: r.rtype,
      value: r.value,
      ttl: String(r.ttl),
      priority: r.priority != null ? String(r.priority) : "",
    });
  };

  const remove = async (r: Record) => {
    setConfirmDel(null);
    setBusy(true);
    try {
      await api(`/dns/${r.id}`, { method: "DELETE" });
      notify("Record deleted");
      loadRecords();
    } catch (e: any) {
      notify(String(e.message || e), "err");
    } finally {
      setBusy(false);
    }
  };

  const base = "w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none";
  const btn = "flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-1.5 text-xs font-semibold text-white transition hover:bg-brand-700 disabled:opacity-60";
  const typeBadge = (t: string) => {
    const colors: Record<string, string> = {
      A: "bg-blue-50 text-blue-700",
      AAAA: "bg-indigo-50 text-indigo-700",
      CNAME: "bg-purple-50 text-purple-700",
      MX: "bg-amber-50 text-amber-700",
      TXT: "bg-green-50 text-green-700",
      NS: "bg-gray-100 text-gray-600",
      SRV: "bg-pink-50 text-pink-700",
      CAA: "bg-teal-50 text-teal-700",
    };
    return <span className={`inline-block rounded px-1.5 py-0.5 font-mono text-[11px] font-semibold ${colors[t] || "bg-gray-100 text-gray-600"}`}>{t}</span>;
  };

  return (
    <div className="space-y-6">
      {toast && (
        <div className={`fixed top-4 right-4 z-[60] rounded-lg px-4 py-3 text-sm font-medium text-white shadow-lg ${toast.type === "ok" ? "bg-green-600" : "bg-red-600"}`}>
          {toast.msg}
        </div>
      )}

      {confirmDel && (
        <div className="fixed inset-0 z-[70] flex items-center justify-center bg-black/40 p-4">
          <div className="w-full max-w-sm rounded-xl bg-white p-5 shadow-2xl">
            <div className="mb-2 flex items-center gap-2 text-red-600">
              <Trash2 className="h-5 w-5" />
              <h3 className="text-base font-semibold text-gray-800">Delete record</h3>
            </div>
            <p className="mb-5 text-sm text-gray-600">
              Delete {confirmDel.rtype} record <span className="font-mono font-semibold text-gray-800">"{confirmDel.name}"</span> from{" "}
              <span className="font-mono text-gray-800">{confirmDel.domain}</span>? This will also update the live NSD zone.
            </p>
            <div className="flex justify-end gap-2">
              <button onClick={() => setConfirmDel(null)} disabled={busy} className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50">
                Cancel
              </button>
              <button onClick={() => remove(confirmDel)} disabled={busy} className="flex items-center gap-2 rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">
                <Trash2 className="h-4 w-4" /> {busy ? "Deleting..." : "Delete"}
              </button>
            </div>
          </div>
        </div>
      )}

      <div>
        <h2 className="flex items-center gap-2 text-xl font-semibold text-gray-800">
          <Globe className="h-5 w-5 text-brand-600" /> DNS Zone Editor
        </h2>
        <p className="text-sm text-gray-500">Manage DNS records for each domain</p>
      </div>

      <section className="rounded-xl border border-gray-200 bg-white p-5">
        <label className="mb-1.5 block text-sm font-medium text-gray-700">Zone</label>
        <select value={domainId} onChange={(e) => setDomainId(e.target.value)} className={base + " max-w-md"}>
          <option value="">Select a domain...</option>
          {domains.map((d) => (
            <option key={d.id} value={d.id}>
              {d.name} ({d.username})
            </option>
          ))}
        </select>
      </section>

      {domainId && (
        <>
          <form onSubmit={submit} className="rounded-xl border border-brand-200 bg-brand-50 p-5">
            <div className="mb-3 flex items-center gap-2 text-sm font-semibold text-brand-700">
              {editing ? <Pencil className="h-4 w-4" /> : <Plus className="h-4 w-4" />}
              {editing ? `Edit record #${editing.id}` : "Add record"}
            </div>
            <div className="grid grid-cols-2 gap-3 md:grid-cols-6">
              <div>
                <label className="mb-1 block text-xs font-medium text-gray-600">Name</label>
                <input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} className={base} placeholder="@" />
              </div>
              <div>
                <label className="mb-1 block text-xs font-medium text-gray-600">Type</label>
                <select value={form.rtype} onChange={(e) => setForm({ ...form, rtype: e.target.value })} className={base}>
                  {TYPES.map((t) => (
                    <option key={t}>{t}</option>
                  ))}
                </select>
              </div>
              <div className="col-span-2">
                <label className="mb-1 block text-xs font-medium text-gray-600">Value</label>
                <input value={form.value} onChange={(e) => setForm({ ...form, value: e.target.value })} className={base} placeholder={form.rtype === "A" ? "192.0.2.10" : form.rtype === "CNAME" ? "example.com." : "..."} required />
              </div>
              <div>
                <label className="mb-1 block text-xs font-medium text-gray-600">TTL</label>
                <input value={form.ttl} onChange={(e) => setForm({ ...form, ttl: e.target.value })} className={base} placeholder="3600" />
              </div>
              <div>
                <label className="mb-1 block text-xs font-medium text-gray-600">Priority</label>
                <input value={form.priority} onChange={(e) => setForm({ ...form, priority: e.target.value })} className={base} placeholder={form.rtype === "MX" ? "10" : ""} disabled={form.rtype !== "MX" && form.rtype !== "SRV"} />
              </div>
            </div>
            <div className="mt-4 flex gap-2">
              <button type="submit" disabled={busy} className={btn}>
                {busy ? "Saving..." : editing ? "Update record" : "Add record"}
              </button>
              {editing && (
                <button type="button" onClick={() => { setEditing(null); setForm({ name: "@", rtype: "A", value: "", ttl: "3600", priority: "" }); }} className="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50">
                  Cancel
                </button>
              )}
            </div>
          </form>

          <section className="overflow-hidden rounded-xl border border-gray-200 bg-white">
            <table className="w-full text-left text-sm">
              <thead className="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                <tr>
                  <th className="px-5 py-3">Name</th>
                  <th className="px-5 py-3">Type</th>
                  <th className="px-5 py-3">Value</th>
                  <th className="px-5 py-3">TTL</th>
                  <th className="px-5 py-3 text-right">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100">
                {records.length === 0 ? (
                  <tr>
                    <td colSpan={5} className="px-5 py-10 text-center text-gray-400">
                      No DNS records yet. Add your first record above.
                    </td>
                  </tr>
                ) : (
                  records.map((r) => (
                    <tr key={r.id} className="hover:bg-gray-50">
                      <td className="px-5 py-3 font-mono text-xs font-semibold text-gray-800">{r.name}</td>
                      <td className="px-5 py-3">{typeBadge(r.rtype)}</td>
                      <td className="max-w-[28rem] truncate px-5 py-3 font-mono text-xs text-gray-600" title={r.value}>
                        {r.value}
                      </td>
                      <td className="px-5 py-3 text-xs text-gray-500">{r.ttl}</td>
                      <td className="px-5 py-3">
                        <div className="flex justify-end gap-1.5">
                          <button onClick={() => edit(r)} className="rounded-lg p-1.5 text-gray-500 transition hover:bg-brand-50 hover:text-brand-700" title="Edit">
                            <Pencil className="h-4 w-4" />
                          </button>
<button onClick={() => setConfirmDel(r)} className="rounded-lg p-1.5 text-gray-500 transition hover:bg-red-50 hover:text-red-600" title="Delete">
                              <Trash2 className="h-4 w-4" />
                          </button>
                        </div>
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </section>
        </>
      )}
    </div>
  );
}