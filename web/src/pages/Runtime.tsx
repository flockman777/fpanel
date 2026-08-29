import {
  Play,
  Plus,
  RotateCw,
  Server,
  Square,
  TerminalSquare,
  Trash2,
  Users,
} from "lucide-react";
import { useEffect, useRef, useState } from "react";
import { api } from "../App";

interface Account {
  id: number;
  username: string;
}

interface Domain {
  id: number;
  account_id: number;
  username: string;
  name: string;
  kind: string;
  status: string;
}

interface RunApp {
  id: number;
  account_id: number;
  domain_id: number;
  domain: string;
  app: string;
  runtime: string;
  entrypoint: string;
  port: number;
  auto_restart: boolean;
  status: string;
  pid: number | null;
  created_at: string;
  env: string | null;
}

interface Toolchain {
  id: string;
  label: string;
  available: boolean;
  version: string | null;
}

interface ListResp {
  apps: RunApp[];
  toolchains: Toolchain[];
}

const RUNTIME_LABELS: Record<string, string> = {
  node: "Node.js",
  python: "Python",
  php: "PHP",
  bun: "Bun",
  deno: "Deno",
  go: "Go",
  ruby: "Ruby",
};

const runtimeColor: Record<string, string> = {
  node: "bg-green-50 text-green-700",
  python: "bg-blue-50 text-blue-700",
  php: "bg-indigo-50 text-indigo-700",
  bun: "bg-red-50 text-red-700",
  deno: "bg-gray-100 text-gray-600",
  go: "bg-cyan-50 text-cyan-700",
  ruby: "bg-rose-50 text-rose-700",
};

export default function Runtime() {
  const [accounts, setAccounts] = useState<Account[]>([]);
  const [accountId, setAccountId] = useState("");
  const [allDomains, setAllDomains] = useState<Domain[]>([]);
  const [domains, setDomains] = useState<Domain[]>([]);
  const [rows, setRows] = useState<RunApp[]>([]);
  const [toolchains, setToolchains] = useState<Toolchain[]>([]);
  const [showForm, setShowForm] = useState(false);
  const [toast, setToast] = useState<{ type: "ok" | "err"; msg: string } | null>(null);
  const toastTimer = useRef<number>();

  const [domainId, setDomainId] = useState("");
  const [runtime, setRuntime] = useState("node");
  const [entrypoint, setEntrypoint] = useState("server.js");
  const [port, setPort] = useState("3000");
  const [env, setEnv] = useState("");
  const [auto, setAuto] = useState(false);
  const [busy, setBusy] = useState(false);

  const [logApp, setLogApp] = useState<RunApp | null>(null);
  const [logLines, setLogLines] = useState<string[]>([]);

  const notify = (msg: string, type: "ok" | "err" = "ok") => {
    setToast({ type, msg });
    window.clearTimeout(toastTimer.current);
    toastTimer.current = window.setTimeout(() => setToast(null), 4000);
  };

  const load = async () => {
    if (!accountId) return;
    const q = `?account_id=${accountId}`;
    try {
      const r = await api<ListResp>("/runtime" + q);
      setRows(r.apps);
      setToolchains(r.toolchains);
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
    api<Domain[]>("/domains")
      .then((doms) => setAllDomains(doms))
      .catch((e: any) => notify(String(e.message || e), "err"));
  }, []);

  useEffect(() => {
    if (!accountId) return;
    const acc = Number(accountId);
    setDomains(allDomains.filter((d) => d.account_id === acc));
    setRows([]);
    load();
  }, [accountId, allDomains]);

  useEffect(() => {
    if (domains[0]) setDomainId(String(domains[0].id));
  }, [domains]);

  const create = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    try {
      await api(`/runtime?account_id=${accountId}`, {
        method: "POST",
        body: JSON.stringify({
          domain_id: Number(domainId),
          runtime,
          entrypoint: entrypoint.trim(),
          port: Number(port),
          env: env.trim() || null,
          auto_restart: auto,
        }),
      });
      notify("App created");
      setShowForm(false);
      setEnv("");
      load();
    } catch (err: any) {
      notify(String(err.message || err), "err");
    } finally {
      setBusy(false);
    }
  };

  const act = async (app: RunApp, action: string) => {
    try {
      await api(`/runtime/${app.id}/${action}?account_id=${accountId}`, { method: "POST" });
      notify(`App ${action === "stop" ? "stopped" : action === "start" ? "started" : "restarted"}`);
      load();
    } catch (e: any) {
      notify(String(e.message || e), "err");
    }
  };

  const drop = async (app: RunApp) => {
    if (!confirm(`Delete the runtime app for "${app.domain}"?`)) return;
    try {
      await api(`/runtime/${app.id}?account_id=${accountId}`, { method: "DELETE" });
      notify("App deleted");
      load();
    } catch (e: any) {
      notify(String(e.message || e), "err");
    }
  };

  const openLog = async (app: RunApp) => {
    setLogApp(app);
    setLogLines([]);
    try {
      const r = await api<{ lines: string[] }>(`/runtime/${app.id}/log?account_id=${accountId}&lines=200`);
      setLogLines(r.lines);
    } catch (e: any) {
      notify(String(e.message || e), "err");
    }
  };

  const base = "w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none";
  const btn = "flex items-center gap-2 rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-brand-700";
  const btnGhost = "flex items-center gap-2 rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50";

  const statusChip = (a: RunApp) =>
    a.status === "running" ? (
      <span className="inline-flex items-center gap-1.5 rounded-full bg-green-50 px-2.5 py-1 text-xs font-medium text-green-700">
        <span className="h-1.5 w-1.5 animate-pulse rounded-full bg-green-500" />
        Running{typeof a.pid === "number" ? ` · pid ${a.pid}` : ""}
      </span>
    ) : a.status === "error" ? (
      <span className="rounded-full bg-red-50 px-2.5 py-1 text-xs font-medium text-red-600">Error</span>
    ) : (
      <span className="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-500">Stopped</span>
    );

  const envNames = (env: string | null) => {
    if (!env) return "-";
    try {
      return Object.keys(JSON.parse(env)).join(", ") || "-";
    } catch {
      return "-";
    }
  };

  return (
    <div className="space-y-6">
      {toast && (
        <div
          className={`fixed top-4 right-4 z-[60] rounded-lg px-4 py-3 text-sm font-medium text-white shadow-lg ${
            toast.type === "ok" ? "bg-green-600" : "bg-red-600"
          }`}
        >
          {toast.msg}
        </div>
      )}

      <div>
        <h2 className="text-xl font-semibold text-gray-800">Runtime Manager</h2>
        <p className="text-sm text-gray-500">
          Run apps on your accounts' domains through the Pingora web server
        </p>
      </div>

      {toolchains.length > 0 && (
        <section className="rounded-xl border border-gray-200 bg-white p-5">
          <div className="mb-3 flex items-center gap-2 text-gray-800">
            <Server className="h-4 w-4 text-brand-600" />
            <span className="font-semibold">Runtimes available on this server</span>
          </div>
          <div className="flex flex-wrap gap-2">
            {toolchains.map((t) => (
              <span
                key={t.id}
                className={`inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-medium ${
                  t.available
                    ? "border-green-200 bg-green-50 text-green-700"
                    : "border-gray-200 bg-gray-50 text-gray-400"
                }`}
              >
                {t.label}
                {t.available ? (
                  <span className="hidden font-normal text-green-600 sm:inline">{t.version}</span>
                ) : (
                  <span className="font-normal">not installed</span>
                )}
              </span>
            ))}
          </div>
        </section>
      )}

      <section className="rounded-xl border border-gray-200 bg-white p-5">
        <div className="mb-4 flex flex-wrap items-center gap-3">
          <div className="flex items-center gap-2 text-gray-800">
            <Users className="h-4 w-4 text-brand-600" />
            <span className="font-semibold">Account</span>
          </div>
          <select
            value={accountId}
            onChange={(e) => setAccountId(e.target.value)}
            className="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none"
          >
            {accounts.map((a) => (
              <option key={a.id} value={a.id}>
                {a.username}
              </option>
            ))}
          </select>
          <button onClick={() => setShowForm((v) => !v)} className={btn + " ml-auto"}>
            <Plus className="h-3.5 w-3.5" /> {showForm ? "Close" : "Create App"}
          </button>
        </div>

        {showForm && (
          <form onSubmit={create} className="mb-5 rounded-lg border border-brand-200 bg-brand-50 p-4">
            <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
              <div>
                <label className="mb-1 block text-xs font-medium text-gray-600">Domain</label>
                <select value={domainId} onChange={(e) => setDomainId(e.target.value)} className={base}>
                  {domains.map((d) => (
                    <option key={d.id} value={d.id}>
                      {d.name}
                    </option>
                  ))}
                </select>
              </div>
              <div>
                <label className="mb-1 block text-xs font-medium text-gray-600">Runtime</label>
                <select value={runtime} onChange={(e) => setRuntime(e.target.value)} className={base}>
                  {Object.entries(RUNTIME_LABELS).map(([id, label]) => (
                    <option key={id} value={id}>
                      {label}
                    </option>
                  ))}
                </select>
              </div>
              <div>
                <label className="mb-1 block text-xs font-medium text-gray-600">Port</label>
                <input type="number" min={1} max={65535} value={port} onChange={(e) => setPort(e.target.value)} className={base} required />
              </div>
              <div>
                <label className="mb-1 block text-xs font-medium text-gray-600">Entry point</label>
                <input value={entrypoint} onChange={(e) => setEntrypoint(e.target.value)} className={base} placeholder="server.js" required />
              </div>
              <div>
                <label className="mb-1 block text-xs font-medium text-gray-600">Env vars (JSON)</label>
                <input value={env} onChange={(e) => setEnv(e.target.value)} className={base} placeholder='{"NODE_ENV":"production"}' />
              </div>
              <div>
                <label className="mb-1 block text-xs font-medium text-gray-600">Auto-restart</label>
                <label className="flex items-center gap-2 pt-2.5">
                  <input type="checkbox" checked={auto} onChange={(e) => setAuto(e.target.checked)} className="h-4 w-4 rounded accent-brand-600" />
                  <span className="text-sm text-gray-600">Restart on crash</span>
                </label>
              </div>
            </div>
            <div className="mt-4 flex items-center justify-between">
              <p className="text-xs text-gray-500">
                The app runs from the account's document root; the <code className="rounded bg-gray-100 px-1">PORT</code> variable is set automatically.
              </p>
              <button disabled={busy} className={btn + " disabled:opacity-60"}>
                {busy ? "Creating..." : "Create App"}
              </button>
            </div>
          </form>
        )}

        {accountId && domains.length === 0 ? (
          <p className="text-sm text-gray-500">No domains for this account.</p>
        ) : rows.length === 0 ? (
          <p className="text-sm text-gray-500">No runtime apps for this account.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead>
                <tr className="border-b border-gray-200 text-xs uppercase tracking-wider text-gray-500">
                  <th className="px-3 py-2">Domain</th>
                  <th className="px-3 py-2">Runtime</th>
                  <th className="px-3 py-2">Entry point</th>
                  <th className="px-3 py-2">Port</th>
                  <th className="px-3 py-2">Status</th>
                  <th className="px-3 py-2 text-right">Actions</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((a) => (
                  <tr key={a.id} className="border-b border-gray-100">
                    <td className="px-3 py-2.5">
                      <div className="font-medium text-gray-800">{a.domain}</div>
                      <div className="text-xs text-gray-400">{envNames(a.env)}</div>
                    </td>
                    <td className="px-3 py-2.5">
                      <span className={`rounded-full px-2.5 py-1 text-xs font-medium ${runtimeColor[a.runtime] || "bg-gray-100 text-gray-600"}`}>
                        {RUNTIME_LABELS[a.runtime] || a.runtime}
                      </span>
                    </td>
                    <td className="px-3 py-2.5 font-mono text-xs text-gray-600">{a.entrypoint}</td>
                    <td className="px-3 py-2.5 text-gray-600">{a.port}</td>
                    <td className="px-3 py-2.5">{statusChip(a)}</td>
                    <td className="px-3 py-2.5">
                      <div className="flex justify-end gap-1.5">
                        {a.status === "running" ? (
                          <>
                            <button onClick={() => act(a, "restart")} className={btnGhost} title="Restart">
                              <RotateCw className="h-3.5 w-3.5" />
                            </button>
                            <button onClick={() => act(a, "stop")} className={btnGhost} title="Stop">
                              <Square className="h-3.5 w-3.5" />
                            </button>
                          </>
                        ) : (
                          <button onClick={() => act(a, "start")} className={btn} title="Start">
                            <Play className="h-3.5 w-3.5" /> Start
                          </button>
                        )}
                        <button onClick={() => openLog(a)} className={btnGhost} title="View log">
                          <TerminalSquare className="h-3.5 w-3.5" />
                        </button>
                        <button
                          onClick={() => drop(a)}
                          className="rounded-lg p-1.5 text-gray-500 transition hover:bg-red-50 hover:text-red-600"
                          title="Delete app"
                        >
                          <Trash2 className="h-4 w-4" />
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

      {logApp && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
          <div className="w-full max-w-3xl rounded-xl bg-white p-6 shadow-xl">
            <div className="mb-3 flex items-center justify-between">
              <div className="flex items-center gap-2">
                <TerminalSquare className="h-4 w-4 text-brand-600" />
                <h3 className="text-lg font-semibold text-gray-800">
                  Log · {logApp.domain}
                </h3>
              </div>
              <button
                onClick={() => setLogApp(null)}
                className="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-50"
              >
                Close
              </button>
            </div>
            <pre className="max-h-[60vh] overflow-auto rounded-lg bg-gray-900 p-4 font-mono text-xs leading-5 text-green-400">
              {logLines.length ? logLines.join("\n") : "(no output yet)"}
            </pre>
          </div>
        </div>
      )}
    </div>
  );
}