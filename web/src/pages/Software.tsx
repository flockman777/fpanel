import { Boxes, CheckCircle2, ExternalLink, PackageOpen, Plus, Trash2, Users } from "lucide-react";
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

interface ToolsInfo {
  wpcli: boolean;
  composer: boolean;
  php: boolean;
  php_version: string;
  ojs: boolean;
}

interface AppRow {
  id: number;
  account_id: number;
  domain_id: number;
  domain: string;
  app: string;
  path: string;
  version: string | null;
  db_name: string | null;
  db_user: string | null;
  admin_user: string | null;
  admin_email: string | null;
  status: string;
  created_at: string;
}

interface AppsResp {
  rows: AppRow[];
  tools: ToolsInfo;
}

const APPS = [
  { id: "wordpress", label: "WordPress", desc: "Blog, CMS & sites for everyone", color: "bg-sky-50 text-sky-700", reqTool: "wpcli" },
  { id: "laravel", label: "Laravel", desc: "PHP framework with artisan CLI", color: "bg-red-50 text-red-700", reqTool: "composer" },
  { id: "ojs", label: "OJS", desc: "Open Journal Systems reader/journals", color: "bg-amber-50 text-amber-700", reqTool: "ojs" },
];

const appMeta = (id: string) => APPS.find((a) => a.id === id) || APPS[0];

export default function Software() {
  const [accounts, setAccounts] = useState<Account[]>([]);
  const [accountId, setAccountId] = useState("");
  const [allDomains, setAllDomains] = useState<Domain[]>([]);
  const [domains, setDomains] = useState<Domain[]>([]);
  const [rows, setRows] = useState<AppRow[]>([]);
  const [tools, setTools] = useState<ToolsInfo | null>(null);
  const [toast, setToast] = useState<{ type: "ok" | "err"; msg: string } | null>(null);
  const toastTimer = useRef<number>();

  const [showInstall, setShowInstall] = useState(false);
  const [domainId, setDomainId] = useState("");
  const [app, setApp] = useState("wordpress");
  const [siteTitle, setSiteTitle] = useState("");
  const [adminUser, setAdminUser] = useState("admin");
  const [adminPass, setAdminPass] = useState("");
  const [adminEmail, setAdminEmail] = useState("");
  const [busy, setBusy] = useState(false);

  const notify = (msg: string, type: "ok" | "err" = "ok") => {
    setToast({ type, msg });
    window.clearTimeout(toastTimer.current);
    toastTimer.current = window.setTimeout(() => setToast(null), 4000);
  };

  useEffect(() => {
    api<Account[]>("/accounts")
      .then((accs) => {
        setAccounts(accs);
        if (accs[0]) setAccountId(String(accs[0].id));
      })
      .catch((e: any) => notify(String(e.message || e), "err"));
    api<Domain[]>("/domains")
      .then(setAllDomains)
      .catch((e: any) => notify(String(e.message || e), "err"));
  }, []);

  const load = async () => {
    if (!accountId) return;
    try {
      const r = await api<AppsResp>(`/apps?account_id=${accountId}`);
      setRows(r.rows);
      setTools(r.tools);
    } catch (e: any) {
      notify(String(e.message || e), "err");
    }
  };

  useEffect(() => {
    setDomains(allDomains.filter((d) => d.account_id === Number(accountId)));
    setRows([]);
    load();
  }, [accountId, allDomains]);

  const openInstall = () => {
    setShowInstall(true);
    setSiteTitle("");
    setAdminUser("admin");
    setAdminPass("");
    setAdminEmail("");
  };

  const install = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    try {
      await api(`/apps?account_id=${accountId}`, {
        method: "POST",
        body: JSON.stringify({
          domain_id: Number(domainId),
          app,
          site_title: siteTitle || null,
          admin_user: adminUser || null,
          admin_password: adminPass || null,
          admin_email: adminEmail || null,
        }),
      });
      notify("Application installed");
      setShowInstall(false);
      load();
    } catch (err: any) {
      notify(String((err as any).message || err), "err");
    } finally {
      setBusy(false);
    }
  };

  const uninstall = async (r: AppRow) => {
    if (!confirm(`Uninstall ${appMeta(r.app).label} from "${r.domain}"?\n\nThis removes the files and its database (${r.db_name || "n/a"}).`)) return;
    try {
      await api(`/apps/${r.id}?account_id=${accountId}`, { method: "DELETE" });
      notify("Application uninstalled");
      load();
    } catch (e: any) {
      notify(String(e.message || e), "err");
    }
  };

  const base = "w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none";
  const btn = "flex items-center gap-2 rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-brand-700";

  const ready = (name: string) => {
    if (!tools) return true;
    if (name === "wpcli") return tools.wpcli;
    if (name === "composer") return tools.composer;
    return tools.ojs;
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

      <div className="flex items-start justify-between">
        <div>
          <h2 className="text-xl font-semibold text-gray-800">Software</h2>
          <p className="text-sm text-gray-500">
            Install popular applications on an account domain with their own MySQL database
          </p>
        </div>
        <button onClick={openInstall} className={btn}>
          <Plus className="h-3.5 w-3.5" /> Install App
        </button>
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
        {tools && <span className="text-xs text-gray-400">PHP {tools.php_version} · wp-cli · composer</span>}
      </div>

      {tools && (
        <section className="grid grid-cols-2 gap-3 sm:grid-cols-4">
          {[
            { label: "PHP", ok: tools.php, sub: tools.php_version },
            { label: "wp-cli", ok: tools.wpcli, sub: "WordPress" },
            { label: "Composer", ok: tools.composer, sub: "Laravel" },
            { label: "OJS mirror", ok: tools.ojs, sub: "pkp.sfu.ca" },
          ].map((t) => (
            <div key={t.label} className="rounded-xl border border-gray-200 bg-white p-4">
              <div className="flex items-center gap-2">
                {t.ok ? <CheckCircle2 className="h-4 w-4 text-green-600" /> : <span className="text-red-500">✕</span>}
                <span className="text-sm font-semibold text-gray-800">{t.label}</span>
              </div>
              <p className="mt-1 text-xs text-gray-500">{t.ok ? t.sub : "not available"}</p>
            </div>
          ))}
        </section>
      )}

      <section className="rounded-xl border border-gray-200 bg-white p-5">
        <div className="mb-3 flex items-center gap-2">
          <Boxes className="h-4 w-4 text-brand-600" />
          <span className="font-semibold text-gray-800">Installed applications ({rows.length})</span>
        </div>
        {rows.length === 0 ? (
          <p className="text-sm text-gray-500">Nothing installed for this account yet.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead>
                <tr className="border-b border-gray-200 text-xs uppercase tracking-wider text-gray-500">
                  <th className="px-3 py-2">Application</th>
                  <th className="px-3 py-2">Domain</th>
                  <th className="px-3 py-2">Version</th>
                  <th className="px-3 py-2">Database</th>
                  <th className="px-3 py-2">Admin</th>
                  <th className="px-3 py-2 text-right">Actions</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((r) => {
                  const meta = appMeta(r.app);
                  return (
                    <tr key={r.id} className="border-b border-gray-100">
                      <td className="px-3 py-2.5">
                        <span className={`rounded-full px-2.5 py-1 text-xs font-medium ${meta.color}`}>{meta.label}</span>
                      </td>
                      <td className="px-3 py-2.5">
                        <div className="font-medium text-gray-800">{r.domain}</div>
                        <div className="text-xs text-gray-400">{r.path}</div>
                      </td>
                      <td className="px-3 py-2.5 text-xs text-gray-600">{r.version || "-"}</td>
                      <td className="px-3 py-2.5">
                        <div className="font-mono text-xs text-gray-600">{r.db_name || "-"}</div>
                        <div className="font-mono text-xs text-gray-400">{r.db_user || ""}</div>
                      </td>
                      <td className="px-3 py-2.5">
                        <div className="text-xs text-gray-600">{r.admin_user || "-"}</div>
                        <div className="text-xs text-gray-400">{r.admin_email || ""}</div>
                      </td>
                      <td className="px-3 py-2.5">
                        <div className="flex justify-end gap-1.5">
                          <a
                            href={`http://${r.domain}/`}
                            target="_blank"
                            rel="noreferrer"
                            className="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50"
                          >
                            <span className="flex items-center gap-1">
                              Open <ExternalLink className="h-3 w-3" />
                            </span>
                          </a>
                          <button
                            onClick={() => uninstall(r)}
                            className="rounded-lg p-1.5 text-gray-500 transition hover:bg-red-50 hover:text-red-600"
                            title="Uninstall"
                          >
                            <Trash2 className="h-4 w-4" />
                          </button>
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </section>

      {showInstall && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
          <form onSubmit={install} className="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl">
            <div className="mb-4 flex items-center gap-2">
              <PackageOpen className="h-4 w-4 text-brand-600" />
              <h3 className="text-lg font-semibold text-gray-800">Install a new application</h3>
            </div>
            <div className="space-y-4">
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
                <label className="mb-1 block text-xs font-medium text-gray-600">Application</label>
                <div className="grid grid-cols-1 gap-2">
                  {APPS.map((a) => {
                    const ok = ready(a.reqTool);
                    return (
                      <label
                        key={a.id}
                        className={`flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition ${
                          app === a.id ? "border-brand-500 bg-brand-50" : "border-gray-200 hover:bg-gray-50"
                        }`}
                      >
                        <input
                          type="radio"
                          name="app"
                          value={a.id}
                          checked={app === a.id}
                          onChange={() => setApp(a.id)}
                          className="mt-1 h-4 w-4 accent-brand-600"
                          disabled={!ok}
                        />
                        <span>
                          <span className={`block text-sm font-semibold ${ok ? "text-gray-800" : "text-gray-400"}`}>
                            {a.label} {!ok && <span className="text-xs font-normal">(tool unavailable)</span>}
                          </span>
                          <span className="block text-xs text-gray-500">{a.desc}</span>
                        </span>
                      </label>
                    );
                  })}
                </div>
              </div>
              <div>
                <label className="mb-1 block text-xs font-medium text-gray-600">Site title {app === "wordpress" ? "" : "(optional)"}</label>
                <input value={siteTitle} onChange={(e) => setSiteTitle(e.target.value)} className={base} placeholder={app === "wordpress" ? "My Awesome Site" : "Optional"} />
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="mb-1 block text-xs font-medium text-gray-600">Admin username</label>
                  <input value={adminUser} onChange={(e) => setAdminUser(e.target.value)} className={base} required minLength={3} />
                </div>
                <div>
                  <label className="mb-1 block text-xs font-medium text-gray-600">Admin password</label>
                  <input type="password" value={adminPass} onChange={(e) => setAdminPass(e.target.value)} className={base} required minLength={6} />
                </div>
              </div>
              <div>
                <label className="mb-1 block text-xs font-medium text-gray-600">Admin email</label>
                <input type="email" value={adminEmail} onChange={(e) => setAdminEmail(e.target.value)} className={base} required />
              </div>
              <p className="rounded-lg bg-gray-50 p-3 text-xs text-gray-500">
                A dedicated MySQL database and user are created automatically. Installation downloads the software and can take a few minutes.
              </p>
            </div>
            <div className="mt-5 flex justify-end gap-2">
              <button type="button" onClick={() => setShowInstall(false)} disabled={busy} className="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50">
                Cancel
              </button>
              <button disabled={busy} className={btn + " disabled:opacity-60"}>
                {busy ? "Installing..." : "Install"}
              </button>
            </div>
          </form>
        </div>
      )}
    </div>
  );
}