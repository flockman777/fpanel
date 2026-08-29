import {
  ExternalLink,
  Forward,
  KeyRound,
  Mail,
  MailWarning,
  Plus,
  Reply,
  Save,
  Trash2,
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

interface AccountRow {
  id: number;
  account_id: number;
  domain_id: number;
  domain: string;
  local: string;
  address: string;
  forward_to: string | null;
  quota_mb: number;
  status: string;
}

interface Forwarder {
  id: number;
  domain_id: number;
  domain: string;
  from: string;
  to: string;
  status: string;
}

interface AutoResp {
  id: number;
  domain_id: number;
  domain: string;
  local: string;
  address: string;
  subject: string;
  body: string;
  start_date: string | null;
  end_date: string | null;
  status: string;
}

interface DefaultRow {
  id: number;
  domain_id: number;
  domain: string;
  action: string;
  forward_to: string | null;
}

type Tab = "accounts" | "forwarders" | "autoresponders" | "default";

export default function Email() {
  const [tab, setTab] = useState<Tab>("accounts");
  const [accounts, setAccounts] = useState<Account[]>([]);
  const [accountId, setAccountId] = useState("");
  const [allDomains, setAllDomains] = useState<Domain[]>([]);
  const [domains, setDomains] = useState<Domain[]>([]);
  const [rows, setRows] = useState<AccountRow[]>([]);
  const [forwarders, setForwarders] = useState<Forwarder[]>([]);
  const [resps, setResps] = useState<AutoResp[]>([]);
  const [defaults, setDefaults] = useState<DefaultRow[]>([]);
  const [toast, setToast] = useState<{ type: "ok" | "err"; msg: string } | null>(null);
  const toastTimer = useRef<number>();

  const [acctDomain, setAcctDomain] = useState("");
  const [acctLocal, setAcctLocal] = useState("");
  const [acctPass, setAcctPass] = useState("");
  const [acctQuota, setAcctQuota] = useState("256");

  const [fwDomain, setFwDomain] = useState("");
  const [fwFrom, setFwFrom] = useState("");
  const [fwTo, setFwTo] = useState("");

  const [arDomain, setArDomain] = useState("");
  const [arLocal, setArLocal] = useState("");
  const [arSubject, setArSubject] = useState("");
  const [arBody, setArBody] = useState("");
  const [arStart, setArStart] = useState("");
  const [arEnd, setArEnd] = useState("");

  const [pwd, setPwd] = useState<AccountRow | null>(null);
  const [pwdVal, setPwdVal] = useState("");
  const [dft, setDft] = useState<Record<number, { action: string; to: string }>>({});

  const notify = (msg: string, type: "ok" | "err" = "ok") => {
    setToast({ type, msg });
    window.clearTimeout(toastTimer.current);
    toastTimer.current = window.setTimeout(() => setToast(null), 4000);
  };

  const load = async () => {
    if (!accountId) return;
    const q = `?account_id=${accountId}`;
    try {
      const [a, f, r, d] = await Promise.all([
        api<AccountRow[]>("/email/accounts" + q),
        api<Forwarder[]>("/email/forwarders" + q),
        api<AutoResp[]>("/email/autoresponders" + q),
        api<DefaultRow[]>("/email/default" + q),
      ]);
      setRows(a);
      setForwarders(f);
      setResps(r);
      setDefaults(d);
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
    setForwarders([]);
    setResps([]);
    setDefaults([]);
    load();
  }, [accountId, allDomains]);

  useEffect(() => {
    if (domains.length > 0) {
      if (!acctDomain) setAcctDomain(String(domains[0].id));
      if (!fwDomain) setFwDomain(String(domains[0].id));
      if (!arDomain) setArDomain(String(domains[0].id));
    }
  }, [domains]);

  useEffect(() => {
    const init: Record<number, { action: string; to: string }> = {};
    for (const d of defaults) init[d.domain_id] = { action: d.action, to: d.forward_to || "" };
    setDft(init);
  }, [defaults]);

  const domainName = (id: number) => domains.find((d) => d.id === id)?.name || "";

  const createAccount = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      await api("/email/accounts", {
        method: "POST",
        body: JSON.stringify({
          account_id: Number(accountId),
          domain_id: Number(acctDomain),
          local: acctLocal.trim(),
          password: acctPass,
          quota_mb: Number(acctQuota) || 256,
        }),
      });
      setAcctLocal("");
      setAcctPass("");
      notify("Email account created");
      load();
    } catch (err: any) {
      notify(String(err.message || err), "err");
    }
  };

  const dropAccount = async (a: AccountRow) => {
    if (!confirm(`Delete email account "${a.address}"?`)) return;
    try {
      await api(`/email/accounts/${a.id}?account_id=${accountId}`, { method: "DELETE" });
      notify("Email account deleted");
      load();
    } catch (e: any) {
      notify(String(e.message || e), "err");
    }
  };

  const changePwd = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!pwd) return;
    try {
      await api(`/email/accounts/${pwd.id}/password?account_id=${accountId}`, {
        method: "POST",
        body: JSON.stringify({ password: pwdVal }),
      });
      notify("Password updated");
      setPwd(null);
      setPwdVal("");
    } catch (err: any) {
      notify(String(err.message || err), "err");
    }
  };

  const createForwarder = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      await api("/email/forwarders", {
        method: "POST",
        body: JSON.stringify({
          account_id: Number(accountId),
          domain_id: Number(fwDomain),
          from: fwFrom.trim(),
          to: fwTo.trim(),
        }),
      });
      setFwFrom("");
      setFwTo("");
      notify("Forwarder created");
      load();
    } catch (err: any) {
      notify(String(err.message || err), "err");
    }
  };

  const dropForwarder = async (f: Forwarder) => {
    if (!confirm(`Delete forwarder "${f.from}"?`)) return;
    try {
      await api(`/email/forwarders/${f.id}?account_id=${accountId}`, { method: "DELETE" });
      notify("Forwarder deleted");
      load();
    } catch (e: any) {
      notify(String(e.message || e), "err");
    }
  };

  const createResp = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      await api("/email/autoresponders", {
        method: "POST",
        body: JSON.stringify({
          account_id: Number(accountId),
          domain_id: Number(arDomain),
          local: arLocal.trim(),
          subject: arSubject,
          body: arBody,
          start_date: arStart || null,
          end_date: arEnd || null,
        }),
      });
      setArLocal("");
      setArSubject("");
      setArBody("");
      setArStart("");
      setArEnd("");
      notify("Autoresponder created");
      load();
    } catch (err: any) {
      notify(String(err.message || err), "err");
    }
  };

  const dropResp = async (r: AutoResp) => {
    if (!confirm(`Delete autoresponder for "${r.address}"?`)) return;
    try {
      await api(`/email/autoresponders/${r.id}?account_id=${accountId}`, { method: "DELETE" });
      notify("Autoresponder deleted");
      load();
    } catch (e: any) {
      notify(String(e.message || e), "err");
    }
  };

  const saveDefault = async (d: Domain) => {
    const v = dft[d.id] || { action: "discard", to: "" };
    try {
      await api("/email/default", {
        method: "POST",
        body: JSON.stringify({
          account_id: Number(accountId),
          domain_id: d.id,
          action: v.action,
          forward_to: v.action === "forward" ? v.to : null,
        }),
      });
      notify("Default address updated");
      load();
    } catch (e: any) {
      notify(String(e.message || e), "err");
    }
  };

  const base = "w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none";
  const btn = "flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-700";
  const btnSm = "flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-brand-700";
  const chip = "rounded-full bg-brand-50 px-2.5 py-1 text-xs font-medium text-brand-700";

  const tabs: { key: Tab; label: string }[] = [
    { key: "accounts", label: "Email Accounts" },
    { key: "forwarders", label: "Forwarders" },
    { key: "autoresponders", label: "Autoresponders" },
    { key: "default", label: "Default Address" },
  ];

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

      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 className="text-xl font-semibold text-gray-800">Email</h2>
          <p className="text-sm text-gray-500">
            Manage email accounts, forwarders and autoresponders for hosting accounts
          </p>
        </div>
        <select
          value={accountId}
          onChange={(e) => setAccountId(e.target.value)}
          className="rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none"
        >
          <option value="">Select account...</option>
          {accounts.map((a) => (
            <option key={a.id} value={a.id}>
              {a.username}
            </option>
          ))}
        </select>
      </div>

      {!accountId ? (
        <p className="text-sm text-gray-500">Select a hosting account to manage its email.</p>
      ) : (
        <>
          <div className="flex flex-wrap gap-2">
            {tabs.map((t) => (
              <button
                key={t.key}
                onClick={() => setTab(t.key)}
                className={`rounded-lg px-4 py-2 text-sm font-medium transition ${
                  tab === t.key
                    ? "bg-brand-600 text-white"
                    : "border border-gray-300 bg-white text-gray-600 hover:bg-gray-50"
                }`}
              >
                {t.label}
              </button>
            ))}
          </div>

          {tab === "accounts" && (
            <div className="space-y-6">
              <section className="rounded-xl border border-brand-200 bg-brand-50/50 p-5">
                <div className="mb-3 flex items-center gap-2 text-brand-700">
                  <Plus className="h-4 w-4" />
                  <span className="font-semibold">Create Email Address</span>
                </div>
                <form onSubmit={createAccount} className="grid grid-cols-1 gap-3 sm:grid-cols-6">
                  <div className="sm:col-span-2">
                    <label className="mb-1 block text-xs font-medium text-gray-600">Local part</label>
                    <input
                      className={base}
                      value={acctLocal}
                      onChange={(e) => setAcctLocal(e.target.value)}
                      placeholder="john"
                      required
                    />
                  </div>
                  <div className="sm:col-span-2">
                    <label className="mb-1 block text-xs font-medium text-gray-600">Domain</label>
                    <select
                      className={base}
                      value={acctDomain}
                      onChange={(e) => setAcctDomain(e.target.value)}
                    >
                      {domains.map((d) => (
                        <option key={d.id} value={d.id}>
                          {d.name}
                        </option>
                      ))}
                    </select>
                  </div>
                  <div className="flex items-end pb-1 text-sm text-gray-500">
                    →&nbsp;
                    <code className="rounded bg-white px-1.5 py-0.5">
                      {acctLocal || "john"}@{acctDomain ? domainName(Number(acctDomain)) : "domain"}
                    </code>
                  </div>
                  <div className="sm:col-span-2">
                    <label className="mb-1 block text-xs font-medium text-gray-600">Password</label>
                    <input
                      type="password"
                      className={base}
                      value={acctPass}
                      onChange={(e) => setAcctPass(e.target.value)}
                      minLength={6}
                      required
                    />
                  </div>
                  <div className="sm:col-span-2">
                    <label className="mb-1 block text-xs font-medium text-gray-600">Quota (MB)</label>
                    <input
                      type="number"
                      className={base}
                      value={acctQuota}
                      onChange={(e) => setAcctQuota(e.target.value)}
                      min={0}
                    />
                  </div>
                  <div className="flex items-end sm:col-span-2">
                    <button className={btn}>
                      <Plus className="h-4 w-4" /> Create
                    </button>
                  </div>
                </form>
              </section>

              <section className="rounded-xl border border-gray-200 bg-white p-5">
                <div className="mb-3 flex items-center gap-2 text-gray-800">
                  <Mail className="h-4 w-4 text-brand-600" />
                  <span className="font-semibold">Email Accounts ({rows.length})</span>
                </div>
                {rows.length === 0 ? (
                  <p className="text-sm text-gray-500">No email accounts yet.</p>
                ) : (
                  <div className="overflow-x-auto">
                    <table className="w-full text-left text-sm">
                      <thead>
                        <tr className="border-b border-gray-200 text-xs uppercase tracking-wider text-gray-500">
                          <th className="px-3 py-2">Address</th>
                          <th className="px-3 py-2">Quota</th>
                          <th className="px-3 py-2">Forward To</th>
                          <th className="px-3 py-2">Status</th>
                          <th className="px-3 py-2 text-right">Actions</th>
                        </tr>
                      </thead>
                      <tbody>
                        {rows.map((a) => (
                          <tr key={a.id} className="border-b border-gray-100">
                            <td className="px-3 py-2.5 font-medium text-gray-800">{a.address}</td>
                            <td className="px-3 py-2.5 text-gray-600">{a.quota_mb} MB</td>
                            <td className="px-3 py-2.5">
                              {a.forward_to ? (
                                <span className={chip}>{a.forward_to}</span>
                              ) : (
                                <span className="text-gray-400">-</span>
                              )}
                            </td>
                            <td className="px-3 py-2.5">
                              <span className={chip}>{a.status}</span>
                            </td>
                            <td className="px-3 py-2.5">
                              <div className="flex justify-end gap-2">
                                <a
                                  href={`https://webmail.${a.domain}`}
                                  target="_blank"
                                  rel="noreferrer"
                                  className="rounded-lg p-1.5 text-gray-500 transition hover:bg-brand-50 hover:text-brand-600"
                                  title="Open webmail"
                                >
                                  <ExternalLink className="h-4 w-4" />
                                </a>
                                <button
                                  onClick={() => {
                                    setPwd(a);
                                    setPwdVal("");
                                  }}
                                  className="rounded-lg p-1.5 text-gray-500 transition hover:bg-brand-50 hover:text-brand-600"
                                  title="Change password"
                                >
                                  <KeyRound className="h-4 w-4" />
                                </button>
                                <button
                                  onClick={() => dropAccount(a)}
                                  className="rounded-lg p-1.5 text-gray-500 transition hover:bg-red-50 hover:text-red-600"
                                  title="Delete"
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
            </div>
          )}

          {tab === "forwarders" && (
            <div className="space-y-6">
              <section className="rounded-xl border border-brand-200 bg-brand-50/50 p-5">
                <div className="mb-3 flex items-center gap-2 text-brand-700">
                  <Forward className="h-4 w-4" />
                  <span className="font-semibold">Add Forwarder</span>
                </div>
                <form onSubmit={createForwarder} className="grid grid-cols-1 gap-3 sm:grid-cols-4">
                  <div className="sm:col-span-1">
                    <label className="mb-1 block text-xs font-medium text-gray-600">Domain</label>
                    <select
                      className={base}
                      value={fwDomain}
                      onChange={(e) => setFwDomain(e.target.value)}
                    >
                      {domains.map((d) => (
                        <option key={d.id} value={d.id}>
                          {d.name}
                        </option>
                      ))}
                    </select>
                  </div>
                  <div className="sm:col-span-2">
                    <label className="mb-1 block text-xs font-medium text-gray-600">
                      Forwarder address
                    </label>
                    <input
                      className={base}
                      value={fwFrom}
                      onChange={(e) => setFwFrom(e.target.value)}
                      placeholder="mail@example.com"
                      required
                    />
                  </div>
                  <div className="sm:col-span-2">
                    <label className="mb-1 block text-xs font-medium text-gray-600">
                      Destinations (comma separated)
                    </label>
                    <input
                      className={base}
                      value={fwTo}
                      onChange={(e) => setFwTo(e.target.value)}
                      placeholder="a@gmail.com, b@yahoo.com"
                      required
                    />
                  </div>
                  <div className="flex items-end">
                    <button className={btn}>
                      <Plus className="h-4 w-4" /> Add
                    </button>
                  </div>
                </form>
              </section>

              <section className="rounded-xl border border-gray-200 bg-white p-5">
                <div className="mb-3 flex items-center gap-2 text-gray-800">
                  <Forward className="h-4 w-4 text-brand-600" />
                  <span className="font-semibold">Forwarders ({forwarders.length})</span>
                </div>
                {forwarders.length === 0 ? (
                  <p className="text-sm text-gray-500">No forwarders yet.</p>
                ) : (
                  <div className="space-y-2">
                    {forwarders.map((f) => (
                      <div
                        key={f.id}
                        className="flex items-center justify-between rounded-lg border border-gray-100 px-4 py-3"
                      >
                        <div className="flex items-center gap-3">
                          <span className="font-medium text-gray-800">{f.from}</span>
                          <span className="text-gray-400">→</span>
                          <div className="flex flex-wrap gap-1.5">
                            {f.to.split(",").map((t) => (
                              <span key={t} className={chip}>
                                {t.trim()}
                              </span>
                            ))}
                          </div>
                        </div>
                        <button
                          onClick={() => dropForwarder(f)}
                          className="rounded-lg p-1.5 text-gray-500 transition hover:bg-red-50 hover:text-red-600"
                          title="Delete"
                        >
                          <Trash2 className="h-4 w-4" />
                        </button>
                      </div>
                    ))}
                  </div>
                )}
              </section>
            </div>
          )}

          {tab === "autoresponders" && (
            <div className="space-y-6">
              <section className="rounded-xl border border-brand-200 bg-brand-50/50 p-5">
                <div className="mb-3 flex items-center gap-2 text-brand-700">
                  <Reply className="h-4 w-4" />
                  <span className="font-semibold">Create Autoresponder</span>
                </div>
                <form onSubmit={createResp} className="grid grid-cols-1 gap-3 sm:grid-cols-4">
                  <div className="sm:col-span-1">
                    <label className="mb-1 block text-xs font-medium text-gray-600">Domain</label>
                    <select
                      className={base}
                      value={arDomain}
                      onChange={(e) => setArDomain(e.target.value)}
                    >
                      {domains.map((d) => (
                        <option key={d.id} value={d.id}>
                          {d.name}
                        </option>
                      ))}
                    </select>
                  </div>
                  <div className="sm:col-span-1">
                    <label className="mb-1 block text-xs font-medium text-gray-600">Email</label>
                    <input
                      className={base}
                      value={arLocal}
                      onChange={(e) => setArLocal(e.target.value)}
                      placeholder="john"
                      required
                    />
                  </div>
                  <div className="sm:col-span-2">
                    <label className="mb-1 block text-xs font-medium text-gray-600">Subject</label>
                    <input
                      className={base}
                      value={arSubject}
                      onChange={(e) => setArSubject(e.target.value)}
                      placeholder="I am out of office"
                      required
                    />
                  </div>
                  <div className="sm:col-span-4">
                    <label className="mb-1 block text-xs font-medium text-gray-600">Body</label>
                    <textarea
                      className={base}
                      rows={3}
                      value={arBody}
                      onChange={(e) => setArBody(e.target.value)}
                      placeholder="I will reply when I get back."
                    />
                  </div>
                  <div className="sm:col-span-1">
                    <label className="mb-1 block text-xs font-medium text-gray-600">Start date</label>
                    <input
                      type="date"
                      className={base}
                      value={arStart}
                      onChange={(e) => setArStart(e.target.value)}
                    />
                  </div>
                  <div className="sm:col-span-1">
                    <label className="mb-1 block text-xs font-medium text-gray-600">End date</label>
                    <input
                      type="date"
                      className={base}
                      value={arEnd}
                      onChange={(e) => setArEnd(e.target.value)}
                    />
                  </div>
                  <div className="flex items-end">
                    <button className={btn}>
                      <Plus className="h-4 w-4" /> Create
                    </button>
                  </div>
                </form>
              </section>

              <section className="rounded-xl border border-gray-200 bg-white p-5">
                <div className="mb-3 flex items-center gap-2 text-gray-800">
                  <Reply className="h-4 w-4 text-brand-600" />
                  <span className="font-semibold">Autoresponders ({resps.length})</span>
                </div>
                {resps.length === 0 ? (
                  <p className="text-sm text-gray-500">No autoresponders yet.</p>
                ) : (
                  <div className="space-y-2">
                    {resps.map((r) => (
                      <div
                        key={r.id}
                        className="flex items-center justify-between rounded-lg border border-gray-100 px-4 py-3"
                      >
                        <div className="min-w-0">
                          <div className="font-medium text-gray-800">{r.address}</div>
                          <div className="truncate text-sm text-gray-500">
                            {r.subject}
                            {r.start_date || r.end_date
                              ? ` (${r.start_date || "soon"} → ${r.end_date || "always"})`
                              : ""}
                          </div>
                        </div>
                        <button
                          onClick={() => dropResp(r)}
                          className="rounded-lg p-1.5 text-gray-500 transition hover:bg-red-50 hover:text-red-600"
                          title="Delete"
                        >
                          <Trash2 className="h-4 w-4" />
                        </button>
                      </div>
                    ))}
                  </div>
                )}
              </section>
            </div>
          )}

          {tab === "default" && (
            <section className="rounded-xl border border-gray-200 bg-white p-5">
              <div className="mb-1 flex items-center gap-2 text-gray-800">
                <MailWarning className="h-4 w-4 text-brand-600" />
                <span className="font-semibold">Default Address</span>
              </div>
              <p className="mb-4 text-sm text-gray-500">
                What happens to mail sent to unknown addresses on each domain.
              </p>
              {domains.length === 0 ? (
                <p className="text-sm text-gray-500">No domains for this account.</p>
              ) : (
                <div className="space-y-3">
                  {domains.map((d) => {
                    const v = dft[d.id] || { action: "discard", to: "" };
                    return (
                      <div
                        key={d.id}
                        className="flex flex-wrap items-end gap-3 rounded-lg border border-gray-100 p-4"
                      >
                        <div className="w-full sm:w-56">
                          <label className="mb-1 block text-xs font-medium text-gray-600">Domain</label>
                          <div className="px-1 py-2 text-sm font-medium text-gray-800">{d.name}</div>
                        </div>
                        <div className="w-full sm:w-48">
                          <label className="mb-1 block text-xs font-medium text-gray-600">Action</label>
                          <select
                            className={base}
                            value={v.action}
                            onChange={(e) =>
                              setDft({ ...dft, [d.id]: { action: e.target.value, to: v.to } })
                            }
                          >
                            <option value="discard">Discard</option>
                            <option value="blackhole">Blackhole</option>
                            <option value="forward">Forward to email</option>
                          </select>
                        </div>
                        {v.action === "forward" && (
                          <div className="w-full sm:w-72">
                            <label className="mb-1 block text-xs font-medium text-gray-600">
                              Forward to
                            </label>
                            <input
                              className={base}
                              value={v.to}
                              onChange={(e) =>
                                setDft({ ...dft, [d.id]: { action: v.action, to: e.target.value } })
                              }
                              placeholder="catch@example.com"
                            />
                          </div>
                        )}
                        <div>
                          <button onClick={() => saveDefault(d)} className={btnSm}>
                            <Save className="h-4 w-4" /> Save
                          </button>
                        </div>
                      </div>
                    );
                  })}
                </div>
              )}
            </section>
          )}
        </>
      )}

      {pwd && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
          <form
            onSubmit={changePwd}
            className="w-full max-w-sm rounded-xl bg-white p-6 shadow-xl"
          >
            <h3 className="mb-1 text-lg font-semibold text-gray-800">Change password</h3>
            <p className="mb-4 text-sm text-gray-500">{pwd.address}</p>
            <input
              type="password"
              className={base}
              value={pwdVal}
              onChange={(e) => setPwdVal(e.target.value)}
              placeholder="New password"
              minLength={6}
              required
              autoFocus
            />
            <div className="mt-5 flex justify-end gap-2">
              <button
                type="button"
                onClick={() => setPwd(null)}
                className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50"
              >
                Cancel
              </button>
              <button className={btn}>Save</button>
            </div>
          </form>
        </div>
      )}
    </div>
  );
}