import { useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import {
  Archive,
  ArrowRightLeft,
  BarChart2,
  BarChart3,
  Boxes,
  Braces,
  ChevronDown,
  Clock,
  Database,
  FileCode2,
  Flame,
  FolderOpen,
  GitBranch,
  Globe,
  HardDrive,
  HelpCircle,
  KeyRound,
  Link2Off,
  Lock,
  Mail,
  MailCheck,
  Network,
  Radio,
  Server,
  Settings,
  ShieldCheck,
  ShieldOff,
  Table2,
  Terminal,
  Users,
  Zap,
} from "lucide-react";
import { api } from "../App";

interface ClientData {
  account: { id: number; username: string; email: string; package_id: number; status: string; name: string | null };
  package: { name: string; disk_limit_mb: number; mailbox_limit: number; database_limit: number; domain_limit: number; bandwidth_limit_gb: number };
  usage: { disk_used_mb: number; domain_used: number; subdomain_used: number; database_used: number; mailbox_used: number };
  primary_domain: string | null;
}

interface ServerInfo {
  os: string;
  kernel: string;
  arch: string;
  ip: string;
  server_name: string;
  php_version: string;
  nginx_version: string;
  mariadb_version: string;
  panel_version: string;
  disk_used: string;
  disk_total: string;
  disk_pct: string;
  mem_pct: string;
  load: string;
  services: { name: string; status: string }[];
}

type SectionItem = { label: string; icon: any; to?: string; href?: string; disabled?: boolean };

const sections: { title: string; items: SectionItem[] }[] = [
  {
    title: "Exclusive For FPanel",
    items: [
      { label: "Let's Encrypt", icon: ShieldCheck, to: "/ssl" },
      { label: "WordPress Accelerator", icon: Globe, disabled: true },
      { label: "Redis", icon: Radio, disabled: true },
      { label: "Valkey", icon: Radio, disabled: true },
      { label: "Memcached", icon: Radio, disabled: true },
      { label: "MongoDB", icon: Database, disabled: true },
      { label: "PostgreSQL", icon: Database, disabled: true },
      { label: "Nginx Cache", icon: Zap, to: "/cache" },
      { label: "cP Cleaner", icon: Settings, disabled: true },
      { label: "XML-RPC", icon: FileCode2, to: "/waf" },
      { label: "CSP", icon: ShieldCheck, to: "/waf" },
      { label: "Force to HTTPS", icon: Lock, to: "/ssl" },
      { label: "Git Deploy", icon: GitBranch, disabled: true },
      { label: "How to Access SSH", icon: HelpCircle, to: "/ssh" },
      { label: "Server Status", icon: BarChart2, to: "/usage" },
    ],
  },
  {
    title: "Runtime Manager",
    items: [
      { label: "Runtime Manager", icon: Server, to: "/runtime" },
      { label: "Node.js", icon: Server, disabled: true },
      { label: "Bun", icon: Server, disabled: true },
      { label: "Deno", icon: Server, disabled: true },
      { label: "Python", icon: Server, disabled: true },
      { label: "Ruby", icon: Server, disabled: true },
      { label: "Go", icon: Server, disabled: true },
      { label: "Rust", icon: Server, disabled: true },
      { label: "MultiPHP Version", icon: Braces, to: "/php" },
      { label: "Perl Modules", icon: FileCode2, disabled: true },
      { label: "PHP PEAR Packages", icon: Braces, disabled: true },
      { label: "Select PHP Version", icon: Braces, disabled: true },
      { label: "Setup Node.js App", icon: Server, disabled: true },
      { label: "Setup Ruby App", icon: Server, disabled: true },
      { label: "Setup Python App", icon: Server, disabled: true },
    ],
  },
  {
    title: "Domains",
    items: [
      { label: "Domains", icon: Globe, to: "/domains" },
      { label: "Redirects", icon: ArrowRightLeft, to: "/redirects" },
      { label: "Zone Editor", icon: Network, to: "/dns" },
      { label: "Dynamic DNS", icon: Network, disabled: true },
      { label: "Sitejet Builder", icon: Globe, disabled: true },
    ],
  },
  {
    title: "Files",
    items: [
      { label: "File Manager", icon: FolderOpen, to: "/files" },
      { label: "Directory Privacy", icon: Lock, disabled: true },
      { label: "Disk Usage", icon: HardDrive, to: "/usage" },
      { label: "Web Disk", icon: HardDrive, disabled: true },
      { label: "FTP Accounts", icon: Server, disabled: true },
      { label: "Backup", icon: Archive, to: "/backups" },
      { label: "Backup Wizard", icon: Archive, disabled: true },
      { label: "JetBackup 5", icon: Archive, disabled: true },
    ],
  },
  {
    title: "Email",
    items: [
      { label: "Email Accounts", icon: Mail, to: "/email" },
      { label: "Forwarders", icon: ArrowRightLeft, disabled: true },
      { label: "Email Routing", icon: Network, disabled: true },
      { label: "Autoresponders", icon: Mail, disabled: true },
      { label: "Default Address", icon: Mail, disabled: true },
      { label: "Track Delivery", icon: BarChart2, disabled: true },
      { label: "Global Email Filters", icon: ShieldOff, disabled: true },
      { label: "Email Filters", icon: ShieldOff, disabled: true },
      { label: "Email Deliverability", icon: MailCheck, disabled: true },
      { label: "Address Importer", icon: Users, disabled: true },
      { label: "Spam Filters", icon: ShieldOff, disabled: true },
      { label: "Archive", icon: Archive, disabled: true },
      { label: "Encryption", icon: KeyRound, disabled: true },
      { label: "BoxTrapper", icon: ShieldCheck, disabled: true },
      { label: "Calendars & Contacts", icon: Users, disabled: true },
      { label: "Email Disk Usage", icon: HardDrive, disabled: true },
      { label: "Webmail", icon: Mail, href: "https://webmail.fpanel.my.id" },
    ],
  },
  {
    title: "Databases",
    items: [
      { label: "phpMyAdmin", icon: Table2, href: "https://pma.fpanel.my.id" },
      { label: "Manage Databases", icon: Database, to: "/databases" },
      { label: "Database Wizard", icon: Database, disabled: true },
      { label: "Remote DB Access", icon: Network, disabled: true },
      { label: "PostgreSQL", icon: Database, disabled: true },
      { label: "PostgreSQL Wizard", icon: Database, disabled: true },
      { label: "phpPgAdmin", icon: Table2, disabled: true },
    ],
  },
  {
    title: "Security",
    items: [
      { label: "SSL", icon: ShieldCheck, to: "/ssl" },
      { label: "IP Blocker", icon: ShieldOff, to: "/ip-blocker" },
      { label: "Hotlink Protection", icon: Link2Off, to: "/hotlink" },
      { label: "WAF", icon: Flame, to: "/waf" },
      { label: "Password & Security", icon: KeyRound, disabled: true },
    ],
  },
  {
    title: "Software",
    items: [
      { label: "Software", icon: Boxes, to: "/software" },
      { label: "WordPress Manager", icon: Globe, disabled: true },
    ],
  },
  {
    title: "Metrics",
    items: [
      { label: "Visitors", icon: Users, disabled: true },
      { label: "Site Quality", icon: BarChart2, disabled: true },
      { label: "Errors", icon: HelpCircle, disabled: true },
      { label: "Bandwidth", icon: BarChart3, to: "/usage" },
      { label: "Raw Access", icon: BarChart2, disabled: true },
      { label: "Awstats", icon: BarChart2, disabled: true },
      { label: "Resource Usage", icon: BarChart3, to: "/usage" },
    ],
  },
  {
    title: "Preferences",
    items: [
      { label: "Account Preferences", icon: Settings, disabled: true },
      { label: "Password & Security", icon: KeyRound, disabled: true },
      { label: "Change Language", icon: Globe, disabled: true },
      { label: "Contact Information", icon: Users, disabled: true },
      { label: "User Manager", icon: Users, disabled: true },
    ],
  },
  {
    title: "Advanced",
    items: [
      { label: "Terminal", icon: Terminal, to: "/ssh" },
      { label: "Cron Jobs", icon: Clock, to: "/cron" },
      { label: "Track DNS", icon: Network, disabled: true },
      { label: "Indexes", icon: FileCode2, disabled: true },
      { label: "Error Pages", icon: HelpCircle, disabled: true },
      { label: "Apache Handlers", icon: Server, disabled: true },
      { label: "MIME Types", icon: FileCode2, disabled: true },
      { label: "Cache Manager", icon: Zap, to: "/cache" },
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
  const [srvInfo, setSrvInfo] = useState<ServerInfo | null>(null);
  const [error, setError] = useState("");
  const navigate = useNavigate();
  const [open, setOpen] = useState<Record<string, boolean>>(
    Object.fromEntries(sections.map((s) => [s.title, true]))
  );

  useEffect(() => {
    api<ClientData>("/client/me")
      .then(setData)
      .catch((e) => setError(String(e.message || e)));
    api<ServerInfo>("/client/server-info")
      .then(setSrvInfo)
      .catch(() => {});
  }, []);

  const tileCls = (disabled?: boolean) =>
    `flex items-center gap-3 rounded-lg border px-3 py-3 transition ${
      disabled
        ? "border-gray-200 bg-white shadow-sm cursor-not-allowed"
        : "border-gray-200 bg-white shadow-sm hover:border-brand-400 hover:shadow-md cursor-pointer"
    }`;

  const tileContent = (Icon: any, label: string, disabled?: boolean) => (
    <>
      <Icon className="h-6 w-6 shrink-0 text-brand-600" />
      <span className="text-xs font-medium leading-tight text-left text-gray-700">
        {label}{disabled ? <span className="ml-1 text-[10px] text-gray-400">(Soon)</span> : null}
      </span>
    </>
  );

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
          <div key={section.title} className="rounded-xl border border-gray-200 bg-white shadow-sm overflow-hidden">
            <button
              onClick={() => setOpen((o) => ({ ...o, [section.title]: !o[section.title] }))}
              className="flex w-full items-center justify-between px-4 py-3 text-sm font-bold uppercase tracking-widest text-brand-700 hover:bg-brand-50 transition"
            >
              {section.title}
              <ChevronDown className={`h-4 w-4 text-brand-400 transition-transform ${open[section.title] ? "rotate-180" : ""}`} />
            </button>
            {open[section.title] && (
              <div className="grid grid-cols-3 gap-2 p-3 border-t border-gray-100">
                {section.items.map((item) => {
                  const Icon = item.icon;
                  if (item.disabled) {
                    return (
                      <div key={item.label} className={tileCls(true)}>
                        {tileContent(Icon, item.label, true)}
                      </div>
                    );
                  }
                  return item.href ? (
                    <a key={item.href + item.label} href={item.href} target="_blank" rel="noreferrer" className={tileCls()}>
                      {tileContent(Icon, item.label)}
                    </a>
                  ) : (
                    <button key={item.to + item.label} onClick={() => navigate(item.to!)} className={tileCls()}>
                      {tileContent(Icon, item.label)}
                    </button>
                  );
                })}
              </div>
            )}
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
              <dd className="font-semibold text-gray-800 break-all">{data?.primary_domain || data?.account.username || "—"}</dd>
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
            <StatRow label="Subdomains" used={data?.usage.subdomain_used ?? 0} limit={0} />
            <StatRow label="Databases" used={data?.usage.database_used ?? 0} limit={data?.package.database_limit ?? 0} />
            <StatRow label="Email Accounts" used={data?.usage.mailbox_used ?? 0} limit={data?.package.mailbox_limit ?? 0} />
            <StatRow label="FTP Accounts" used={0} limit={0} />
            <StatRow label="Autoresponders" used={0} limit={0} />
            <StatRow label="Forwarders" used={0} limit={0} />
          </div>
        </div>

        {/* Server Information */}
        {srvInfo && (
          <div className="rounded-xl border border-gray-200 bg-white shadow-sm overflow-hidden">
            <div className="bg-brand-700 px-4 py-2.5 text-xs font-bold uppercase tracking-wider text-white">
              Server Information
            </div>
            <dl className="divide-y divide-gray-100 px-4 py-1 text-xs">
              <div className="py-2">
                <dt className="text-gray-400">Server Name</dt>
                <dd className="font-semibold text-gray-800 break-all">{srvInfo.server_name || "—"}</dd>
              </div>
              <div className="py-2">
                <dt className="text-gray-400">Panel Version</dt>
                <dd className="font-semibold text-gray-800">{srvInfo.panel_version || "—"}</dd>
              </div>
              <div className="py-2">
                <dt className="text-gray-400">Operating System</dt>
                <dd className="font-semibold text-gray-800 break-all">{srvInfo.os || "—"}</dd>
              </div>
              <div className="py-2">
                <dt className="text-gray-400">Kernel</dt>
                <dd className="font-semibold text-gray-800 break-all">{srvInfo.kernel || "—"}</dd>
              </div>
              <div className="py-2">
                <dt className="text-gray-400">Architecture</dt>
                <dd className="font-semibold text-gray-800">{srvInfo.arch || "—"}</dd>
              </div>
              <div className="py-2">
                <dt className="text-gray-400">Shared IP</dt>
                <dd className="font-semibold text-gray-800">{srvInfo.ip || "—"}</dd>
              </div>
              <div className="py-2">
                <dt className="text-gray-400">PHP Version</dt>
                <dd className="font-semibold text-gray-800 break-all">{srvInfo.php_version || "—"}</dd>
              </div>
              <div className="py-2">
                <dt className="text-gray-400">Web Server</dt>
                <dd className="font-semibold text-gray-800 break-all">{srvInfo.nginx_version || "—"}</dd>
              </div>
              <div className="py-2">
                <dt className="text-gray-400">Database</dt>
                <dd className="font-semibold text-gray-800 break-all">{srvInfo.mariadb_version || "—"}</dd>
              </div>
              <div className="py-2">
                <dt className="text-gray-400">Server Load</dt>
                <dd className="font-semibold text-gray-800">{srvInfo.load || "—"}</dd>
              </div>
              <div className="py-2">
                <dt className="text-gray-400">Memory Used</dt>
                <dd className="font-semibold text-gray-800">{srvInfo.mem_pct || "—"}</dd>
              </div>
              <div className="py-2">
                <dt className="text-gray-400">Disk Used</dt>
                <dd className="font-semibold text-gray-800">
                  {srvInfo.disk_used || "—"} / {srvInfo.disk_total || "—"} ({srvInfo.disk_pct || "—"})
                </dd>
              </div>
            </dl>
            <div className="border-t border-gray-200">
              <div className="bg-gray-50 px-4 py-2 text-[10px] font-bold uppercase tracking-wider text-gray-400">
                Services
              </div>
              <dl className="divide-y divide-gray-100 px-4 py-1 text-xs">
                {srvInfo.services.map((svc) => (
                  <div key={svc.name} className="flex items-center justify-between py-1.5">
                    <dt className="text-gray-600 pr-2 break-all">{svc.name}</dt>
                    <dd
                      className={`font-semibold ${
                        svc.status === "active" ? "text-green-600" : "text-red-500"
                      }`}
                    >
                      {svc.status === "active" ? "up" : "down"}
                    </dd>
                  </div>
                ))}
              </dl>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
