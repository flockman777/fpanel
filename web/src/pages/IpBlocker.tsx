import { Plus, ShieldOff, Trash2, Users } from "lucide-react";
import { useEffect, useRef, useState } from "react";
import { api } from "../App";

interface Account {
  id: number;
  username: string;
}

interface Domain {
  id: number;
  account_id: number;
  name: string;
}

interface Row {
  id: number;
  account_id: number;
  domain_id: number | null;
  domain: string | null;
  ip: string;
  reason: string | null;
  created_at: string;
}

export default function IpBlocker() {
  const [accounts, setAccounts] = useState<Account[]>([]);
  const [accountId, setAccountId] = useState("");
  const [domains, setDomains] = useState<Domain[]>([]);
  const [rows, setRows] = useState<Row[]>([]);
  const [ip, setIp] = useState("");
  const [domainId, setDomainId] = useState("");
  const [reason, setReason] = useState("");
  const [busy, setBusy] = useState(false);
  const [toast, setToast] = useState<{ type: "ok" | "err"; msg: string } | null>(null);
  const toastTimer = useRef<number>();

  const notify = (msg: string, type: "ok" | "err" = "ok") => {
    setToast({ type, msg });
    window.clearTimeout(toastTimer.current);
    toastTimer.current = window.setTimeout(() => setToast(null), 4000);
  };

  const load = async () => {
    if (!accountId) return;
    try {
      const list = await api<Row[]>(`/ipblocker?account_id=${accountId}`);
      setRows(list);
      const ds = await api<Domain[]>(`/domains?account_id=${accountId}`);
      setDomains(ds);
    } catch (e: any) {
      notify(String(e.message || e), "err");
    }
  };

  useEffect(() => {
    api<Account[]>("/accounts")
      .then((accs) => {
        setAccounts(accs);
        if (accs[0]) setAccountId(String(accs[0].id));
      })
      .catch((e: any) => notify(String(e.message || e), "err"));
  }, []);

  useEffect(() => {
    if (accountId) load();
  }, [accountId]);

  const add = async () => {
    if (!ip.trim()) {
      notify("IP address is required", "err");
      return;
    }
    setBusy(true);
    try {
      await api("/ipblocker", {
        method: "POST",
        body: JSON.stringify({
          account_id: Number(accountId),
          domain_id: domainId ? Number(domainId) : null,
          ip: ip.trim(),
          reason: reason.trim() || null,
        }),
      });
      notify("IP added to block list");
      setIp("");
      setReason("");
      setDomainId("");
      load();
    } catch (e: any) {
      notify(String(e.message || e), "err");
    } finally {
      setBusy(false);
    }
  };

  const remove = async (r: Row) => {
    if (!confirm(`Unblock ${r.ip}?`)) return;
    try {
      await api(`/ipblocker/${r.id}?account_id=${accountId}`, { method: "DELETE" });
      notify(`Unblocked ${r.ip}`);
      load();
    } catch (e: any) {
      notify(String(e.message || e), "err");
    }
  };

  const base = "w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none";
  const btn = "flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-1.5 text-xs font-semibold text-white transition hover:bg-brand-700";

  return (
    <div className="space-y-6">
      {toast && (
        <div className={`fixed top-4 right-4 z-[60] rounded-lg px-4 py-3 text-sm font-medium text-white shadow-lg ${toast.type === "ok" ? "bg-green-600" : "bg-red-600"}`}>
          {toast.msg}
        </div>
      )}

      <div>
        <h2 className="text-xl font-semibold text-gray-800">IP Blocker</h2>
        <p className="text-sm text-gray-500">Block client IP addresses from reaching a domain or the whole account</p>
      </div>

      <div className="flex items-center gap-2 rounded-xl border border-gray-200 bg-white px-4 py-3">
        <Users className="h-4 w-4 text-gray-500" />
        <label className="text-sm text-gray-600">Account</label>
        <select value={accountId} onChange={(e) => setAccountId(e.target.value)} className={base + " w-64"}>
          {accounts.map((a) => (
            <option key={a.id} value={a.id}>
              {a.username}
            </option>
          ))}
        </select>
      </div>

      <section className="rounded-xl border border-gray-200 bg-white p-5">
        <div className="mb-3 flex items-center gap-2 text-sm font-semibold text-gray-700">
          <Plus className="h-4 w-4 text-brand-600" /> Block an IP
        </div>
        <div className="flex flex-wrap items-end gap-3">
          <div className="w-56">
            <label className="mb-1 block text-xs font-medium text-gray-600">IP address or CIDR</label>
            <input value={ip} onChange={(e) => setIp(e.target.value)} placeholder="203.0.113.7 or 203.0.113.0/24" className={base} />
          </div>
          <div className="w-64">
            <label className="mb-1 block text-xs font-medium text-gray-600">Domain (empty = all domains)</label>
            <select value={domainId} onChange={(e) => setDomainId(e.target.value)} className={base}>
              <option value="">All domains</option>
              {domains.map((d) => (
                <option key={d.id} value={d.id}>
                  {d.name}
                </option>
              ))}
            </select>
          </div>
          <div className="w-56">
            <label className="mb-1 block text-xs font-medium text-gray-600">Reason</label>
            <input value={reason} onChange={(e) => setReason(e.target.value)} placeholder="abuse, bot, ..." className={base} />
          </div>
          <button onClick={add} disabled={busy} className={btn + " disabled:opacity-60"}>
            {busy ? "Adding..." : "Block IP"}
          </button>
        </div>
      </section>

      <section className="rounded-xl border border-gray-200 bg-white p-5">
        <div className="overflow-x-auto">
          <table className="w-full text-left text-sm">
            <thead>
              <tr className="border-b border-gray-200 text-xs uppercase tracking-wider text-gray-500">
                <th className="px-3 py-2">IP / CIDR</th>
                <th className="px-3 py-2">Scope</th>
                <th className="px-3 py-2">Reason</th>
                <th className="px-3 py-2">Added</th>
                <th className="px-3 py-2 text-right">Actions</th>
              </tr>
            </thead>
            <tbody>
              {rows.length === 0 ? (
                <tr>
                  <td colSpan={5} className="px-3 py-6 text-center text-sm text-gray-400">
                    No IP addresses are blocked
                  </td>
                </tr>
              ) : (
                rows.map((r) => (
                  <tr key={r.id} className="border-b border-gray-100">
                    <td className="px-3 py-2.5 font-mono text-xs font-medium text-gray-800">{r.ip}</td>
                    <td className="px-3 py-2.5">
                      <span className={`rounded-full px-2.5 py-1 text-xs font-medium ${r.domain ? "bg-indigo-50 text-indigo-700" : "bg-gray-100 text-gray-600"}`}>
                        {r.domain || "All domains"}
                      </span>
                    </td>
                    <td className="px-3 py-2.5 text-xs text-gray-600">{r.reason || "—"}</td>
                    <td className="px-3 py-2.5 text-xs text-gray-500">{r.created_at}</td>
                    <td className="px-3 py-2.5">
                      <div className="flex justify-end">
                        <button onClick={() => remove(r)} className="rounded-lg p-1.5 text-gray-500 transition hover:bg-red-50 hover:text-red-600" title="Unblock">
                          <Trash2 className="h-4 w-4" />
                        </button>
                      </div>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
        <p className="mt-3 flex items-center gap-1.5 text-xs text-gray-400">
          <ShieldOff className="h-3.5 w-3.5" /> Blocked requests from these addresses receive HTTP 403.
        </p>
      </section>
    </div>
  );
}