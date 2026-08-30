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
  Table2,
  Terminal,
  Zap,
} from "lucide-react";
import { NavLink, Outlet } from "react-router-dom";
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

export default function ClientLayout({ onLogout }: { onLogout: () => void }) {
  const name = getAccountName() || "Client";

  return (
    <div className="flex min-h-screen bg-gray-50">
      <aside className="flex w-64 flex-col bg-brand-900 text-brand-100">
        <div className="flex flex-col items-center border-b border-brand-800 px-4 pt-4 pb-3">
          <img
            src="/fpanel-logo.png"
            alt="FPanel"
            className="w-36 rounded-lg object-contain"
          />
          <div className="mt-2 text-xs font-semibold uppercase tracking-wider text-brand-300">
            Client Area
          </div>
        </div>

        <nav className="flex-1 space-y-4 overflow-y-auto px-3 py-4">
          <NavLink
            to={dashboardItem.to!}
            end
            className={({ isActive }) =>
              `flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition ${
                isActive
                  ? "bg-brand-700 text-white"
                  : "text-brand-200 hover:bg-brand-800 hover:text-white"
              }`
            }
          >
            <dashboardItem.icon className="h-5 w-5" />
            {dashboardItem.label}
          </NavLink>

          {navSections.map((section) => (
            <div key={section.title}>
              <div className="px-3 pb-2 text-xs font-semibold uppercase tracking-wider text-brand-400">
                {section.title}
              </div>
              <div className="space-y-1">
                {section.items.map((item) =>
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
                  )
                )}
              </div>
            </div>
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