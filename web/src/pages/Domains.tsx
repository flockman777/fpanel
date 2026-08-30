import { askConfirm } from "../askConfirm";
import { Globe, Plus, Trash2 } from "lucide-react";
import { useEffect, useState } from "react";
import { api } from "../App";

interface Domain {
  id: number;
  account_id: number;
  username: string;
  name: string;
  kind: string;
  status: string;
  created_at: string;
}

interface Account {
  id: number;
  username: string;
}

export default function Domains() {
  const [domains, setDomains] = useState<Domain[]>([]);
  const [accounts, setAccounts] = useState<Account[]>([]);
  const [error, setError] = useState("");
  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState({ account_id: "", name: "", kind: "main" });

  const load = async () => {
    setError("");
    try {
      const res = await api<Domain[]>("/domains");
      if (Array.isArray(res)) setDomains(res);
    } catch (e: any) {
      setError(String(e.message || e));
    }
  };

  useEffect(() => {
    api<Account[]>("/accounts")
      .then(setAccounts)
      .catch((e: any) => setError(String(e.message || e)));
    load();
  }, []);

  const create = async (e: React.FormEvent) => {
    e.preventDefault();
    setError("");
    try {
      await api("/domains", {
        method: "POST",
        body: JSON.stringify({
          account_id: Number(form.account_id),
          name: form.name.trim().toLowerCase(),
          kind: form.kind,
        }),
      });
      setForm({ account_id: "", name: "", kind: "main" });
      setShowForm(false);
      load();
    } catch (err: any) {
      setError(err.message || "Failed to create domain");
    }
  };

  const remove = async (id: number) => {
    if (!await askConfirm("Delete this domain and remove its vhost?")) return;
    try {
      await api(`/domains/${id}`, { method: "DELETE" });
      load();
    } catch (e: any) {
      setError(String(e.message || e));
    }
  };

  const kindBadge = (kind: string) => {
    const color =
      kind === "main"
        ? "bg-brand-50 text-brand-700"
        : kind === "sub"
        ? "bg-indigo-50 text-indigo-700"
        : "bg-purple-50 text-purple-700";
    return (
      <span className={`rounded-full px-2.5 py-1 text-xs font-medium ${color}`}>
        {kind}
      </span>
    );
  };

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-xl font-semibold text-gray-800">Domains</h2>
          <p className="text-sm text-gray-500">
            Manage domains, subdomains and aliases across accounts
          </p>
        </div>
        <button
          onClick={() => setShowForm(!showForm)}
          className="flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-700"
        >
          <Plus className="h-4 w-4" />
          Add Domain
        </button>
      </div>

      {error && (
        <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
          {error}
        </div>
      )}

      {showForm && (
        <form
          onSubmit={create}
          className="rounded-xl border border-brand-200 bg-brand-50 p-6"
        >
          <div className="mb-4 flex items-center gap-2 text-brand-700">
            <Globe className="h-5 w-5" />
            <span className="font-semibold">New Domain</span>
          </div>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div>
              <label className="mb-1.5 block text-sm font-medium text-gray-700">
                Account
              </label>
              <select
                value={form.account_id}
                onChange={(e) => setForm({ ...form, account_id: e.target.value })}
                className="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none"
                required
              >
                <option value="">Select account...</option>
                {accounts.map((a) => (
                  <option key={a.id} value={a.id}>
                    {a.username}
                  </option>
                ))}
              </select>
            </div>
            <div>
              <label className="mb-1.5 block text-sm font-medium text-gray-700">
                Domain name
              </label>
              <input
                value={form.name}
                onChange={(e) => setForm({ ...form, name: e.target.value })}
                className="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none"
                placeholder="example.com"
                required
              />
            </div>
            <div>
              <label className="mb-1.5 block text-sm font-medium text-gray-700">
                Kind
              </label>
              <select
                value={form.kind}
                onChange={(e) => setForm({ ...form, kind: e.target.value })}
                className="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none"
              >
                <option value="main">Main</option>
                <option value="sub">Subdomain</option>
                <option value="alias">Alias</option>
              </select>
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
              <th className="px-5 py-3.5">Domain</th>
              <th className="px-5 py-3.5">Account</th>
              <th className="px-5 py-3.5">Kind</th>
              <th className="px-5 py-3.5">Status</th>
              <th className="px-5 py-3.5 text-right">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100">
            {domains.length === 0 ? (
              <tr>
                <td colSpan={5} className="px-5 py-10 text-center text-gray-400">
                  No domains yet. Add your first domain.
                </td>
              </tr>
            ) : (
              domains.map((d) => (
                <tr key={d.id} className="hover:bg-gray-50">
                  <td className="px-5 py-3.5 font-medium text-gray-800">
                    {d.name}
                  </td>
                  <td className="px-5 py-3.5 text-gray-600">{d.username}</td>
                  <td className="px-5 py-3.5">{kindBadge(d.kind)}</td>
                  <td className="px-5 py-3.5">
                    <span
                      className={`rounded-full px-2.5 py-1 text-xs font-medium ${
                        d.status === "active"
                          ? "bg-green-50 text-green-700"
                          : "bg-red-50 text-red-700"
                      }`}
                    >
                      {d.status}
                    </span>
                  </td>
                  <td className="px-5 py-3.5 text-right">
                    <button
                      onClick={() => remove(d.id)}
                      className="rounded-lg p-2 text-gray-400 transition hover:bg-red-50 hover:text-red-600"
                      title="Delete domain"
                    >
                      <Trash2 className="h-4 w-4" />
                    </button>
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