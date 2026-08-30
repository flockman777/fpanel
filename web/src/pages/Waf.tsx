import { askConfirm } from "../askConfirm";
import { Flame, RotateCcw, Save, Users } from "lucide-react";
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
  kind: string;
}

interface WafRule {
  id: string;
  name: string;
  pattern: string;
  severity: string;
  action: string;
}

interface Row {
  id: number;
  domain_id: number;
  domain: string;
  enabled: boolean;
  mode: string;
  rules: WafRule[];
}

export default function Waf() {
  const [accounts, setAccounts] = useState<Account[]>([]);
  const [accountId, setAccountId] = useState("");
  const [domains, setDomains] = useState<Domain[]>([]);
  const [rows, setRows] = useState<Row[]>([]);
  const [toast, setToast] = useState<{ type: "ok" | "err"; msg: string } | null>(null);
  const toastTimer = useRef<number>();
  const [busy, setBusy] = useState(false);
  const [expanded, setExpanded] = useState<number | null>(null);

  const notify = (msg: string, type: "ok" | "err" = "ok") => {
    setToast({ type, msg });
    window.clearTimeout(toastTimer.current);
    toastTimer.current = window.setTimeout(() => setToast(null), 4000);
  };

  const load = async () => {
    if (!accountId) return;
    try {
      const [list, ds] = await Promise.all([
        api<Row[]>(`/waf?account_id=${accountId}`),
        api<Domain[]>(`/domains?account_id=${accountId}`),
      ]);
      setRows(list);
      setDomains(ds);
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
  }, []);

  useEffect(() => {
    if (accountId) load();
  }, [accountId]);

  const defaults = async (): Promise<WafRule[]> => {
    try {
      return await api<WafRule[]>(`/waf/defaults?account_id=${accountId}`);
    } catch {
      return [];
    }
  };

  const save = async (row: Row) => {
    setBusy(true);
    try {
      await api("/waf", {
        method: "PUT",
        body: JSON.stringify({
          account_id: Number(accountId),
          domain_id: row.domain_id,
          enabled: row.enabled,
          mode: row.mode,
          rules: row.rules,
        }),
      });
      notify("Saved");
      load();
    } catch (e: any) {
      notify(String(e.message || e), "err");
    } finally {
      setBusy(false);
    }
  };

  const remove = async (row: Row) => {
    if (!await askConfirm(`Disable WAF for "${row.domain}"?`)) return;
    try {
      await api(`/waf/${row.id}?account_id=${accountId}`, { method: "DELETE" });
      notify("WAF disabled");
      load();
    } catch (e: any) {
      notify(String(e.message || e), "err");
    }
  };

  const setRow = (i: number, patch: Partial<Row>) =>
    setRows(rows.map((r, idx) => (idx === i ? { ...r, ...patch } : r)));

  const toggleRule = (i: number, ruleId: string) => {
    setRows(
      rows.map((r, idx) =>
        idx === i
          ? {
              ...r,
              rules: r.rules.map((rule) =>
                rule.id === ruleId ? { ...rule, action: rule.action === "block" ? "ignore" : "block" } : rule
              ),
            }
          : r
      )
    );
  };

  const base = "w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none";
  const btn = "flex items-center gap-2 rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-brand-700 disabled:opacity-60";
  const btnGhost = "flex items-center gap-2 rounded-lg border border-gray-300 px-2.5 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50";

  const notProtected = domains.filter((d) => !rows.some((r) => r.domain_id === d.id));

  return (
    <div className="space-y-6">
      {toast && (
        <div className={`fixed top-4 right-4 z-[60] rounded-lg px-4 py-3 text-sm font-medium text-white shadow-lg ${toast.type === "ok" ? "bg-green-600" : "bg-red-600"}`}>
          {toast.msg}
        </div>
      )}

      <div>
        <h2 className="text-xl font-semibold text-gray-800">ModSecurity / WAF</h2>
        <p className="text-sm text-gray-500">Application firewall rules for each domain</p>
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
      </div>

      {notProtected.length > 0 && (
        <section className="rounded-xl border border-dashed border-gray-300 bg-white p-5">
          <p className="mb-2 flex items-center gap-2 text-sm font-semibold text-gray-700">
            <Flame className="h-4 w-4 text-brand-600" /> Enable firewall
          </p>
          <div className="flex flex-wrap gap-2">
            {notProtected.map((d) => (
              <button
                key={d.id}
                onClick={async () => {
                  try {
                    const rules = await defaults();
                    const r = await api<Row>("/waf", {
                      method: "PUT",
                      body: JSON.stringify({ account_id: Number(accountId), domain_id: d.id, enabled: true, mode: "block", rules }),
                    });
                    setRows([r, ...rows]);
                    notify("WAF enabled for " + d.name);
                  } catch (e: any) {
                    notify(String(e.message || e), "err");
                  }
                }}
                className="rounded-full border border-brand-200 bg-brand-50 px-3 py-1.5 text-xs font-medium text-brand-700 hover:bg-brand-100"
              >
                {d.name}
              </button>
            ))}
          </div>
        </section>
      )}

      <section className="rounded-xl border border-gray-200 bg-white p-5">
        {rows.length === 0 ? (
          <p className="py-4 text-center text-sm text-gray-400">No WAF rule sets configured.</p>
        ) : (
          <div className="space-y-4">
            {rows.map((row, i) => (
              <div key={row.domain_id} className="rounded-lg border border-gray-200 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <div className="flex items-center gap-3">
                    <span className="text-sm font-semibold text-gray-800">{row.domain}</span>
                    <span className={`rounded-full px-2.5 py-1 text-xs font-medium ${row.enabled ? "bg-green-50 text-green-700" : "bg-gray-100 text-gray-500"}`}>
                      {row.enabled ? "Enabled" : "Disabled"}
                    </span>
                    <span className={`rounded-full px-2.5 py-1 text-xs font-medium ${row.mode === "block" ? "bg-red-50 text-red-700" : "bg-amber-50 text-amber-700"}`}>
                      {row.mode === "block" ? "Block" : "Log only"}
                    </span>
                  </div>
                  <div className="flex items-center gap-2">
                    <label className="inline-flex cursor-pointer items-center gap-1.5 text-xs text-gray-600">
                      <input type="checkbox" checked={row.enabled} onChange={(e) => setRow(i, { enabled: e.target.checked })} />
                      Enable
                    </label>
                    <select
                      value={row.mode}
                      onChange={(e) => setRow(i, { mode: e.target.value })}
                      className="rounded-lg border border-gray-300 px-2 py-1.5 text-xs"
                    >
                      <option value="block">Block</option>
                      <option value="log">Log only</option>
                    </select>
                    <button onClick={() => save(row)} disabled={busy} className={btn}>
                      <Save className="h-3.5 w-3.5" /> Save
                    </button>
                    <button onClick={() => remove(row)} className={btnGhost}>
                      <RotateCcw className="h-3.5 w-3.5" /> Disable
                    </button>
                  </div>
                </div>

                <button
                  onClick={() => setExpanded(expanded === row.domain_id ? null : row.domain_id)}
                  className="mt-3 text-xs font-medium text-brand-700 hover:underline"
                >
                  {expanded === row.domain_id ? "Hide rules" : "Show rules (" + row.rules.filter((r) => r.action === "block").length + " active)"}
                </button>

                {expanded === row.domain_id && (
                  <div className="mt-3 grid grid-cols-1 gap-2 md:grid-cols-2">
                    {(row.rules || []).map((rule) => (
                      <label key={rule.id} className="flex cursor-pointer items-start gap-2 rounded-lg border border-gray-100 px-3 py-2 text-xs text-gray-700">
                        <input type="checkbox" checked={rule.action === "block"} onChange={() => toggleRule(i, rule.id)} className="mt-0.5" />
                        <span>
                          <span className="font-semibold">{rule.name}</span>
                          <span className="ml-1 text-gray-400">({rule.severity})</span>
                          <div className="mt-0.5 font-mono text-[11px] text-gray-500">{rule.pattern}</div>
                        </span>
                      </label>
                    ))}
                  </div>
                )}
              </div>
            ))}
          </div>
        )}
      </section>
    </div>
  );
}