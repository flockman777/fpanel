import { useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
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
  HardDrive,
  Inbox,
  Link2Off,
  Mail,
  Network,
  Server,
  ShieldCheck,
  ShieldOff,
  Table2,
  Terminal,
  Zap,
} from "lucide-react";
import { api } from "../App";

interface ClientData {
  account: {
    id: number;
    username: string;
    email: string;
    package_id: number;
    status: string;
    name: string | null;
  };
  package: {
    name: string;
    disk_limit_mb: number;
    mailbox_limit: number;
    database_limit: number;
    domain_limit: number;
    bandwidth_limit_gb: number;
  };
  usage: {
    disk_used_mb: number;
    domain_used: number;
    database_used: number;
    mailbox_used: number;
  };
}

function UsageBar({
  label,
  used,
  limit,
  icon: Icon,
}: {
  label: string;
  used: number;
  limit: number;
  icon: typeof Globe;
}) {
  const pct = limit > 0 ? Math.min(100, Math.round((used / limit) * 100)) : 0;
  const color =
    pct >= 90 ? "bg-red-500" : pct >= 70 ? "bg-amber-500" : "bg-brand-600";
  return (
    <div className="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-2 text-sm font-medium text-gray-700">
          <Icon className="h-4 w-4 text-brand-600" />
          {label}
        </div>
        <span className="text-xs text-gray-400">
          {used} / {limit}
        </span>
      </div>
      <div className="mt-3 h-2 w-full overflow-hidden rounded-full bg-gray-100">
        <div className={`h-full rounded-full ${color}`} style={{ width: `${pct}%` }} />
      </div>
      <div className="mt-1 text-xs text-gray-400">{pct}% used</div>
    </div>
  );
}

const sections: {
  title: string;
  items: { label: string; icon: any; to?: string; href?: string }[];
}[] = [
  {
    title: "Files",
    items: [
      { label: "File Manager", icon: FolderOpen, to: "/files" },
      { label: "Backups", icon: Archive, to: "/backups" },
    ],
  },
  {
    title: "Domains",
    items: [
      { label: "Domains", icon: Globe, to: "/domains" },
      { label: "Zone Editor", icon: Network, to: "/dns" },
      { label: "Redirects", icon: ArrowRightLeft, to: "/redirects" },
    ],
  },
  {
    title: "Databases",
    items: [
      { label: "Databases", icon: Database, to: "/databases" },
      { label: "phpMyAdmin", icon: Table2, href: "https://pma.fpanel.my.id" },
    ],
  },
  {
    title: "Email",
    items: [
      { label: "Email", icon: Mail, to: "/email" },
    ],
  },
  {
    title: "Security",
    items: [
      { label: "SSL", icon: ShieldCheck, to: "/ssl" },
      { label: "IP Blocker", icon: ShieldOff, to: "/ip-blocker" },
      { label: "Hotlink", icon: Link2Off, to: "/hotlink" },
      { label: "WAF", icon: Flame, to: "/waf" },
    ],
  },
  {
    title: "Software",
    items: [
      { label: "Software", icon: Boxes, to: "/software" },
      { label: "MultiPHP", icon: Braces, to: "/php" },
      { label: "Runtime", icon: Server, to: "/runtime" },
      { label: "SSH", icon: Terminal, to: "/ssh" },
    ],
  },
  {
    title: "Advanced",
    items: [
      { label: "Cron Jobs", icon: Clock, to: "/cron" },
      { label: "Cache", icon: Zap, to: "/cache" },
      { label: "Usage", icon: BarChart3, to: "/usage" },
    ],
  },
];

export default function ClientHome() {
  const [data, setData] = useState<ClientData | null>(null);
  const [error, setError] = useState("");
  const navigate = useNavigate();

  useEffect(() => {
    api<ClientData>("/client/me")
      .then(setData)
      .catch((e) => setError(String(e.message || e)));
  }, []);

  return (
    <div className="space-y-6">
      {error && (
        <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
          Error loading data: {error}
        </div>
      )}

      {/* Account info bar */}
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
          <div className="flex items-center justify-between">
            <div>
              <div className="text-xs text-gray-400">Main Domain</div>
              <div className="mt-0.5 text-xl font-bold text-gray-800">
                {data?.account.username || "—"}
              </div>
              <div className="mt-0.5 text-xs text-gray-400">
                {data?.account.email || ""}
              </div>
            </div>
            <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-brand-50 text-brand-600">
              <Globe className="h-6 w-6" />
            </div>
          </div>
        </div>

        <div className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
          <div className="flex items-center justify-between">
            <div>
              <div className="text-xs text-gray-400">Hosting Package</div>
              <div className="mt-0.5 text-xl font-bold text-gray-800">
                {data?.package.name || "—"}
              </div>
              <div className="mt-0.5 text-xs font-semibold uppercase tracking-wide text-green-600">
                {data?.account.status || ""}
              </div>
            </div>
            <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-brand-50 text-brand-600">
              <Inbox className="h-6 w-6" />
            </div>
          </div>
        </div>
      </div>

      {/* Usage bars */}
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <UsageBar
          label="Disk"
          used={data?.usage.disk_used_mb ?? 0}
          limit={data?.package.disk_limit_mb ?? 0}
          icon={HardDrive}
        />
        <UsageBar
          label="Domains"
          used={data?.usage.domain_used ?? 0}
          limit={data?.package.domain_limit ?? 0}
          icon={Globe}
        />
        <UsageBar
          label="Databases"
          used={data?.usage.database_used ?? 0}
          limit={data?.package.database_limit ?? 0}
          icon={Database}
        />
        <UsageBar
          label="Mailboxes"
          used={data?.usage.mailbox_used ?? 0}
          limit={data?.package.mailbox_limit ?? 0}
          icon={Mail}
        />
      </div>

      {/* cPanel-style feature grid */}
      <div className="space-y-5">
        {sections.map((section) => (
          <div key={section.title}>
            <div className="mb-3 border-b border-gray-200 pb-1 text-xs font-bold uppercase tracking-widest text-brand-600">
              {section.title}
            </div>
            <div className="grid grid-cols-3 gap-3 sm:grid-cols-4 md:grid-cols-6 lg:grid-cols-8">
              {section.items.map((item) => {
                const Icon = item.icon;
                const cls =
                  "flex flex-col items-center gap-2 rounded-xl border border-gray-200 bg-white p-4 text-center shadow-sm transition hover:border-brand-300 hover:shadow-md cursor-pointer";
                return item.href ? (
                  <a
                    key={item.href}
                    href={item.href}
                    target="_blank"
                    rel="noreferrer"
                    className={cls}
                  >
                    <Icon className="h-8 w-8 text-brand-600" />
                    <span className="text-xs font-medium text-gray-700 leading-tight">
                      {item.label}
                    </span>
                  </a>
                ) : (
                  <button
                    key={item.to}
                    onClick={() => navigate(item.to!)}
                    className={cls}
                  >
                    <Icon className="h-8 w-8 text-brand-600" />
                    <span className="text-xs font-medium text-gray-700 leading-tight">
                      {item.label}
                    </span>
                  </button>
                );
              })}
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
