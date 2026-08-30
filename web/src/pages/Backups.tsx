import { askConfirm } from "../askConfirm";
import { Archive, Download, HardDriveDownload, RefreshCw, Trash2 } from "lucide-react";
import { useEffect, useRef, useState } from "react";
import { api, getSess } from "../App";

interface Account {
  id: number;
  username: string;
}

interface Backup {
  file: string;
  username: string;
  size: number;
  created_at: string;
}

export default function Backups() {
  const [backups, setBackups] = useState<Backup[]>([]);
  const [accounts, setAccounts] = useState<Account[]>([]);
  const [accountId, setAccountId] = useState("");
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
      const [b, a] = await Promise.all([api<Backup[]>("/backups"), api<Account[]>("/accounts")]);
      setBackups(b);
      setAccounts(a);
    } catch (e: any) {
      notify(String(e.message || e), "err");
    }
  };

  useEffect(() => {
    load();
  }, []);

  const create = async () => {
    if (!accountId) return notify("Select an account first", "err");
    setBusy(true);
    try {
      await api("/backups", { method: "POST", body: JSON.stringify({ account_id: Number(accountId) }) });
      notify("Backup created");
      load();
    } catch (e: any) {
      notify(String(e.message || e), "err");
    } finally {
      setBusy(false);
    }
  };

  const restore = async (b: Backup) => {
    if (!await askConfirm(`Restore backup "${b.file}"? Current htdocs files will be overwritten by the backup content.`)) return;
    setBusy(true);
    try {
      await api(`/backups/${b.file}/restore`, { method: "POST" });
      notify(`Restored ${b.file}`);
    } catch (e: any) {
      notify(String(e.message || e), "err");
    } finally {
      setBusy(false);
    }
  };

  const remove = async (b: Backup) => {
    if (!await askConfirm(`Delete backup "${b.file}"?`)) return;
    try {
      await api(`/backups/${b.file}`, { method: "DELETE" });
      notify("Backup deleted");
      load();
    } catch (e: any) {
      notify(String(e.message || e), "err");
    }
  };

  const fmtBytes = (n: number) => (n >= 1 << 20 ? `${(n / (1 << 20)).toFixed(1)} MB` : `${(n / (1 << 10)).toFixed(1)} KB`);

  return (
    <div className="space-y-6">
      {toast && (
        <div className={`fixed top-4 right-4 z-[60] rounded-lg px-4 py-3 text-sm font-medium text-white shadow-lg ${toast.type === "ok" ? "bg-green-600" : "bg-red-600"}`}>
          {toast.msg}
        </div>
      )}

      <div>
        <h2 className="flex items-center gap-2 text-xl font-semibold text-gray-800">
          <Archive className="h-5 w-5 text-brand-600" /> Backup Manager
        </h2>
        <p className="text-sm text-gray-500">Create, download and restore account backups (website files)</p>
      </div>

      <section className="rounded-xl border border-gray-200 bg-white p-5">
        <div className="flex flex-wrap items-end gap-3">
          <div className="w-72">
            <label className="mb-1.5 block text-sm font-medium text-gray-700">Account</label>
            <select value={accountId} onChange={(e) => setAccountId(e.target.value)} className="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none">
              <option value="">Select account...</option>
              {accounts.map((a) => (
                <option key={a.id} value={a.id}>
                  {a.username}
                </option>
              ))}
            </select>
          </div>
          <button onClick={create} disabled={busy} className="flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-700 disabled:opacity-60">
            <HardDriveDownload className="h-4 w-4" /> {busy ? "Creating..." : "Create Backup"}
          </button>
        </div>
        <p className="mt-3 text-xs text-gray-400">Creates a ZIP of the account's public files (htdocs).</p>
      </section>

      <div className="overflow-hidden rounded-xl border border-gray-200 bg-white">
        <table className="w-full text-left text-sm">
          <thead className="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
            <tr>
              <th className="px-5 py-3">File</th>
              <th className="px-5 py-3">Account</th>
              <th className="px-5 py-3">Size</th>
              <th className="px-5 py-3">Created</th>
              <th className="px-5 py-3 text-right">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100">
            {backups.length === 0 ? (
              <tr>
                <td colSpan={5} className="px-5 py-10 text-center text-gray-400">
                  No backups yet.
                </td>
              </tr>
            ) : (
              backups.map((b) => (
                <tr key={b.file} className="hover:bg-gray-50">
                  <td className="max-w-[22rem] truncate px-5 py-3 font-mono text-xs font-medium text-gray-800" title={b.file}>
                    {b.file}
                  </td>
                  <td className="px-5 py-3 text-xs text-gray-600">{b.username}</td>
                  <td className="px-5 py-3 text-xs text-gray-500">{fmtBytes(b.size)}</td>
                  <td className="px-5 py-3 text-xs text-gray-500">{b.created_at}</td>
                  <td className="px-5 py-3">
                    <div className="flex justify-end gap-1.5">
                      <a href={`/api/s/${getSess()}/backups/${b.file}/download`} className="rounded-lg p-1.5 text-gray-500 transition hover:bg-brand-50 hover:text-brand-700" title="Download" download>
                        <Download className="h-4 w-4" />
                      </a>
                      <button onClick={() => restore(b)} disabled={busy} className="rounded-lg p-1.5 text-gray-500 transition hover:bg-amber-50 hover:text-amber-700" title="Restore">
                        <RefreshCw className="h-4 w-4" />
                      </button>
                      <button onClick={() => remove(b)} className="rounded-lg p-1.5 text-gray-500 transition hover:bg-red-50 hover:text-red-600" title="Delete">
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
    </div>
  );
}