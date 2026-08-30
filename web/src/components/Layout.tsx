import {
  Archive,
  BarChart3,
  ArrowRightLeft,
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
  Server,
  Settings,
  ShieldCheck,
  ShieldOff,
  Table2,
  Terminal,
  Users,
} from "lucide-react";
import { NavLink, Outlet } from "react-router-dom";

type NavItem = {
  to?: string;
  href?: string;
  label: string;
  icon: any;
  sub?: boolean;
};

const navItems: NavItem[] = [
  { to: "/", label: "Dashboard", icon: LayoutDashboard },
  { to: "/domains", label: "Domains", icon: Globe },
  { to: "/dns", label: "DNS Zone", icon: Network, sub: true },
  { to: "/redirects", label: "Redirects", icon: ArrowRightLeft },
  { to: "/files", label: "File Manager", icon: FolderOpen },
  { to: "/databases", label: "Databases", icon: Database },
  { href: "https://pma.fpanel.my.id", label: "phpMyAdmin", icon: Table2 },
  { to: "/email", label: "Email", icon: Mail },
  { to: "/ssl", label: "SSL", icon: ShieldCheck },
  { to: "/software", label: "Software", icon: Boxes },
  { to: "/php", label: "MultiPHP", icon: Braces },
  { to: "/runtime", label: "Runtime", icon: Server },
  { to: "/ip-blocker", label: "IP Blocker", icon: ShieldOff },
  { to: "/hotlink", label: "Hotlink", icon: Link2Off },
  { to: "/waf", label: "WAF", icon: Flame },
  { to: "/ssh", label: "SSH", icon: Terminal },
  { to: "/totp", label: "2FA", icon: KeyRound },
  { to: "/cron", label: "Cron Jobs", icon: Clock },
  { to: "/backups", label: "Backups", icon: Archive },
  { to: "/metrics", label: "Metrics", icon: BarChart3 },
  { to: "/accounts", label: "Accounts", icon: Users },
  { to: "/packages", label: "Packages", icon: Globe },
];

export default function Layout({ onLogout }: { onLogout: () => void }) {
  return (
    <div className="flex min-h-screen bg-gray-50">
      <aside className="flex w-64 flex-col bg-brand-900 text-brand-100">
        <div className="flex items-center gap-3 border-b border-brand-800 px-5 py-5">
          <div className="flex h-11 w-16 items-center justify-center overflow-hidden rounded-lg bg-white px-1 py-1">
            <img
              src="/fpanel-logo.png"
              alt="FPanel"
              className="h-full w-full object-contain"
            />
          </div>
          <div>
            <div className="text-xl font-bold text-white">FPanel</div>
            <div className="text-xs text-brand-300">Admin Area</div>
          </div>
        </div>

        <nav className="flex-1 space-y-1 px-3 py-4">
          <div className="px-3 pb-2 text-xs font-semibold uppercase tracking-wider text-brand-400">
            Menu
          </div>
          {navItems.map((item) =>
            item.href ? (
              <a
                key={item.href}
                href={item.href}
                target="_blank"
                rel="noreferrer"
                className="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-brand-200 transition hover:bg-brand-800 hover:text-white"
              >
                <item.icon className="h-5 w-5" />
                {item.label}
              </a>
            ) : (
              <NavLink
                key={item.to}
                to={item.to!}
                end={item.to === "/"}
                className={({ isActive }) =>
                  `flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition ${
                    item.sub ? "ml-6 border-l border-brand-700 pl-8 py-2 text-[13px]" : ""
                  } ${
                    isActive
                      ? "bg-brand-700 text-white"
                      : "text-brand-200 hover:bg-brand-800 hover:text-white"
                  }`
                }
              >
                {item.sub ? (
                  <item.icon className="h-4 w-4 text-brand-400" />
                ) : (
                  <item.icon className="h-5 w-5" />
                )}
                {item.label}
              </NavLink>
            )
          )}
        </nav>

        <div className="border-t border-brand-800 p-3">
          <div className="flex items-center gap-3 rounded-lg px-3 py-2 text-sm">
            <div className="flex h-9 w-9 items-center justify-center rounded-full bg-brand-600 text-white">
              A
            </div>
            <div className="flex-1">
              <div className="font-semibold text-white">Admin</div>
              <div className="text-xs text-brand-300">Root</div>
            </div>
            <button
              onClick={onLogout}
              className="rounded-lg p-2 text-brand-300 transition hover:bg-brand-800 hover:text-white"
              title="Logout"
            >
              <LogOut className="h-5 w-5" />
            </button>
          </div>
        </div>
      </aside>

      <main className="flex-1 overflow-x-hidden">
        <header className="sticky top-0 z-10 flex items-center justify-between border-b border-gray-200 bg-white px-8 py-4">
          <h2 className="text-xl font-semibold text-gray-800">
            Server Admin Panel
          </h2>
          <button className="flex items-center gap-2 rounded-lg bg-brand-600 px-3 py-2 text-sm font-medium text-white transition hover:bg-brand-700">
            <Settings className="h-4 w-4" />
            Settings
          </button>
        </header>
        <div className="p-8">
          <Outlet />
        </div>
      </main>
    </div>
  );
}