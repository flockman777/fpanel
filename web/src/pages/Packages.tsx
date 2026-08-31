import { askConfirm } from "../askConfirm";
import { Pencil, Plus, Trash2 } from "lucide-react";
import { useEffect, useState } from "react";
import { api } from "../App";

interface Package {
  id: number;
  name: string;
  disk_limit_mb: number;
  mailbox_limit: number;
  database_limit: number;
  domain_limit: number;
  bandwidth_limit_gb: number;
}

export default function Packages() {
  const [packages, setPackages] = useState<Package[]>([]);
  const [showForm, setShowForm] = useState(false);
  const [editing, setEditing] = useState<number | null>(null);
  const [error, setError] = useState("");
  const [form, setForm] = useState({
    name: "",
    disk_limit_mb: "1024",
    mailbox_limit: "5",
    database_limit: "1",
    domain_limit: "1",
    bandwidth_limit_gb: "0",
  });

  const load = async () => {
    setError("");
    try {
      const res = await api<Package[]>("/packages");
      if (Array.isArray(res)) setPackages(res);
    } catch (e: any) {
      setError(String(e.message || e));
    }
  };

  useEffect(() => {
    load();
  }, []);

  const save = async (e: React.FormEvent) => {
    e.preventDefault();
    setError("");
    const body = {
      name: form.name,
      disk_limit_mb: Number(form.disk_limit_mb),
      mailbox_limit: Number(form.mailbox_limit),
      database_limit: Number(form.database_limit),
      domain_limit: Number(form.domain_limit),
      bandwidth_limit_gb: Number(form.bandwidth_limit_gb),
    };
    try {
      if (editing) {
        await api(`/packages/${editing}`, {
          method: "PATCH",
          body: JSON.stringify(body),
        });
      } else {
        await api("/packages", {
          method: "POST",
          body: JSON.stringify(body),
        });
      }
      closeForm();
      load();
    } catch (err: any) {
      setError(err.message || "Failed to save package. Name must be unique.");
    }
  };

  const closeForm = () => {
    setEditing(null);
    setShowForm(false);
    setForm({
      name: "",
      disk_limit_mb: "1024",
      mailbox_limit: "5",
      database_limit: "1",
      domain_limit: "1",
      bandwidth_limit_gb: "0",
    });
  };

  const startEdit = (p: Package) => {
    setForm({
      name: p.name,
      disk_limit_mb: String(p.disk_limit_mb),
      mailbox_limit: String(p.mailbox_limit),
      database_limit: String(p.database_limit),
      domain_limit: String(p.domain_limit),
      bandwidth_limit_gb: String(p.bandwidth_limit_gb),
    });
    setEditing(p.id);
    setShowForm(true);
  };

  const remove = async (id: number) => {
    if (!await askConfirm("Delete this package?")) return;
    try {
      await api(`/packages/${id}`, { method: "DELETE" });
      load();
    } catch (err: any) {
      setError(err.message || "Failed to delete package");
    }
  };

  const inputClass =
    "w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none";

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-xl font-semibold text-gray-800">Hosting Packages</h2>
          <p className="text-sm text-gray-500">
            Set limits for disk, mailboxes, and databases
          </p>
        </div>
        <button
          onClick={() => {
            setEditing(null);
            setShowForm(true);
          }}
          className="flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-700"
        >
          <Plus className="h-4 w-4" />
          Create Package
        </button>
      </div>

      {error && (
        <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
          {error}
        </div>
      )}

      {showForm && (
        <form
          onSubmit={save}
          className="rounded-xl border border-brand-200 bg-brand-50 p-6"
        >
          <div className="mb-4 font-semibold text-brand-700">
            {editing ? "Edit Package" : "New Package"}
          </div>
          <div className="grid grid-cols-2 gap-4 sm:grid-cols-3">
            <div className="col-span-2">
              <label className="mb-1.5 block text-sm font-medium text-gray-700">
                Package Name
              </label>
              <input
                value={form.name}
                onChange={(e) => setForm({ ...form, name: e.target.value })}
                className={inputClass}
                placeholder="e.g. Starter / Basic / Pro"
                required
              />
            </div>
            {[
              { key: "disk_limit_mb", label: "Disk (MB)" },
              { key: "mailbox_limit", label: "Mailboxes" },
              { key: "database_limit", label: "Databases" },
              { key: "domain_limit", label: "Domains" },
            ].map((f) => (
              <div key={f.key}>
                <label className="mb-1.5 block text-sm font-medium text-gray-700">
                  {f.label}
                </label>
                <input
                  type="number"
                  min={0}
                  value={(form as any)[f.key]}
                  onChange={(e) =>
                    setForm({ ...form, [f.key]: e.target.value })
                  }
                  className={inputClass}
                  required
                />
              </div>
            ))}
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
              onClick={closeForm}
              className="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-600 hover:bg-gray-50"
            >
              Cancel
            </button>
          </div>
        </form>
      )}

      <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
        {packages.length === 0 && (
          <div className="md:col-span-2 xl:col-span-3 rounded-xl border border-dashed border-gray-300 px-6 py-12 text-center text-sm text-gray-400">
            No hosting packages yet.
          </div>
        )}
        {packages.map((p) => (
          <div
            key={p.id}
            className="rounded-xl border border-gray-200 bg-white shadow-sm"
          >
            <div className="flex items-center justify-between border-b border-gray-100 px-5 py-4">
              <h3 className="text-lg font-semibold text-gray-800">{p.name}</h3>
              <div className="flex gap-1">
                <button
                  onClick={() => startEdit(p)}
                  className="rounded-lg p-2 text-gray-400 transition hover:bg-brand-50 hover:text-brand-600"
                >
                  <Pencil className="h-4 w-4" />
                </button>
                <button
                  onClick={() => remove(p.id)}
                  className="rounded-lg p-2 text-gray-400 transition hover:bg-red-50 hover:text-red-600"
                >
                  <Trash2 className="h-4 w-4" />
                </button>
              </div>
            </div>
            <ul className="divide-y divide-gray-50 px-5 py-2 text-sm text-gray-600">
              <li className="flex justify-between py-2">
                <span>Disk</span>
                <span className="font-medium">{p.disk_limit_mb} MB</span>
              </li>
              <li className="flex justify-between py-2">
                <span>Mailbox</span>
                <span className="font-medium">{p.mailbox_limit}</span>
              </li>
              <li className="flex justify-between py-2">
                <span>Database</span>
                <span className="font-medium">{p.database_limit}</span>
              </li>
              <li className="flex justify-between py-2">
                <span>Domain</span>
                <span className="font-medium">{p.domain_limit}</span>
              </li>
              <li className="flex justify-between py-2">
                <span>Bandwidth</span>
                <span className="font-medium">
                  {p.bandwidth_limit_gb > 0 ? `${p.bandwidth_limit_gb} GB` : "Unlimited"}
                </span>
              </li>
            </ul>
          </div>
        ))}
      </div>
    </div>
  );
}