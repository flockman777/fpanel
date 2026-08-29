import { Plus, Trash2, ArrowRightLeft } from "lucide-react";
import { useEffect, useState } from "react";
import { api } from "../App";

interface Redirect {
  id: number;
  account_id: number;
  domain_id: number | null;
  domain: string | null;
  from_path: string;
  to_url: string;
  permanent: boolean;
  status: string;
  created_at: string;
}

interface Domain {
  id: number;
  name: string;
  status: string;
}

export default function Redirects() {
  const [redirects, setRedirects] = useState<Redirect[]>([]);
  const [domains, setDomains] = useState<Domain[]>([]);
  const [error, setError] = useState("");
  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState({
    domain_id: "",
    from_path: "/",
    to_url: "",
    permanent: true,
  });

  const load = async () => {
    setError("");
    try {
      const res = await api<Redirect[]>("/client/redirects");
      if (Array.isArray(res)) setRedirects(res);
    } catch (e: any) {
      setError(String(e.message || e));
    }
  };

  useEffect(() => {
    api<Domain[]>("/client/domains")
      .then((d) => {
        setDomains(d);
        if (d[0]) setForm((f) => ({ ...f, domain_id: String(d[0].id) }));
      })
      .catch((e: any) => setError(String(e.message || e)));
    load();
  }, []);

  const create = async (e: React.FormEvent) => {
    e.preventDefault();
    setError("");
    try {
      await api("/client/redirects", {
        method: "POST",
        body: JSON.stringify({
          domain_id: form.domain_id ? Number(form.domain_id) : null,
          from_path: form.from_path.trim() || "/",
          to_url: form.to_url.trim(),
          permanent: form.permanent,
        }),
      });
      setForm({ domain_id: form.domain_id, from_path: "/", to_url: "", permanent: true });
      setShowForm(false);
      load();
    } catch (err: any) {
      setError(err.message || "Failed to create redirect");
    }
  };

  const remove = async (id: number) => {
    if (!confirm("Delete this redirect?")) return;
    try {
      await api(`/client/redirects/${id}`, { method: "DELETE" });
      load();
    } catch (e: any) {
      setError(String(e.message || e));
    }
  };

  const typeBadge = (permanent: boolean) => (
    <span
      className={`rounded-full px-2.5 py-1 text-xs font-medium ${
        permanent ? "bg-brand-50 text-brand-700" : "bg-amber-50 text-amber-700"
      }`}
    >
      {permanent ? "301 Permanent" : "302 Temporary"}
    </span>
  );

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-xl font-semibold text-gray-800">Redirects</h2>
          <p className="text-sm text-gray-500">
            Send visitors from one URL to another
          </p>
        </div>
        <button
          onClick={() => setShowForm(!showForm)}
          className="flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-700"
        >
          <Plus className="h-4 w-4" />
          Create Redirect
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
            <ArrowRightLeft className="h-5 w-5" />
            <span className="font-semibold">Create a redirect</span>
          </div>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div>
              <label className="mb-1.5 block text-sm font-medium text-gray-700">
                Domain
              </label>
              <select
                value={form.domain_id}
                onChange={(e) => setForm({ ...form, domain_id: e.target.value })}
                className="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none"
                required
              >
                <option value="">Select domain...</option>
                {domains
                  .filter((d) => d.status === "active")
                  .map((d) => (
                    <option key={d.id} value={d.id}>
                      {d.name}
                    </option>
                  ))}
              </select>
            </div>
            <div>
              <label className="mb-1.5 block text-sm font-medium text-gray-700">
                From path
              </label>
              <input
                value={form.from_path}
                onChange={(e) => setForm({ ...form, from_path: e.target.value })}
                className="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none"
                placeholder="/old-page"
              />
            </div>
            <div>
              <label className="mb-1.5 block text-sm font-medium text-gray-700">
                To URL
              </label>
              <input
                value={form.to_url}
                onChange={(e) => setForm({ ...form, to_url: e.target.value })}
                className="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none"
                placeholder="https://example.com/new"
                required
              />
            </div>
            <div>
              <label className="mb-1.5 block text-sm font-medium text-gray-700">
                Type
              </label>
              <select
                value={form.permanent ? "1" : "0"}
                onChange={(e) => setForm({ ...form, permanent: e.target.value === "1" })}
                className="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none"
              >
                <option value="1">301 Permanent</option>
                <option value="0">302 Temporary</option>
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
              <th className="px-5 py-3.5">From</th>
              <th className="px-5 py-3.5">To</th>
              <th className="px-5 py-3.5">Type</th>
              <th className="px-5 py-3.5">Status</th>
              <th className="px-5 py-3.5 text-right">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100">
            {redirects.length === 0 ? (
              <tr>
                <td colSpan={5} className="px-5 py-10 text-center text-gray-400">
                  No redirects yet. Create your first redirect.
                </td>
              </tr>
            ) : (
              redirects.map((r) => (
                <tr key={r.id} className="hover:bg-gray-50">
                  <td className="px-5 py-3.5 font-medium text-gray-800">
                    {r.domain ? `${r.domain}${r.from_path}` : `Any domain${r.from_path}`}
                  </td>
                  <td className="px-5 py-3.5 text-gray-600">{r.to_url}</td>
                  <td className="px-5 py-3.5">{typeBadge(r.permanent)}</td>
                  <td className="px-5 py-3.5">
                    <span className="rounded-full bg-green-50 px-2.5 py-1 text-xs font-medium text-green-700">
                      {r.status}
                    </span>
                  </td>
                  <td className="px-5 py-3.5 text-right">
                    <button
                      onClick={() => remove(r.id)}
                      className="rounded-lg p-2 text-gray-400 transition hover:bg-red-50 hover:text-red-600"
                      title="Delete redirect"
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