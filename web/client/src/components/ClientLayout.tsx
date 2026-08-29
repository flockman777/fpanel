import {
  Archive,
  ArrowRightLeft,
  BarChart3,
  Boxes,
  Braces,
  Clock,
  Database,
  Flame,
  FolderOpen,
  Globe,
  LayoutDashboard,
  Link2Off,
  LogOut,
  Mail,
  Network,
  Server,
  ShieldCheck,
  ShieldOff,
  Terminal,
} from "lucide-react";
import { NavLink, Outlet } from "react-router-dom";
import { getAccountName } from "../App";

const navItems = [
  { to: "/", label: "Dashboard", icon: LayoutDashboard },
  { to: "/domains", label: "Domains", icon: Globe },
  { to: "/redirects", label: "Redirects", icon: ArrowRightLeft },
  { to: "/files", label: "File Manager", icon: FolderOpen },
  { to: "/databases", label: "Databases", icon: Database },
  { to: "/email", label: "Email", icon: Mail },
  { to: "/software", label: "Software", icon: Boxes },
  { to: "/php", label: "MultiPHP", icon: Braces },
  { to: "/ssl", label: "SSL", icon: ShieldCheck },
  { to: "/runtime", label: "Runtime", icon: Server },
  { to: "/ip-blocker", label: "IP Blocker", icon: ShieldOff },
  { to: "/hotlink", label: "Hotlink", icon: Link2Off },
  { to: "/waf", label: "WAF", icon: Flame },
  { to: "/ssh", label: "SSH", icon: Terminal },
  { to: "/dns", label: "DNS Zone", icon: Network },
  { to: "/cron", label: "Cron Jobs", icon: Clock },
  { to: "/backups", label: "Backups", icon: Archive },
  { to: "/usage", label: "Usage", icon: BarChart3 },
];

export default function ClientLayout({ onLogout }: { onLogout: () => void }) {
  const name = getAccountName() || "Client";

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
            <div className="text-xs text-brand-300">Client Area</div>
          </div>
        </div>

        <nav className="flex-1 space-y-1 px-3 py-4">
          <div className="px-3 pb-2 text-xs font-semibold uppercase tracking-wider text-brand-400">
            Menu
          </div>
          {navItems.map((item) => (
            <NavLink
              key={item.to}
              to={item.to}
              end={item.to === "/"}
              className={({ isActive }) =>
                `flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition ${
                  isActive
                    ? "bg-brand-700 text-white"
                    : "text-brand-200 hover:bg-brand-800 hover:text-white"
                }`
              }
            >
              <item.icon className="h-5 w-5" />
              {item.label}
            </NavLink>
          ))}
        </nav>

        <div className="border-t border-brand-800 p-3">
          <div className="flex items-center gap-3 rounded-lg px-3 py-2 text-sm">
            <div className="flex h-9 w-9 items-center justify-center rounded-full bg-brand-600 text-white">
              {(name[0] || "C").toUpperCase()}
            </div>
            <div className="flex-1 min-w-0">
              <div className="truncate font-semibold text-white">{name}</div>
              <div className="text-xs text-brand-300">Client</div>
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
            Hosting Control Panel
          </h2>
        </header>
        <div className="p-8">
          <Outlet />
        </div>
      </main>
    </div>
  );
}