import { askConfirm } from "../askConfirm";
import { Key, Plus, RefreshCw, Terminal, Trash2 } from "lucide-react";
import { useEffect, useRef, useState } from "react";
import { api } from "../App";

interface Row {
  id: number;
  username: string;
  auth_type: string;
  public_key: string | null;
  authorized_keys: string | null;
  status: string;
  created_at: string;
}

export default function Ssh() {
  const [rows, setRows] = useState<Row[]>([]);
  const [open, setOpen] = useState(false);
  const [username, setUsername] = useState("");
  const [authType, setAuthType] = useState<"key" | "password">("key");
  const [authorizedKeys, setAuthorizedKeys] = useState("");
  const [privateKey, setPrivateKey] = useState<{ user: string; key: string } | null>(null);
  const [regenerating, setRegenerating] = useState<number | null>(null);
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
      setRows(await api<Row[]>("/client/ssh"));
    } catch (e: any) {
      notify(String(e.message || e), "err");
    }
  };

  useEffect(() => {
    load();
  }, []);

  const create = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    try {
      const res = await api<{ user: Row; private_key: string | null }>("/client/ssh", {
        method: "POST",
        body: JSON.stringify({ username, auth_type: authType, authorized_keys: authorizedKeys.trim() || null }),
      });
      setOpen(false);
      setUsername("");
      setAuthorizedKeys("");
      if (res.private_key) setPrivateKey({ user: res.user.username, key: res.private_key });
      notify("SSH access created");
      load();
    } catch (e: any) {
      notify(String(e.message || e), "err");
    } finally {
      setBusy(false);
    }
  };

  const regenerate = async (r: Row) => {
    if (!await askConfirm(`Regenerate the key pair for "${r.username}"?`)) return;
    setRegenerating(r.id);
    try {
      const res = await api<{ username: string; private_key: string }>(`/client/ssh/${r.id}/keys`, { method: "POST" });
      setPrivateKey({ user: res.username, key: res.private_key });
      notify("Key pair regenerated");
      load();
    } catch (e: any) {
      notify(String(e.message || e), "err");
    } finally {
      setRegenerating(null);
    }
  };

  const remove = async (r: Row) => {
    if (!await askConfirm(`Remove SSH access for "${r.username}"?`)) return;
    try {
      await api(`/client/ssh/${r.id}`, { method: "DELETE" });
      notify("SSH access removed");
      load();
    } catch (e: any) {
      notify(String(e.message || e), "err");
    }
  };

  const copyKey = async () => {
    if (!privateKey) return;
    await navigator.clipboard.writeText(privateKey.key);
    notify("Private key copied to clipboard");
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
        <h2 className="text-xl font-semibold text-gray-800">SSH Access</h2>
        <p className="text-sm text-gray-500">Manage SSH users and SSH keys attached to your account</p>
      </div>

      <section className="rounded-xl border border-gray-200 bg-white p-5">
        <div className="mb-3 flex items-center justify-between">
          <div className="flex items-center gap-2 text-sm font-semibold text-gray-700">
            <Terminal className="h-4 w-4 text-brand-600" /> SSH users
          </div>
          <button onClick={() => setOpen(true)} className={btn}>
            <Plus className="h-3.5 w-3.5" /> Create SSH user
          </button>
        </div>
        <table className="w-full text-left text-sm">
          <thead>
            <tr className="border-b border-gray-200 text-xs uppercase tracking-wider text-gray-500">
              <th className="px-3 py-2">Username</th>
              <th className="px-3 py-2">Auth</th>
              <th className="px-3 py-2">Public key</th>
              <th className="px-3 py-2">Status</th>
              <th className="px-3 py-2 text-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 ? (
              <tr>
                <td colSpan={5} className="px-3 py-6 text-center text-sm text-gray-400">
                  No SSH users configured
                </td>
              </tr>
            ) : (
              rows.map((r) => (
                <tr key={r.id} className="border-b border-gray-100">
                  <td className="px-3 py-2.5 font-mono text-xs font-medium text-gray-800">{r.username}</td>
                  <td className="px-3 py-2.5">
                    <span className="rounded-full bg-blue-50 px-2.5 py-1 text-xs font-medium text-blue-700">{r.auth_type}</span>
                  </td>
                  <td className="px-3 py-2.5">
                    {r.public_key ? (
                      <span className="max-w-[22rem] truncate font-mono text-[11px] text-gray-500" title={r.public_key}>
                        {r.public_key}
                      </span>
                    ) : (
                      <span className="text-xs text-gray-400">none</span>
                    )}
                  </td>
                  <td className="px-3 py-2.5">
                    <span className={`rounded-full px-2.5 py-1 text-xs font-medium ${r.status === "active" ? "bg-green-50 text-green-700" : "bg-gray-100 text-gray-500"}`}>
                      {r.status}
                    </span>
                  </td>
                  <td className="px-3 py-2.5">
                    <div className="flex justify-end gap-1.5">
                      {r.auth_type === "key" && (
                        <button
                          onClick={() => regenerate(r)}
                          disabled={regenerating === r.id}
                          className="rounded-lg p-1.5 text-gray-500 transition hover:bg-brand-50 hover:text-brand-700"
                          title="Regenerate key pair"
                        >
                          <RefreshCw className={"h-4 w-4 " + (regenerating === r.id ? "animate-spin" : "")} />
                        </button>
                      )}
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
      </section>

      {open && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
          <form onSubmit={create} className="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
            <div className="mb-4 flex items-center gap-2">
              <Key className="h-4 w-4 text-brand-600" />
              <h3 className="text-lg font-semibold text-gray-800">Create SSH user</h3>
            </div>
            <div className="mb-3">
              <label className="mb-1 block text-xs font-medium text-gray-600">SSH username</label>
              <input value={username} onChange={(e) => setUsername(e.target.value)} required className={base} placeholder="deploy, git, ..." />
            </div>
            <div className="mb-3">
              <label className="mb-1 block text-xs font-medium text-gray-600">Authentication</label>
              <select value={authType} onChange={(e) => setAuthType(e.target.value as "key" | "password")} className={base}>
                <option value="key">SSH key</option>
                <option value="password">Password</option>
              </select>
            </div>
            <div className="mb-3">
              <label className="mb-1 block text-xs font-medium text-gray-600">
                {authType === "key" ? "Authorized keys (leave empty to auto-generate)" : "Authorized keys (optional)"}
              </label>
              <textarea value={authorizedKeys} onChange={(e) => setAuthorizedKeys(e.target.value)} rows={3} className={base + " font-mono text-xs"} placeholder="ssh-ed25519 AAAA... comment" />
            </div>
            <div className="mt-5 flex justify-end gap-2">
              <button type="button" onClick={() => setOpen(false)} className={btnGhost}>
                Cancel
              </button>
              <button disabled={busy} className={btn}>
                {busy ? "Creating..." : "Create user"}
              </button>
            </div>
          </form>
        </div>
      )}

      {privateKey && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
          <div className="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl">
            <div className="mb-2 flex items-center gap-2">
              <Key className="h-4 w-4 text-brand-600" />
              <h3 className="text-lg font-semibold text-gray-800">Private key for "{privateKey.user}"</h3>
            </div>
            <p className="mb-3 text-xs text-amber-700">Copy this private key now. It is only shown once and is not stored on the server.</p>
            <pre className="max-h-64 overflow-auto rounded-lg bg-gray-900 p-3 font-mono text-[11px] text-green-300">{privateKey.key}</pre>
            <div className="mt-4 flex justify-end gap-2">
              <button onClick={copyKey} className={btn}>
                Copy key
              </button>
              <button onClick={() => setPrivateKey(null)} className={btnGhost}>
                Done
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}