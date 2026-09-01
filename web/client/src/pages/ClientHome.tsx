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

function StatRow({
  label,
  used,
  limit,
  unit = "",
}: {
  label: string;
  used: number | string;
  limit: number | string;
  unit?: string;
}) {
  const usedNum = Number(used);
  const limitNum = Number(limit);
  const pct =
    limitNum > 0 ? Math.min(100, Math.round((usedNum / limitNum) * 100)) : null;
  const warn = pct !== null && pct >= 80;
  const limitStr = limitNum > 0 ? `${limitNum}${unit}` : "∞";
  return (
    <div className="flex items-start justify-between gap-2 py-1.5 border-b border-gray-100 last:border-0">
      <span className="text-xs text-gray-500 leading-snug">{label}</span>
      <span
        className={`text-xs font-semibold whitespace-nowrap ${
          warn ? "text-amber-600" : "text-gray-700"
        }`}
      >
        {usedNum}{unit} / {limitStr}
        {pct !== null && (
          <span className="text-gray-400 font-normal"> ({pct}%)</span>
        )}
      </span>
    </div>
  );
}

export default function ClientHome() {
  const [data, setData] = useState<ClientData | null>(null);
  const [error, setError] = useState("");
  const navigate = useNavigate();

  useEffect(() => {
    api<ClientData>("/client/me")
      .then(setData)
      .catch((e) => setError(String(e.message || e)));
  }, []);

  const tileCls =
    "flex flex-col items-center gap-2 rounded-lg border border-gray-200 bg-white p-3 text-center shadow-sm transition hover:border-brand-400 hover:shadow-md cursor-pointer";

  return (
    <div className="flex gap-6 items-start">
      {/* ── LEFT: feature tile grid ── */}
      <div className="flex-1 min-w-0 space-y-5">
        {error && (
          <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            Error loading data: {error}
          </div>
        )}

        {sections.map((section) => (
          <div key={section.title}>
            <div className="mb-2 border-b border-gray-200 pb-1 text-xs font-bold uppercase tracking-widest text-brand-600">
              {section.title}
            </div>
            <div className="grid grid-cols-4 gap-2 sm:grid-cols-5 md:grid-cols-6 lg:grid-cols-8">
              {section.items.map((item) => {
                const Icon = item.icon;
                return item.href ? (
                  <a
                    key={item.href}
                    href={item.href}
                    target="_blank"
                    rel="noreferrer"
                    className={tileCls}
                  >
                    <Icon className="h-7 w-7 text-brand-600" />
                    <span className="text-xs font-medium text-gray-700 leading-tight">
                      {item.label}
                    </span>
                  </a>
                ) : (
                  <button
                    key={item.to}
                    onClick={() => navigate(item.to!)}
                    className={tileCls}
                  >
                    <Icon className="h-7 w-7 text-brand-600" />
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

      {/* ── RIGHT: info + statistics sidebar ── */}
      <div className="w-60 shrink-0 space-y-4">
        {/* General Information */}
        <div className="rounded-xl border border-gray-200 bg-white shadow-sm overflow-hidden">
          <div className="bg-brand-700 px-4 py-2.5 text-xs font-bold uppercase tracking-wider text-white">
            General Information
          </div>
          <dl className="divide-y divide-gray-100 px-4 py-1 text-xs">
            <div className="py-2">
              <dt className="text-gray-400">Current User</dt>
              <dd className="font-semibold text-gray-800 break-all">{data?.account.username || "—"}</dd>
            </div>
            <div className="py-2">
              <dt className="text-gray-400">Primary Domain</dt>
              <dd className="font-semibold text-gray-800 break-all">{data?.account.username || "—"}</dd>
            </div>
            <div className="py-2">
              <dt className="text-gray-400">Email</dt>
              <dd className="font-semibold text-gray-800 break-all">{data?.account.email || "—"}</dd>
            </div>
            <div className="py-2">
              <dt className="text-gray-400">Package</dt>
              <dd className="font-semibold text-gray-800">{data?.package.name || "—"}</dd>
            </div>
            <div className="py-2">
              <dt className="text-gray-400">Status</dt>
              <dd className="font-semibold uppercase text-green-600">{data?.account.status || "—"}</dd>
            </div>
          </dl>
        </div>

        {/* Statistics */}
        <div className="rounded-xl border border-gray-200 bg-white shadow-sm overflow-hidden">
          <div className="bg-brand-700 px-4 py-2.5 text-xs font-bold uppercase tracking-wider text-white">
            Statistics
          </div>
          <div className="px-4 py-2">
            <StatRow label="Disk Usage" used={data?.usage.disk_used_mb ?? 0} limit={data?.package.disk_limit_mb ?? 0} unit=" MB" />
            <StatRow label="Bandwidth" used={0} limit={0} />
            <StatRow label="Addon Domains" used={data?.usage.domain_used ?? 0} limit={data?.package.domain_limit ?? 0} />
            <StatRow label="Databases" used={data?.usage.database_used ?? 0} limit={data?.package.database_limit ?? 0} />
            <StatRow label="Email Accounts" used={data?.usage.mailbox_used ?? 0} limit={data?.package.mailbox_limit ?? 0} />
            <StatRow label="Subdomains" used={0} limit={0} />
            <StatRow label="FTP Accounts" used={0} limit={0} />
            <StatRow label="Autoresponders" used={0} limit={0} />
            <StatRow label="Forwarders" used={0} limit={0} />
          </div>
        </div>
      </div>
    </div>
  );
}
