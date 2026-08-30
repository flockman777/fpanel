import { askConfirm } from "../askConfirm";
import { Globe, Plus, Trash2 } from "lucide-react";
import { useEffect, useState } from "react";
import { api } from "../App";

interface Domain {
  id: number;
  account_id: number;
  name: string;
  kind: string;
  status: string;
  created_at: string;
}

export default function Domains() {
  const [domains, setDomains] = useState<Domain[]>([]);
  const [error, setError] = useState("");
  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState({ name: "", kind: "sub" });

  const load = async () => {
    setError("");
    try {
      const res = await api<Domain[]>("/client/domains");
      if (Array.isArray(res)) setDomains(res);
    } catch (e: any) {
      setError(String(e.message || e));
    }
  };

  useEffect(() => {
    load();
  }, []);

  const create = async (e: React.FormEvent) => {
    e.preventDefault();
    setError("");
    try {
      await api("/client/domains", {
        method: "POST",
        body: JSON.stringify({ name: form.name.trim().toLowerCase(), kind: form.kind }),
      });
      setForm({ name: "", kind: "sub" });
      setShowForm(false);
      load();
    } catch (err: any) {
      setError(err.message || "Failed to create domain");
    }
  };

  const remove = async (id: number) => {
    if (!await askConfirm("Delete this domain?")) return;
    try {
      await api(`/client/domains/${id}`, { method: "DELETE" });
      load();
    } catch (e: any) {
      setError(String(e.message || e));
    }
  };

  const kindText = (kind: string) =>
    kind === "main" ? "Main" : kind === "sub" ? "Subdomain" : "Alias";

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-xl font-semibold text-gray-800">Domains</h2>
          <p className="text-sm text-gray-500">
            Create subdomains and aliases for your hosting account
          </p>
        </div>
        <button
          onClick={() => setShowForm(!showForm)}
          className="flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-700"
        >
          <Plus className="h-4 w-4" />
          Create Domain
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
            <span className="font-semibold">Create a domain</span>
          </div>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
              <label className="mb-1.5 block text-sm font-medium text-gray-700">
                Domain name
              </label>
              <input
                value={form.name}
                onChange={(e) => setForm({ ...form, name: e.target.value })}
                className="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none"
                placeholder="blog.example.com"
                required
              />
            </div>
            <div>
              <label className="mb-1.5 block text-sm font-medium text-gray-700">
                Type
              </label>
              <select
                value={form.kind}
                onChange={(e) => setForm({ ...form, kind: e.target.value })}
                className="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none"
              >
                <option value="sub">Subdomain</option>
                <option value="alias">Alias (parked domain)</option>
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
              <th className="px-5 py-3.5">Type</th>
              <th className="px-5 py-3.5">Status</th>
              <th className="px-5 py-3.5 text-right">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100">
            {domains.length === 0 ? (
              <tr>
                <td colSpan={4} className="px-5 py-10 text-center text-gray-400">
                  No domains yet. Create your first subdomain or alias.
                </td>
              </tr>
            ) : (
              domains.map((d) => (
                <tr key={d.id} className="hover:bg-gray-50">
                  <td className="px-5 py-3.5 font-medium text-gray-800">
                    {d.name}
                  </td>
                  <td className="px-5 py-3.5">
                    <span
                      className={`rounded-full px-2.5 py-1 text-xs font-medium ${
                        d.kind === "main"
                          ? "bg-brand-50 text-brand-700"
                          : "bg-indigo-50 text-indigo-700"
                      }`}
                    >
                      {kindText(d.kind)}
                    </span>
                  </td>
                  <td className="px-5 py-3.5">
                    <span className="rounded-full bg-green-50 px-2.5 py-1 text-xs font-medium text-green-700">
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