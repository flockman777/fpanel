import { askConfirm } from "../askConfirm";
import { KeyRound, Pencil, Plus, Trash2, UserPlus } from "lucide-react";
import { useEffect, useState } from "react";
import { api } from "../App";

interface Account {
  id: number;
  username: string;
  email: string;
  package_id: number;
  status: string;
  name: string | null;
  main_domain?: string | null;
}

interface Package {
  id: number;
  name: string;
}

export default function Accounts() {
  const [accounts, setAccounts] = useState<Account[]>([]);
  const [packages, setPackages] = useState<Package[]>([]);
  const [error, setError] = useState("");
  const [showForm, setShowForm] = useState(false);
  const [edit, setEdit] = useState<Account | null>(null);
  const [form, setForm] = useState({
    username: "",
    email: "",
    password: "",
    package_id: "",
    status: "active",
    name: "",
    domain: "",
  });

  const load = async () => {
    setError("");
    try {
      const res = await api<Account[]>("/accounts");
      if (Array.isArray(res)) setAccounts(res);
    } catch (e: any) {
      setError(String(e.message || e));
    }
  };

  useEffect(() => {
    api<Package[]>("/packages")
      .then(setPackages)
      .catch((e: any) => setError(String(e.message || e)));
    load();
  }, []);

  const save = async (e: React.FormEvent) => {
    e.preventDefault();
    setError("");
    try {
      if (edit) {
        await api(`/accounts/${edit.id}`, {
          method: "PUT",
          body: JSON.stringify({
            email: form.email,
            package_id: Number(form.package_id),
            status: form.status,
            name: form.name || null,
            password: form.password || null,
          }),
        });
      } else {
        await api("/accounts", {
          method: "POST",
          body: JSON.stringify({
            username: form.username,
            email: form.email,
            password: form.password || null,
            package_id: Number(form.package_id),
            name: form.name || null,
            domain: form.domain.trim() || null,
          }),
        });
      }
      setForm({
        username: "",
        email: "",
        password: "",
        package_id: "",
        status: "active",
        name: "",
        domain: "",
      });
      setEdit(null);
      setShowForm(false);
      load();
    } catch (err: any) {
      setError(err.message || "Failed to save account");
    }
  };

  const remove = async (id: number) => {
    if (!await askConfirm("Delete this account? This removes its files, domains, databases and emails.")) return;
    try {
      await api(`/accounts/${id}`, { method: "DELETE" });
      setError("");
      load();
    } catch (e: any) {
      setError(String(e.message || e));
    }
  };

  const [pwAccount, setPwAccount] = useState<Account | null>(null);
  const [pw, setPw] = useState("");
  const [pwShow, setPwShow] = useState(false);
  const [pwError, setPwError] = useState("");
  const [notice, setNotice] = useState("");

  const openPw = (a: Account) => {
    setPwAccount(a);
    setPw("");
    setPwShow(false);
    setPwError("");
  };

  const savePw = async () => {
    if (!pwAccount) return;
    if (pw.length < 6) {
      setPwError("Password must be at least 6 characters");
      return;
    }
    try {
      await api(`/accounts/${pwAccount.id}`, {
        method: "PUT",
        body: JSON.stringify({ password: pw }),
      });
      setNotice(`Password updated for ${pwAccount.username}`);
      setPwAccount(null);
      setError("");
    } catch (e: any) {
      setPwError(String(e.message || e));
    }
  };

  const startEdit = (a: Account) => {
    setEdit(a);
    setForm({
      username: a.username,
      email: a.email,
      password: "",
      package_id: String(a.package_id),
      status: a.status,
      name: a.name || "",
      domain: "",
    });
    setShowForm(true);
    setError("");
  };

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-xl font-semibold text-gray-800">Hosting Accounts</h2>
          <p className="text-sm text-gray-500">
            Manage customer accounts and hosting packages
          </p>
        </div>
        <button
          onClick={() => {
            setEdit(null);
            setShowForm(!showForm);
          }}
          className="flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-700"
        >
          <Plus className="h-4 w-4" />
          Create Account
        </button>
      </div>

      {error && (
        <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
          {error}
        </div>
      )}
      {notice && (
        <div className="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
          {notice}
        </div>
      )}

      {showForm && (
        <form
          onSubmit={save}
          className="rounded-xl border border-brand-200 bg-brand-50 p-6"
        >
          <div className="mb-4 flex items-center gap-2 text-brand-700">
            {edit ? <Pencil className="h-5 w-5" /> : <UserPlus className="h-5 w-5" />}
            <span className="font-semibold">
              {edit ? `Edit Account: ${edit.username}` : "New Account"}
            </span>
            {edit && (
              <button
                type="button"
                onClick={() => {
                  setEdit(null);
                  setForm({
                    username: "",
                    email: "",
                    password: "",
                    package_id: "",
                    status: "active",
                    name: "",
                    domain: "",
                  });
                }}
                className="ml-auto text-xs font-medium text-brand-500 hover:text-brand-700"
              >
                ✕ cancel edit
              </button>
            )}
          </div>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            {!edit && (
              <div className="sm:col-span-2">
                <label className="mb-1.5 block text-sm font-medium text-gray-700">
                  Main Domain <span className="text-red-500">*</span>
                </label>
                <input
                  value={form.domain}
                  onChange={(e) => setForm({ ...form, domain: e.target.value })}
                  className="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none"
                  placeholder="mis. fpanel.my.id"
                  required
                />
                <p className="mt-1 text-xs text-gray-500">
                  DNS dibuat otomatis untuk domain utama ini (A → IP server, NS, www CNAME).
                </p>
              </div>
            )}
            <div>
              <label className="mb-1.5 block text-sm font-medium text-gray-700">
                Username
              </label>
              <input
                value={form.username}
                onChange={(e) => setForm({ ...form, username: e.target.value })}
                className="w-full rounded-lg border border-gray-300 bg-gray-100 px-3 py-2.5 text-sm focus:outline-none disabled:cursor-not-allowed"
                required
                disabled={edit}
              />
            </div>
            <div>
              <label className="mb-1.5 block text-sm font-medium text-gray-700">
                Email
              </label>
              <input
                type="email"
                value={form.email}
                onChange={(e) => setForm({ ...form, email: e.target.value })}
                className="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none"
                required
              />
            </div>
            <div>
              <label className="mb-1.5 block text-sm font-medium text-gray-700">
                Password (min 6 chars)
              </label>
              <input
                type="password"
                value={form.password}
                onChange={(e) => setForm({ ...form, password: e.target.value })}
                className="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none"
                placeholder={edit ? "Leave blank to keep current" : "Used for client login"}
              />
            </div>
            {edit && (
              <div>
                <label className="mb-1.5 block text-sm font-medium text-gray-700">
                  Status
                </label>
                <select
                  value={form.status}
                  onChange={(e) => setForm({ ...form, status: e.target.value })}
                  className="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none"
                >
                  <option value="active">active</option>
                  <option value="suspended">suspended</option>
                </select>
              </div>
            )}
            <div>
              <label className="mb-1.5 block text-sm font-medium text-gray-700">
                Package
              </label>
              <select
                value={form.package_id}
                onChange={(e) =>
                  setForm({ ...form, package_id: e.target.value })
                }
                className="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none"
                required
              >
                <option value="">Select package...</option>
                {packages.map((p) => (
                  <option key={p.id} value={p.id}>
                    {p.name}
                  </option>
                ))}
              </select>
            </div>
            <div>
              <label className="mb-1.5 block text-sm font-medium text-gray-700">
                Full Name
              </label>
              <input
                value={form.name}
                onChange={(e) => setForm({ ...form, name: e.target.value })}
                className="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none"
              />
            </div>
          </div>
          <div className="mt-5 flex gap-3">
            <button
              type="submit"
              className="rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-700"
            >
              Save
            </button>
            <button
              type="button"
              onClick={() => setShowForm(false)}
              className="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-600 hover:bg-gray-50"
            >
              Cancel
            </button>
          </div>
        </form>
      )}

      <div className="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
        <table className="w-full text-left text-sm">
          <thead className="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
            <tr>
              <th className="px-5 py-3.5">Username</th>
              <th className="px-5 py-3.5">Name</th>
              <th className="px-5 py-3.5">Main Domain</th>
              <th className="px-5 py-3.5">Email</th>
              <th className="px-5 py-3.5">Package</th>
              <th className="px-5 py-3.5">Status</th>
              <th className="px-5 py-3.5 text-right">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100">
            {accounts.length === 0 ? (
              <tr>
                <td colSpan={7} className="px-5 py-10 text-center text-gray-400">
                  No accounts yet
                </td>
              </tr>
            ) : (
              accounts.map((a) => (
                <tr key={a.id} className="hover:bg-gray-50">
                  <td className="px-5 py-3.5 font-medium text-gray-800">
                    {a.username}
                  </td>
                  <td className="px-5 py-3.5 text-gray-600">{a.name || "-"}</td>
                  <td className="px-5 py-3.5">
                    {a.main_domain ? (
                      <span className="font-mono text-xs font-medium text-brand-600">
                        {a.main_domain}
                      </span>
                    ) : (
                      <span className="text-gray-400">-</span>
                    )}
                  </td>
                  <td className="px-5 py-3.5 text-gray-600">{a.email}</td>
                  <td className="px-5 py-3.5 text-gray-600">
                    {packages.find((p) => p.id === a.package_id)?.name ||
                      "No package"}
                  </td>
                  <td className="px-5 py-3.5">
                    <span
                      className={`rounded-full px-2.5 py-1 text-xs font-medium ${
                        a.status === "active"
                          ? "bg-green-50 text-green-700"
                          : "bg-red-50 text-red-700"
                      }`}
                    >
                      {a.status}
                    </span>
                  </td>
                  <td className="px-5 py-3.5 text-right">
                    <div className="flex items-center justify-end gap-1">
                      <button
                        onClick={() => startEdit(a)}
                        className="rounded-lg p-2 text-gray-400 transition hover:bg-brand-50 hover:text-brand-600"
                        title="Edit account"
                      >
                        <Pencil className="h-4 w-4" />
                      </button>
                      <button
                        onClick={() => openPw(a)}
                        className="rounded-lg p-2 text-gray-400 transition hover:bg-brand-50 hover:text-brand-600"
                        title="Reset password"
                      >
                        <KeyRound className="h-4 w-4" />
                      </button>
                      <button
                        onClick={() => remove(a.id)}
                        className="rounded-lg p-2 text-gray-400 transition hover:bg-red-50 hover:text-red-600"
                      >
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

      {pwAccount && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
          <div className="w-full max-w-sm rounded-2xl bg-white p-6 shadow-xl">
            <h3 className="text-lg font-semibold text-gray-800">
              Reset password for {pwAccount.username}
            </h3>
            <p className="mt-1 text-sm text-gray-500">Minimum 6 characters.</p>
            <div className="mt-4">
              <div className="relative">
                <input
                  type={pwShow ? "text" : "password"}
                  value={pw}
                  onChange={(e) => {
                    setPw(e.target.value);
                    setPwError("");
                  }}
                  autoFocus
                  className="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-brand-500 focus:outline-none"
                  placeholder="New password"
                />
                <button
                  type="button"
                  onClick={() => setPwShow(!pwShow)}
                  className="absolute inset-y-0 right-2 text-sm text-gray-500 hover:text-gray-700"
                >
                  {pwShow ? "Hide" : "Show"}
                </button>
              </div>
              {pwError && (
                <div className="mt-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                  {pwError}
                </div>
              )}
            </div>
            <div className="mt-5 flex justify-end gap-2">
              <button
                onClick={() => setPwAccount(null)}
                className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50"
              >
                Cancel
              </button>
              <button
                onClick={savePw}
                className="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-brand-700"
              >
                Save password
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}