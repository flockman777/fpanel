import { askConfirm } from "../askConfirm";
import {
  BadgeCheck,
  FileDown,
  Lock,
  Plus,
  ShieldAlert,
  Sparkles,
  Trash2,
} from "lucide-react";
import { useEffect, useRef, useState } from "react";
import { api } from "../App";

interface SslRow {
  domain_id: number;
  account_id: number;
  domain: string;
  kind: string;
  cert_id: number | null;
  issuer: string | null;
  valid_from: string | null;
  valid_to: string | null;
  days_left: number | null;
  status: string;
}

export default function Ssl() {
  const [rows, setRows] = useState<SslRow[]>([]);
  const [toast, setToast] = useState<{ type: "ok" | "err"; msg: string } | null>(null);
  const toastTimer = useRef<number>();

  const [imp, setImp] = useState<SslRow | null>(null);
  const [cert, setCert] = useState("");
  const [key, setKey] = useState("");
  const [ca, setCa] = useState("");
  const [busy, setBusy] = useState(false);
  const [autoBusy, setAutoBusy] = useState(false);
  const [autoResults, setAutoResults] = useState<
    { domain: string; ok: boolean; message: string }[] | null
  >(null);

  const notify = (msg: string, type: "ok" | "err" = "ok") => {
    setToast({ type, msg });
    window.clearTimeout(toastTimer.current);
    toastTimer.current = window.setTimeout(() => setToast(null), 4000);
  };

  const load = async () => {
    try {
      setRows(await api<SslRow[]>("/client/ssl"));
    } catch (e: any) {
      notify(String(e.message || e), "err");
    }
  };

  useEffect(() => {
    load();
  }, []);

  const generate = async (r: SslRow) => {
    if (!await askConfirm(`Request a free Let's Encrypt certificate for "${r.domain}"?\n\nThis requires ${r.domain} to point at this server and port 80 open for the challenge.`)) return;
    try {
      const res = await api<
        { domain: string; ok: boolean; message: string }[]
      >("/client/ssl/autossl", {
        method: "POST",
        body: JSON.stringify({ domain_id: r.domain_id }),
      });
      const r0 = res && res[0];
      notify(r0 && r0.ok ? `Certificate issued for ${r0.domain}` : String((r0 && r0.message) || "AutoSSL failed"), r0 && r0.ok ? "ok" : "err");
      load();
    } catch (e: any) {
      notify(String(e.message || e), "err");
    }
  };

  const doImport = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!imp) return;
    setBusy(true);
    try {
      await api("/client/ssl/import", {
        method: "POST",
        body: JSON.stringify({
          domain_id: imp.domain_id,
          cert,
          key,
          ca: ca || null,
        }),
      });
      notify("Certificate installed");
      setImp(null);
      setCert("");
      setKey("");
      setCa("");
      load();
    } catch (err: any) {
      notify(String(err.message || err), "err");
    } finally {
      setBusy(false);
    }
  };

  const drop = async (r: SslRow) => {
    if (!await askConfirm(`Remove the certificate for "${r.domain}"?`)) return;
    try {
      await api(`/client/ssl/${r.cert_id}`, { method: "DELETE" });
      notify("Certificate removed");
      load();
    } catch (e: any) {
      notify(String(e.message || e), "err");
    }
  };

  const runAutoSsl = async () => {
    if (!await askConfirm("Run AutoSSL? This requests free Let's Encrypt certificates for all your active domains.")) return;
    setAutoBusy(true);
    setAutoResults(null);
    try {
      const res = await api<
        { domain: string; ok: boolean; message: string }[]
      >("/client/ssl/autossl", { method: "POST", body: JSON.stringify({}) });
      setAutoResults(res);
      notify(`AutoSSL finished for ${res.length} domain(s)`);
      load();
    } catch (e: any) {
      notify(String(e.message || e), "err");
    } finally {
      setAutoBusy(false);
    }
  };

  const base = "w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none";
  const btn = "flex items-center gap-2 rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-brand-700";
  const btnGhost = "flex items-center gap-2 rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50";

  const statusChip = (r: SslRow) => {
    if (r.status === "none")
      return <span className="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-500">No certificate</span>;
    if (r.status === "expired")
      return <span className="rounded-full bg-red-50 px-2.5 py-1 text-xs font-medium text-red-600">Expired</span>;
    return (
      <span className="rounded-full bg-green-50 px-2.5 py-1 text-xs font-medium text-green-700">
        {r.days_left !== null && r.days_left <= 30 ? "Expiring soon" : "Valid"} · {r.days_left} days
      </span>
    );
  };

  const fmt = (iso: string | null) =>
    iso ? new Date(iso.replace("+00:00", "Z")).toLocaleDateString() : "-";

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
        <h2 className="text-xl font-semibold text-gray-800">SSL/TLS</h2>
        <p className="text-sm text-gray-500">
          Install, generate or remove SSL certificates for your domains
        </p>
      </div>

      <section className="rounded-xl border border-gray-200 bg-white p-5">
        <div className="mb-3 flex items-center gap-2 text-gray-800">
          <Lock className="h-4 w-4 text-brand-600" />
          <span className="font-semibold">SSL/TLS Status</span>
          <button
            onClick={runAutoSsl}
            disabled={autoBusy}
            className="ml-auto flex items-center gap-2 rounded-lg bg-green-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-green-700 disabled:opacity-60"
          >
            <Sparkles className="h-3.5 w-3.5" />
            {autoBusy ? "AutoSSL..." : "Run AutoSSL"}
          </button>
        </div>
        {autoResults && (
          <div className="mb-4 rounded-lg border border-gray-200 bg-gray-50 p-3">
            <div className="mb-2 text-xs font-semibold text-gray-600 uppercase tracking-wider">
              AutoSSL results
            </div>
            <ul className="space-y-1">
              {autoResults.map((r) => (
                <li key={r.domain} className="flex items-start gap-2 text-sm">
                  <span className={`font-medium ${r.ok ? "text-green-600" : "text-red-600"}`}>
                    {r.ok ? "✓" : "✕"}
                  </span>
                  <span className="font-medium text-gray-800">{r.domain}</span>
                  <span className="text-gray-500">{r.message}</span>
                </li>
              ))}
            </ul>
          </div>
        )}
        {rows.length === 0 ? (
          <p className="text-sm text-gray-500">No domains yet.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead>
                <tr className="border-b border-gray-200 text-xs uppercase tracking-wider text-gray-500">
                  <th className="px-3 py-2">Domain</th>
                  <th className="px-3 py-2">Status</th>
                  <th className="px-3 py-2">Issuer</th>
                  <th className="px-3 py-2">Valid To</th>
                  <th className="px-3 py-2 text-right">Actions</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((r) => (
                  <tr key={r.domain_id} className="border-b border-gray-100">
                    <td className="px-3 py-2.5 font-medium text-gray-800">{r.domain}</td>
                    <td className="px-3 py-2.5">{statusChip(r)}</td>
                    <td className="px-3 py-2.5 text-gray-600">
                      {r.issuer || <span className="text-gray-400">-</span>}
                    </td>
                    <td className="px-3 py-2.5 text-gray-600">{fmt(r.valid_to)}</td>
                    <td className="px-3 py-2.5">
                      <div className="flex justify-end gap-2">
                        {r.status === "none" && (
                          <button onClick={() => generate(r)} className={btn}>
                            <Plus className="h-3.5 w-3.5" /> Generate
                          </button>
                        )}
                        <button
                          onClick={() => {
                            setImp(r);
                            setCert("");
                            setKey("");
                            setCa("");
                          }}
                          className={r.status === "none" ? btnGhost : btn}
                        >
                          <BadgeCheck className="h-3.5 w-3.5" />
                          {r.status === "none" ? "Import" : "Replace"}
                        </button>
                        {r.status !== "none" && (
                          <button
                            onClick={() => drop(r)}
                            className="rounded-lg p-1.5 text-gray-500 transition hover:bg-red-50 hover:text-red-600"
                            title="Remove certificate"
                          >
                            <Trash2 className="h-4 w-4" />
                          </button>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

      <div className="rounded-xl border border-brand-200 bg-brand-50 p-5 text-sm text-gray-700">
        <div className="flex items-center gap-2 font-semibold text-brand-700">
          <ShieldAlert className="h-4 w-4" />
          About certificates
        </div>
        <ul className="mt-2 list-inside list-disc space-y-1 text-gray-600">
          <li>
            <span className="font-medium">AutoSSL</span> automatically requests free Let's Encrypt
            certificates for all your active domains and renews them.
          </li>
          <li>
            <span className="font-medium">Generate</span> requests a free Let's Encrypt certificate
            for the domain (requires DNS pointing here and port 80 open).
            for testing or internal services.
          </li>
          <li>
            <span className="font-medium">Import</span> accepts certificates from any CA
            (including Let's Encrypt) — paste the certificate, private key and optional CA bundle.
          </li>
          <li>
            Installed certificates are provisioned automatically for this domain.
          </li>
        </ul>
      </div>

      {imp && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
          <form
            onSubmit={doImport}
            className="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl"
          >
            <div className="mb-1 flex items-center gap-2">
              <FileDown className="h-4 w-4 text-brand-600" />
              <h3 className="text-lg font-semibold text-gray-800">
                {imp.status === "none" ? "Install certificate" : "Replace certificate"}
              </h3>
            </div>
            <p className="mb-4 text-sm text-gray-500">{imp.domain}</p>

            <label className="mb-1 block text-xs font-medium text-gray-600">
              Certificate (PEM)
            </label>
            <textarea
              className={base}
              rows={4}
              value={cert}
              onChange={(e) => setCert(e.target.value)}
              placeholder="-----BEGIN CERTIFICATE-----"
              required
            />

            <label className="mb-1 mt-3 block text-xs font-medium text-gray-600">
              Private key (PEM)
            </label>
            <textarea
              className={base}
              rows={4}
              value={key}
              onChange={(e) => setKey(e.target.value)}
              placeholder="-----BEGIN PRIVATE KEY-----"
              required
            />

            <label className="mb-1 mt-3 block text-xs font-medium text-gray-600">
              CA bundle (optional)
            </label>
            <textarea
              className={base}
              rows={3}
              value={ca}
              onChange={(e) => setCa(e.target.value)}
              placeholder="-----BEGIN CERTIFICATE-----"
            />

            <div className="mt-5 flex justify-end gap-2">
              <button
                type="button"
                onClick={() => setImp(null)}
                className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50"
              >
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