import { KeyRound, Lock, User } from "lucide-react";
import { useState } from "react";
import { setAuth } from "../App";

export default function Login({ onLogin }: { onLogin: () => void }) {
  const [username, setUsername] = useState("admin");
  const [password, setPassword] = useState("");
  const [totp, setTotp] = useState("");
  const [totpStep, setTotpStep] = useState(false);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError("");
    setLoading(true);
    try {
      const res = await fetch("/api/auth/login", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ username, password, totp: totp.trim() || undefined }),
      });
      const data = await res.json();
      if (res.status === 428) {
        setTotpStep(true);
        setError("");
        return;
      }
      if (!res.ok) {
        setError(data.error || "Login failed");
        return;
      }
      setAuth(data.token, data.sess);
      onLogin();
    } catch {
      setError("Cannot connect to server");
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="flex min-h-screen items-center justify-center bg-gradient-to-br from-brand-700 via-brand-600 to-brand-900 p-4">
      <div className="w-full max-w-md">
        <div className="mb-8 flex flex-col items-center text-white">
          <div className="flex h-16 w-auto items-center justify-center rounded-2xl bg-white px-3 py-1.5 shadow-lg">
            <img
              src="/fpanel-logo.png"
              alt="FPanel"
              className="h-full w-auto object-contain"
            />
          </div>
          <h1 className="mt-5 text-3xl font-bold">FPanel</h1>
          <p className="mt-1.5 text-base font-medium text-brand-100">
            Welcome — Manage your servers
          </p>
        </div>

        <form
          onSubmit={submit}
          className="rounded-2xl bg-white p-8 shadow-2xl"
        >
          <h2 className="mb-6 text-lg font-semibold text-gray-800">
            {totpStep ? "Enter your 2FA code" : "Sign in to your panel"}
          </h2>

          {error && (
            <div className="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
              {error}
            </div>
          )}

          <div className="mb-4">
            <label className="mb-1.5 block text-sm font-medium text-gray-700">
              Username
            </label>
            <div className="relative">
              <User className="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-gray-400" />
              <input
                type="text"
                value={username}
                onChange={(e) => setUsername(e.target.value)}
                className="w-full rounded-lg border border-gray-300 py-2.5 pl-10 pr-3 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none"
                required
              />
            </div>
          </div>

          <div className="mb-6">
            <label className="mb-1.5 block text-sm font-medium text-gray-700">
              Password
            </label>
            <div className="relative">
              <Lock className="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-gray-400" />
              <input
                type="password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                className="w-full rounded-lg border border-gray-300 py-2.5 pl-10 pr-3 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none"
                required
              />
            </div>
          </div>

          {totpStep && (
            <div className="mb-4">
              <label className="mb-1.5 block text-sm font-medium text-gray-700">
                6-digit authentication code
              </label>
              <div className="relative">
                <KeyRound className="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-gray-400" />
                <input
                  type="text"
                  inputMode="numeric"
                  autoFocus
                  value={totp}
                  onChange={(e) => setTotp(e.target.value.replace(/\D/g, "").slice(0, 6))}
                  className="w-full rounded-lg border border-gray-300 py-2.5 pl-10 pr-3 text-center font-mono text-lg tracking-widest focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none"
                  placeholder="000000"
                  required
                />
              </div>
            </div>
          )}

          <button
            type="submit"
            disabled={loading}
            className="w-full rounded-lg bg-brand-600 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-700 disabled:opacity-60"
          >
            {loading ? "Processing..." : totpStep ? "Verify code" : "Sign In"}
          </button>
        </form>
      </div>
    </div>
  );
}