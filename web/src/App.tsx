import { useEffect, useState } from "react";
import { Navigate, Route, Routes, useNavigate } from "react-router-dom";
import Layout from "./components/Layout";
import Login from "./pages/Login";
import Dashboard from "./pages/Dashboard";
import Domains from "./pages/Domains";
import Accounts from "./pages/Accounts";
import Packages from "./pages/Packages";
import Redirects from "./pages/Redirects";
import FileManager from "./pages/FileManager";
import Databases from "./pages/Databases";
import Email from "./pages/Email";
import Ssl from "./pages/Ssl";
import Runtime from "./pages/Runtime";
import Php from "./pages/Php";
import Software from "./pages/Software";
import IpBlocker from "./pages/IpBlocker";
import Hotlink from "./pages/Hotlink";
import Waf from "./pages/Waf";
import Ssh from "./pages/Ssh";
import Totp from "./pages/Totp";
import Dns from "./pages/Dns";
import Cron from "./pages/Cron";
import Backups from "./pages/Backups";
import CacheManager from "./pages/Cache";
import Metrics from "./pages/Metrics";

export function getToken() {
  return localStorage.getItem("fpanel_token");
}

export function getSess() {
  return localStorage.getItem("fpanel_sess");
}

export function setAuth(token: string, sess?: string) {
  localStorage.setItem("fpanel_token", token);
  if (sess) localStorage.setItem("fpanel_sess", sess);
}

export function clearAuth() {
  localStorage.removeItem("fpanel_token");
  localStorage.removeItem("fpanel_sess");
}

export async function api<T = any>(path: string, opts: RequestInit = {}): Promise<T> {
  const sess = getSess();
  const headers = new Headers(opts.headers);
  headers.set("Authorization", `Bearer ${getToken()}`);
  if (typeof opts.body === "string" && !headers.has("Content-Type")) {
    headers.set("Content-Type", "application/json");
  }
  const res = await fetch(`/api/s/${sess}${path}`, { ...opts, headers });
  if (!res.ok) {
    let msg = `Error ${res.status}`;
    try {
      const data = await res.json();
      if (data?.error) msg = data.error;
    } catch {}
    throw new Error(msg);
  }
  if (res.status === 204) return undefined as T;
  return res.json();
}

export default function App() {
  const [authed, setAuthed] = useState<boolean | null>(null);
  const navigate = useNavigate();

  useEffect(() => {
    const token = getToken();
    const sess = getSess();
    if (!token || !sess) {
      setAuthed(false);
      return;
    }
    fetch(`/api/s/${sess}/me`, {
      headers: { Authorization: `Bearer ${token}` },
    })
      .then((r) => (r.ok ? setAuthed(true) : setAuthed(false)))
      .catch(() => setAuthed(false));
  }, []);

  if (authed === null) {
    return (
      <div className="flex h-screen items-center justify-center bg-brand-50">
        <div className="flex items-center gap-3 text-brand-600">
          <span className="h-8 w-8 animate-spin rounded-full border-4 border-brand-200 border-t-brand-600" />
          <span className="text-lg font-semibold">FPanel</span>
        </div>
      </div>
    );
  }

  return (
    <Routes>
      <Route
        path="/login"
        element={
          authed ? (
            <Navigate to="/" replace />
          ) : (
            <Login
              onLogin={() => {
                setAuthed(true);
                navigate("/");
              }}
            />
          )
        }
      />
      <Route
        element={
          authed ? (
            <Layout
              onLogout={() => {
                clearAuth();
                setAuthed(false);
              }}
            />
          ) : (
            <Navigate to="/login" replace />
          )
        }
      >
        <Route path="/" element={<Dashboard />} />
        <Route path="/domains" element={<Domains />} />
        <Route path="/redirects" element={<Redirects />} />
        <Route path="/files" element={<FileManager />} />
        <Route path="/databases" element={<Databases />} />
        <Route path="/email" element={<Email />} />
        <Route path="/ssl" element={<Ssl />} />
        <Route path="/runtime" element={<Runtime />} />
        <Route path="/php" element={<Php />} />
        <Route path="/software" element={<Software />} />
        <Route path="/ip-blocker" element={<IpBlocker />} />
        <Route path="/hotlink" element={<Hotlink />} />
        <Route path="/waf" element={<Waf />} />
        <Route path="/ssh" element={<Ssh />} />
        <Route path="/totp" element={<Totp />} />
        <Route path="/dns" element={<Dns />} />
        <Route path="/cron" element={<Cron />} />
        <Route path="/backups" element={<Backups />} />
        <Route path="/cache" element={<CacheManager />} />
        <Route path="/metrics" element={<Metrics />} />
        <Route path="/accounts" element={<Accounts />} />
        <Route path="/packages" element={<Packages />} />
      </Route>
    </Routes>
  );
}