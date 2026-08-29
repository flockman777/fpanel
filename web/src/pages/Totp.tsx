import { KeyRound, ShieldCheck, ShieldOff, Smartphone } from "lucide-react";
import { useEffect, useRef, useState } from "react";
import { api } from "../App";

export default function Totp() {
  const [enabled, setEnabled] = useState<boolean | null>(null);
  const [secret, setSecret] = useState<string | null>(null);
  const [uri, setUri] = useState<string | null>(null);
  const [step, setStep] = useState<"none" | "setup" | "enable">("none");
  const [code, setCode] = useState("");
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
      const s = await api<{ enabled: boolean }>("/totp/status");
      setEnabled(s.enabled);
    } catch (e: any) {
      notify(String(e.message || e), "err");
    }
  };

  useEffect(() => {
    load();
  }, []);

  const setup = async () => {
    setBusy(true);
    try {
      const s = await api<{ secret: string; uri: string; enabled: boolean }>("/totp/setup", { method: "POST" });
      setSecret(s.secret);
      setUri(s.uri);
      setEnabled(s.enabled);
      setStep("enable");
      notify("Scan the QR code with your authenticator app");
    } catch (e: any) {
      notify(String(e.message || e), "err");
    } finally {
      setBusy(false);
    }
  };

  const enable = async () => {
    if (!code.trim()) {
      notify("Enter the 6-digit code", "err");
      return;
    }
    setBusy(true);
    try {
      await api("/totp/enable", { method: "POST", body: JSON.stringify({ code: code.trim() }) });
      notify("Two-factor authentication enabled");
      setEnabled(true);
      setStep("none");
      setCode("");
      setSecret(null);
      setUri(null);
    } catch (e: any) {
      notify(String(e.message || e), "err");
    } finally {
      setBusy(false);
    }
  };

  const disable = async () => {
    const c = window.prompt("Enter your current 6-digit code to disable two-factor authentication:");
    if (!c) return;
    setBusy(true);
    try {
      await api("/totp/disable", { method: "POST", body: JSON.stringify({ code: c.trim() }) });
      notify("Two-factor authentication disabled");
      setEnabled(false);
    } catch (e: any) {
      notify(String(e.message || e), "err");
    } finally {
      setBusy(false);
    }
  };

  const copySecret = async () => {
    if (!secret) return;
    await navigator.clipboard.writeText(secret);
    notify("Secret copied");
  };

  const base = "w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none";
  const btn = "flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-brand-700 disabled:opacity-60";
  const btnGhost = "flex items-center gap-2 rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50";

  return (
    <div className="space-y-6">
      {toast && (
        <div className={`fixed top-4 right-4 z-[60] rounded-lg px-4 py-3 text-sm font-medium text-white shadow-lg ${toast.type === "ok" ? "bg-green-600" : "bg-red-600"}`}>
          {toast.msg}
        </div>
      )}

      <div>
        <h2 className="text-xl font-semibold text-gray-800">Two-Factor Authentication</h2>
        <p className="text-sm text-gray-500">Protect your admin login with a time-based one-time password (TOTP)</p>
      </div>

      <section className="max-w-xl rounded-xl border border-gray-200 bg-white p-6">
        {enabled === null ? (
          <p className="text-sm text-gray-400">Loading status...</p>
        ) : enabled ? (
          <div className="flex items-center justify-between gap-3">
            <div className="flex items-center gap-3">
              <span className="flex h-10 w-10 items-center justify-center rounded-full bg-green-50 text-green-600">
                <ShieldCheck className="h-5 w-5" />
              </span>
              <div>
                <p className="text-sm font-semibold text-gray-800">Two-factor authentication is enabled</p>
                <p className="text-xs text-gray-500">Your admin login requires a TOTP code.</p>
              </div>
            </div>
            <button onClick={disable} disabled={busy} className={btnGhost + " text-red-600 hover:bg-red-50 disabled:opacity-60"}>
              <ShieldOff className="h-4 w-4" /> Disable
            </button>
          </div>
        ) : step === "none" ? (
          <button onClick={setup} disabled={busy} className={btn}>
            <KeyRound className="h-4 w-4" /> Enable two-factor authentication
          </button>
        ) : (
          <div className="space-y-4">
            <div className="flex items-center gap-3">
              <span className="flex h-10 w-10 items-center justify-center rounded-full bg-brand-50 text-brand-600">
                <Smartphone className="h-5 w-5" />
              </span>
              <div>
                <p className="text-sm font-semibold text-gray-800">Scan the QR code in your authenticator app</p>
                <p className="text-xs text-gray-500">or enter the secret below manually, then type the 6-digit code.</p>
              </div>
            </div>
            <div className="flex items-center gap-2 rounded-lg border border-gray-200 p-3">
              <code className="flex-1 truncate font-mono text-sm text-gray-700">{secret || ""}</code>
              <button onClick={copySecret} className="text-xs font-medium text-brand-700 hover:underline">
                Copy
              </button>
            </div>
            {uri && (
              <div className="text-center">
                <img src={`https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=${encodeURIComponent(uri)}`} alt="TOTP QR code" className="mx-auto rounded-lg" />
              </div>
            )}
            <div>
              <label className="mb-1 block text-xs font-medium text-gray-600">6-digit code</label>
              <div className="flex gap-2">
                <input value={code} onChange={(e) => setCode(e.target.value.replace(/\D/g, "").slice(0, 6))} className={base + " w-40 text-center font-mono text-lg tracking-widest"} placeholder="000000" />
                <button onClick={enable} disabled={busy} className={btn}>
                  {busy ? "Verifying..." : "Verify & enable"}
                </button>
              </div>
            </div>
          </div>
        )}
      </section>
      <p className="text-xs text-gray-400">TOTP codes change every 30 seconds. Codes within a 1-step window are accepted.</p>
    </div>
  );
}