import {
  Archive,
  ArrowRightLeft,
  BarChart3,
  Bell,
  Boxes,
  Braces,
  Clock,
  Database,
  Flame,
  FolderOpen,
  Globe,
  KeyRound,
  LayoutDashboard,
  Link2Off,
  LogOut,
  Mail,
  Network,
  RefreshCw,
  Search,
  Server,
  Settings,
  ShieldCheck,
  ShieldOff,
  Table2,
  Terminal,
  User,
  Zap,
} from "lucide-react";
import { useEffect, useRef, useState } from "react";
import { NavLink, Outlet, useNavigate } from "react-router-dom";
import { getAccountName } from "../App";

type NavItem = {
  to?: string;
  href?: string;
  label: string;
  icon: any;
};

const dashboardItem: NavItem = { to: "/", label: "Dashboard", icon: LayoutDashboard };

const navSections: { title: string; items: NavItem[] }[] = [
  {
    title: "DOMAINS",
    items: [
      { to: "/domains", label: "Domains", icon: Globe },
      { to: "/redirects", label: "Redirects", icon: ArrowRightLeft },
      { to: "/dns", label: "Zone Editor", icon: Network },
    ],
  },
  {
    title: "FILES",
    items: [{ to: "/files", label: "File Manager", icon: FolderOpen }],
  },
  {
    title: "DATABASES",
    items: [
      { to: "/databases", label: "Databases", icon: Database },
      { href: "https://pma.fpanel.my.id", label: "phpMyAdmin", icon: Table2 },
    ],
  },
  {
    title: "EMAIL",
    items: [{ to: "/email", label: "Email", icon: Mail }],
  },
  {
    title: "SOFTWARE",
    items: [
      { to: "/software", label: "Software", icon: Boxes },
      { to: "/php", label: "MultiPHP", icon: Braces },
      { to: "/runtime", label: "Runtime", icon: Server },
      { to: "/ssh", label: "SSH", icon: Terminal },
    ],
  },
  {
    title: "SECURITY",
    items: [
      { to: "/ssl", label: "SSL", icon: ShieldCheck },
      { to: "/ip-blocker", label: "IP Blocker", icon: ShieldOff },
      { to: "/hotlink", label: "Hotlink", icon: Link2Off },
      { to: "/waf", label: "WAF", icon: Flame },
    ],
  },
  {
    title: "CONFIGURATION",
    items: [
      { to: "/cron", label: "Cron Jobs", icon: Clock },
      { to: "/cache", label: "Cache Manager", icon: Zap },
      { to: "/backups", label: "Backups", icon: Archive },
      { to: "/usage", label: "Usage", icon: BarChart3 },
    ],
  },
];

const allItems: (NavItem & { keywords?: string })[] = [
  dashboardItem,
  ...navSections.flatMap((s) => s.items),
];

export default function ClientLayout({ onLogout }: { onLogout: () => void }) {
  const name = getAccountName() || "Client";
  const navigate = useNavigate();

  const [search, setSearch] = useState("");
  const [searchOpen, setSearchOpen] = useState(false);
  const [userOpen, setUserOpen] = useState(false);
  const userRef = useRef<HTMLDivElement>(null);
  const searchRef = useRef<HTMLDivElement>(null);

  const filtered = search.trim()
    ? allItems.filter(
        (i) =>
          i.label.toLowerCase().includes(search.toLowerCase()) && (i.to || i.href)
      )
    : [];

  useEffect(() => {
    function handleClick(e: MouseEvent) {
      if (userRef.current && !userRef.current.contains(e.target as Node))
        setUserOpen(false);
      if (searchRef.current && !searchRef.current.contains(e.target as Node))
        setSearchOpen(false);
    }
    document.addEventListener("mousedown", handleClick);
    return () => document.removeEventListener("mousedown", handleClick);
  }, []);

  return (
    <div className="flex min-h-screen bg-gray-50">
      {/* ── Left sidebar ── */}
      <aside className="flex w-56 flex-col bg-brand-900 text-brand-100">
        <div className="flex flex-col items-center border-b border-brand-800 px-4 pt-4 pb-3">
          <img src="/fpanel-logo.png" alt="FPanel" className="w-32 rounded-lg object-contain" />
          <div className="mt-2 text-xs font-semibold uppercase tracking-wider text-brand-300">
            Client Area
          </div>
        </div>

        <nav className="flex-1 space-y-1 overflow-y-auto px-3 py-4">

          {[
            { to: "/",          label: "Home",         icon: LayoutDashboard },
            { to: "/software",  label: "Apps",         icon: Boxes },
            { to: "/domains",   label: "Domains",      icon: Globe },
            { to: "/files",     label: "File Manager", icon: FolderOpen },
            { to: "/databases", label: "Database",     icon: Database },
            { to: "/email",     label: "Email",        icon: Mail },
            { to: "/ssl",       label: "Security",     icon: ShieldCheck },
          ].map((item) => (
            <NavLink
              key={item.to}
              to={item.to}
              end={item.to === "/"}
              className={({ isActive }) =>
                `flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition ${
                  isActive ? "bg-brand-700 text-white" : "text-brand-200 hover:bg-brand-800 hover:text-white"
                }`
              }
            >
              <item.icon className="h-4 w-4" />
              {item.label}
            </NavLink>
          ))}
        </nav>
      </aside>

      {/* ── Main content ── */}
      <main className="flex-1 overflow-x-hidden flex flex-col">
        {/* ── Top header bar ── */}
        <header className="sticky top-0 z-20 flex items-center border-b border-gray-200 bg-white px-6 py-3">
          <div className="flex-1" />

          {/* Search */}
          <div ref={searchRef} className="relative w-64 mr-2">
            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
            <input
              type="text"
              placeholder="Search features..."
              value={search}
              onChange={(e) => { setSearch(e.target.value); setSearchOpen(true); }}
              onFocus={() => setSearchOpen(true)}
              className="w-full rounded-lg border border-gray-200 bg-gray-50 py-2 pl-9 pr-3 text-sm outline-none focus:border-brand-400 focus:bg-white"
            />
            {searchOpen && filtered.length > 0 && (
              <div className="absolute top-full right-0 mt-1 w-full rounded-lg border border-gray-200 bg-white shadow-lg z-30">
                {filtered.map((item) => {
                  const Icon = item.icon;
                  return (
                    <button
                      key={item.to || item.href}
                      onClick={() => {
                        if (item.to) navigate(item.to);
                        else window.open(item.href, "_blank");
                        setSearch("");
                        setSearchOpen(false);
                      }}
                      className="flex w-full items-center gap-3 px-4 py-2.5 text-sm text-gray-700 hover:bg-brand-50 hover:text-brand-700"
                    >
                      <Icon className="h-4 w-4 text-brand-500" />
                      {item.label}
                    </button>
                  );
                })}
              </div>
            )}
          </div>

          {/* Bell */}
          <button className="rounded-lg p-2 text-gray-500 hover:bg-gray-100 mr-1">
            <Bell className="h-5 w-5" />
          </button>

          {/* User icon */}
          <div ref={userRef} className="relative">
            <button
              onClick={() => setUserOpen((v) => !v)}
              className="flex items-center justify-center h-8 w-8 rounded-full bg-brand-600 text-white hover:bg-brand-700"
            >
              <User className="h-4 w-4" />
            </button>
            {userOpen && (
              <div className="absolute right-0 top-full mt-1 w-52 rounded-xl border border-gray-200 bg-white shadow-lg z-30 overflow-hidden">
                {[
                  { label: "Account Preferences", icon: Settings },
                  { label: "Password & Security", icon: KeyRound },
                  { label: "Contact Information", icon: User },
                  { label: "Reset Page Settings", icon: RefreshCw },
                ].map(({ label, icon: Icon }) => (
                  <button key={label} className="flex w-full items-center gap-3 px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50">
                    <Icon className="h-4 w-4 text-gray-400" />
                    {label}
                  </button>
                ))}
                <div className="border-t border-gray-100" />
                <button
                  onClick={() => { setUserOpen(false); onLogout(); }}
                  className="flex w-full items-center gap-3 px-4 py-2.5 text-sm text-red-600 hover:bg-red-50"
                >
                  <LogOut className="h-4 w-4" />
                  Log Out
                </button>
              </div>
            )}
          </div>
        </header>

        <div className="flex-1 p-6">
          <Outlet />
        </div>
      </main>
    </div>
  );
}
