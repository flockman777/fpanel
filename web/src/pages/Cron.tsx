import { askConfirm } from "../askConfirm";
import { Clock, Pause, Play, Plus, Trash2 } from "lucide-react";
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

interface CronJob {
  id: number;
  account_id: number;
  username: string;
  domain_id: number | null;
  domain: string | null;
  schedule: string;
  command: string;
  description: string | null;
  status: string;
  last_run: string | null;
}

const PRESETS = [
  { label: "Every minute", schedule: "* * * * *" },
  { label: "Every 5 minutes", schedule: "*/5 * * * *" },
  { label: "Every 15 minutes", schedule: "*/15 * * * *" },
  { label: "Hourly", schedule: "0 * * * *" },
  { label: "Daily at 02:00", schedule: "0 2 * * *" },
  { label: "Weekly (Mon 02:00)", schedule: "0 2 * * 1" },
  { label: "Monthly (1st, 04:00)", schedule: "0 4 1 * *" },
];

export default function Cron() {
  const [accounts, setAccounts] = useState<Account[]>([]);
  const [domains, setDomains] = useState<Domain[]>([]);
  const [jobs, setJobs] = useState<CronJob[]>([]);
  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState({ account_id: "", domain_id: "", preset: "", schedule: "", command: "", description: "" });
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
      const [j, a, d] = await Promise.all([
        api<CronJob[]>("/cron"),
        api<Account[]>("/accounts"),
        api<Domain[]>("/domains"),
      ]);
      setJobs(j);
      setAccounts(a);
      setDomains(d);
    } catch (e: any) {
      notify(String(e.message || e), "err");
    }
  };

  useEffect(() => {
    load();
  }, []);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    try {
      await api("/cron", {
        method: "POST",
        body: JSON.stringify({
          account_id: Number(form.account_id),
          domain_id: form.domain_id ? Number(form.domain_id) : null,
          schedule: form.schedule.trim(),
          command: form.command.trim(),
          description: form.description.trim() || null,
        }),
      });
      notify("Cron job created");
      setShowForm(false);
      setForm({ account_id: "", domain_id: "", preset: "", schedule: "", command: "", description: "" });
      load();
    } catch (e: any) {
      notify(String(e.message || e), "err");
    } finally {
      setBusy(false);
    }
  };

  const toggle = async (job: CronJob) => {
    try {
      await api(`/cron/${job.id}`, {
        method: "PUT",
        body: JSON.stringify({ status: job.status === "active" ? "paused" : "active" }),
      });
      load();
    } catch (e: any) {
      notify(String(e.message || e), "err");
    }
  };

  const remove = async (job: CronJob) => {
    if (!await askConfirm(`Delete cron job for ${job.username}?`)) return;
    try {
      await api(`/cron/${job.id}`, { method: "DELETE" });
      notify("Cron job deleted");
      load();
    } catch (e: any) {
      notify(String(e.message || e), "err");
    }
  };

  const base = "w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none";

  return (
    <div className="space-y-6">
      {toast && (
        <div className={`fixed top-4 right-4 z-[60] rounded-lg px-4 py-3 text-sm font-medium text-white shadow-lg ${toast.type === "ok" ? "bg-green-600" : "bg-red-600"}`}>
          {toast.msg}
        </div>
      )}

      <div className="flex items-center justify-between">
        <div>
          <h2 className="flex items-center gap-2 text-xl font-semibold text-gray-800">
            <Clock className="h-5 w-5 text-brand-600" /> Cron Jobs
          </h2>
          <p className="text-sm text-gray-500">Scheduled commands per account</p>
        </div>
        <button onClick={() => setShowForm(!showForm)} className="flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-brand-700">
          <Plus className="h-4 w-4" /> New Cron Job
        </button>
      </div>

      {showForm && (
        <form onSubmit={submit} className="rounded-xl border border-brand-200 bg-brand-50 p-6">
          <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
            <div>
              <label className="mb-1.5 block text-sm font-medium text-gray-700">Account</label>
              <select value={form.account_id} onChange={(e) => setForm({ ...form, account_id: e.target.value })} className={base} required>
                <option value="">Select account...</option>
                {accounts.map((a) => (
                  <option key={a.id} value={a.id}>
                    {a.username}
                  </option>
                ))}
              </select>
            </div>
            <div>
              <label className="mb-1.5 block text-sm font-medium text-gray-700">Domain (optional)</label>
              <select value={form.domain_id} onChange={(e) => setForm({ ...form, domain_id: e.target.value })} className={base}>
                <option value="">None</option>
                {domains
                  .filter((d) => !form.account_id || String(d.account_id) === form.account_id)
                  .map((d) => (
                    <option key={d.id} value={d.id}>
                      {d.name}
                    </option>
                  ))}
              </select>
            </div>
            <div>
              <label className="mb-1.5 block text-sm font-medium text-gray-700">Schedule preset</label>
              <select value={form.preset} onChange={(e) => setForm({ ...form, preset: e.target.value, schedule: e.target.value })} className={base}>
                <option value="">Custom schedule...</option>
                {PRESETS.map((p) => (
                  <option key={p.label} value={p.schedule}>
                    {p.label}
                  </option>
                ))}
              </select>
            </div>
            <div>
              <label className="mb-1.5 block text-sm font-medium text-gray-700">Schedule (5-field cron)</label>
              <input value={form.schedule} onChange={(e) => setForm({ ...form, schedule: e.target.value, preset: "" })} className={base} placeholder="*/5 * * * *" required />
            </div>
            <div>
              <label className="mb-1.5 block text-sm font-medium text-gray-700">Command</label>
              <input value={form.command} onChange={(e) => setForm({ ...form, command: e.target.value })} className={base} placeholder="/usr/bin/php /home/.../cron.php" required />
            </div>
            <div>
              <label className="mb-1.5 block text-sm font-medium text-gray-700">Description</label>
              <input value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} className={base} />
            </div>
          </div>
          <div className="mt-5 flex gap-3">
            <button type="submit" disabled={busy} className="rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-700 disabled:opacity-60">
              {busy ? "Saving..." : "Save Cron Job"}
            </button>
            <button type="button" onClick={() => setShowForm(false)} className="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-600 hover:bg-gray-50">
              Cancel
            </button>
          </div>
        </form>
      )}

      <div className="overflow-hidden rounded-xl border border-gray-200 bg-white">
        <table className="w-full text-left text-sm">
          <thead className="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
            <tr>
              <th className="px-5 py-3">Schedule</th>
              <th className="px-5 py-3">Command</th>
              <th className="px-5 py-3">Domain</th>
              <th className="px-5 py-3">Account</th>
              <th className="px-5 py-3">Status</th>
              <th className="px-5 py-3 text-right">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100">
            {jobs.length === 0 ? (
              <tr>
                <td colSpan={6} className="px-5 py-10 text-center text-gray-400">
                  No cron jobs yet.
                </td>
              </tr>
            ) : (
              jobs.map((j) => (
                <tr key={j.id} className="hover:bg-gray-50">
                  <td className="px-5 py-3 font-mono text-xs font-semibold text-gray-800">{j.schedule}</td>
                  <td className="max-w-[24rem] truncate px-5 py-3 text-xs text-gray-700" title={`${j.command}\n${j.description || ""}`}>
                    <span className="font-mono">{j.command}</span>
                    {j.description && <span className="ml-2 text-gray-400">{j.description}</span>}
                  </td>
                  <td className="px-5 py-3 text-xs text-gray-500">{j.domain || "—"}</td>
                  <td className="px-5 py-3 text-xs text-gray-500">{j.username}</td>
                  <td className="px-5 py-3">
                    <span className={`rounded-full px-2.5 py-1 text-xs font-medium ${j.status === "active" ? "bg-green-50 text-green-700" : "bg-gray-100 text-gray-500"}`}>
                      {j.status}
                    </span>
                  </td>
                  <td className="px-5 py-3">
                    <div className="flex justify-end gap-1.5">
                      <button onClick={() => toggle(j)} className="rounded-lg p-1.5 text-gray-500 transition hover:bg-brand-50 hover:text-brand-700" title={j.status === "active" ? "Pause" : "Resume"}>
                        {j.status === "active" ? <Pause className="h-4 w-4" /> : <Play className="h-4 w-4" />}
                      </button>
                      <button onClick={() => remove(j)} className="rounded-lg p-1.5 text-gray-500 transition hover:bg-red-50 hover:text-red-600" title="Delete">
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