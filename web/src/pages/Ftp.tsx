import { askConfirm } from "../askConfirm";
import { FolderLock, KeyRound, Plus, Trash2, Users } from "lucide-react";
import { useEffect, useRef, useState } from "react";
import { api } from "../App";

interface Account {
  id: number;
  username: string;
}

interface Row {
  id: number;
  account_id: number;
  username: string;
  directory: string;
  quota_mb: number;
  status: string;
  created_at: string;
}

export default function Ftp() {
  const [accounts, setAccounts] = useState<Account[]>([]);
  const [accountId, setAccountId] = useState("");
  const [rows, setRows] = useState<Row[]>([]);
  const [open, setOpen] = useState(false);
  const [username, setUsername] = useState("");
  const [password, setPassword] = useState("");
  const [directory, setDirectory] = useState("public_html");
  const [quota, setQuota] = useState("0");
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
      setRows(await api<Row[]>(`/ftp?account_id=${accountId}`));
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

  const create = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    try {
      await api<Row>("/ftp", {
        method: "POST",
        body: JSON.stringify({
          account_id: Number(accountId),
          username,
          password,
          directory: directory.trim() || "public_html",
          quota_mb: Number(quota) || 0,
        }),
      });
      setOpen(false);
      setUsername("");
      setPassword("");
      setDirectory("public_html");
      setQuota("0");
      notify("FTP account created");
      load();
    } catch (e: any) {
      notify(String(e.message || e), "err");
    } finally {
      setBusy(false);
    }
  };

  const remove = async (r: Row) => {
    if (!await askConfirm(`Remove FTP account "${r.username}"?`)) return;
    try {
      await api(`/ftp/${r.id}?account_id=${accountId}`, { method: "DELETE" });
      notify("FTP account removed");
      load();
    } catch (e: any) {
      notify(String(e.message || e), "err");
    }
  };

  const base = "w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none";
  const btn = "flex items-center gap-2 rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-brand-700 disabled:opacity-60";
  const btnGhost = "flex items-center gap-2 rounded-lg border border-gray-300 px-2.5 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50";

  return (
    <div className="space-y-6">
      {toast && (
        <div className={`fixed top-4 right-4 z-[60] rounded-lg px-4 py-3 text-sm font-medium text-white shadow-lg ${toast.type === "ok" ? "bg-green-600" : "bg-red-600"}`}>
          {toast.msg}
        </div>
      )}

      <div>
        <h2 className="text-xl font-semibold text-gray-800">FTP Accounts</h2>
        <p className="text-sm text-gray-500">Manage FTP users that can access this account's files</p>
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
        <div className="mb-3 flex items-center justify-between">
          <div className="flex items-center gap-2 text-sm font-semibold text-gray-700">
            <FolderLock className="h-4 w-4 text-brand-600" /> FTP users
          </div>
          <button onClick={() => setOpen(true)} className={btn}>
            <Plus className="h-3.5 w-3.5" /> Create FTP account
          </button>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full text-left text-sm">
            <thead>
              <tr className="border-b border-gray-200 text-xs uppercase tracking-wider text-gray-500">
                <th className="px-3 py-2">Username</th>
                <th className="px-3 py-2">Directory</th>
                <th className="px-3 py-2">Quota</th>
                <th className="px-3 py-2">Status</th>
                <th className="px-3 py-2 text-right">Actions</th>
              </tr>
            </thead>
            <tbody>
              {rows.length === 0 ? (
                <tr>
                  <td colSpan={5} className="px-3 py-6 text-center text-sm text-gray-400">
                    No FTP accounts configured
                  </td>
                </tr>
              ) : (
                rows.map((r) => (
                  <tr key={r.id} className="border-b border-gray-100">
                    <td className="px-3 py-2.5 font-mono text-xs font-medium text-gray-800">{r.username}</td>
                    <td className="px-3 py-2.5 font-mono text-xs text-gray-500">{r.directory}</td>
                    <td className="px-3 py-2.5 text-xs text-gray-600">{r.quota_mb > 0 ? `${r.quota_mb} MB` : "Unlimited"}</td>
                    <td className="px-3 py-2.5">
                      <span className={`rounded-full px-2.5 py-1 text-xs font-medium ${r.status === "active" ? "bg-green-50 text-green-700" : "bg-gray-100 text-gray-500"}`}>
                        {r.status}
                      </span>
                    </td>
                    <td className="px-3 py-2.5">
                      <div className="flex justify-end">
                        <button onClick={() => remove(r)} className="rounded-lg p-1.5 text-gray-500 transition hover:bg-red-50 hover:text-red-600" title="Remove">
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
      </section>

      {open && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
          <form onSubmit={create} className="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
            <div className="mb-4 flex items-center gap-2">
              <KeyRound className="h-4 w-4 text-brand-600" />
              <h3 className="text-lg font-semibold text-gray-800">Create FTP account</h3>
            </div>
            <div className="mb-3">
              <label className="mb-1 block text-xs font-medium text-gray-600">FTP username</label>
              <input value={username} onChange={(e) => setUsername(e.target.value)} required className={base} placeholder="ftpuser" />
            </div>
            <div className="mb-3">
              <label className="mb-1 block text-xs font-medium text-gray-600">Password</label>
              <input value={password} onChange={(e) => setPassword(e.target.value)} required type="text" className={base} placeholder="min. 6 characters" />
            </div>
            <div className="mb-3">
              <label className="mb-1 block text-xs font-medium text-gray-600">Start directory</label>
              <input value={directory} onChange={(e) => setDirectory(e.target.value)} className={base} placeholder="public_html" />
              <p className="mt-1 text-[11px] text-gray-400">Relative to the account home, e.g. public_html</p>
            </div>
            <div className="mb-3">
              <label className="mb-1 block text-xs font-medium text-gray-600">Quota (MB)</label>
              <input value={quota} onChange={(e) => setQuota(e.target.value)} className={base} placeholder="0 = unlimited" />
            </div>
            <div className="mt-5 flex justify-end gap-2">
              <button type="button" onClick={() => setOpen(false)} className={btnGhost}>
                Cancel
              </button>
              <button disabled={busy} className={btn}>
                {busy ? "Creating..." : "Create account"}
              </button>
            </div>
          </form>
        </div>
      )}
    </div>
  );
}
