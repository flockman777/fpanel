import { useEffect, useState } from "react";
import { Navigate, Route, Routes, useNavigate } from "react-router-dom";
import ClientLayout from "./components/ClientLayout";
import Login from "./pages/Login";
import ClientHome from "./pages/ClientHome";
import Domains from "./pages/Domains";
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
import Ftp from "./pages/Ftp";
import Dns from "./pages/Dns";
import Cron from "./pages/Cron";
import Backups from "./pages/Backups";
import Usage from "./pages/Usage";
import Cache from "./pages/Cache";

export function getToken() {
  return localStorage.getItem("fpanel_token");
}

export function getSess() {
  return localStorage.getItem("fpanel_sess");
}

export function getAccountName() {
  return localStorage.getItem("fpanel_name");
}

export function setAuth(token: string, sess?: string, name?: string) {
  localStorage.setItem("fpanel_token", token);
  if (sess) localStorage.setItem("fpanel_sess", sess);
  if (name) localStorage.setItem("fpanel_name", name);
}

export function clearAuth() {
  localStorage.removeItem("fpanel_token");
  localStorage.removeItem("fpanel_sess");
  localStorage.removeItem("fpanel_name");
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
    fetch(`/api/s/${sess}/client/me`, {
      headers: { Authorization: `Bearer ${token}` },
    })
      .then((r) => (r.ok ? setAuthed(true) : setAuthed(false)))
      .catch(() => setAuthed(false));
  }, []);

  if (authed === null) {
    return (
      <div className="flex h-screen flex-col items-center justify-center gap-6 bg-brand-50">
        <img
          src="/fpanel-logo.png"
          alt="FPanel"
          className="w-52 object-contain"
        />
        <span className="h-8 w-8 animate-spin rounded-full border-4 border-brand-200 border-t-brand-600" />
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
            <ClientLayout
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
        <Route path="/" element={<ClientHome />} />
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
        <Route path="/ftp" element={<Ftp />} />
        <Route path="/dns" element={<Dns />} />
        <Route path="/cron" element={<Cron />} />
        <Route path="/backups" element={<Backups />} />
        <Route path="/usage" element={<Usage />} />
        <Route path="/cache" element={<Cache />} />
      </Route>
    </Routes>
  );
}