import { useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import {
  Archive,
  BarChart3,
  ArrowRightLeft,
  Boxes,
  Braces,
  Clock,
  Database,
  FileKey,
  Flame,
  FolderOpen,
  Globe,
  KeyRound,
  Link2Off,
  Mail,
  MousePointerClick,
  Network,
  Server,
  ShieldCheck,
  ShieldOff,
  Terminal,
  Zap,
} from "lucide-react";
import { api } from "../App";

interface Section {
  title: string;
  items: { icon: any; label: string; to: string }[];
}

const sections: Section[] = [
  {
    title: "Email",
    items: [
      { icon: Mail, label: "Email Accounts", to: "/email" },
      { icon: ShieldCheck, label: "Deliverability", to: "/deliverability" },
      { icon: MousePointerClick, label: "Tracking", to: "/tracking" },
    ],
  },
  {
    title: "Files",
    items: [
      { icon: FolderOpen, label: "File Manager", to: "/files" },
      { icon: FileKey, label: "FTP Accounts", to: "/ftp" },
      { icon: Archive, label: "Backups", to: "/backups" },
    ],
  },
  {
    title: "Databases",
    items: [{ icon: Database, label: "Databases", to: "/databases" }],
  },
  {
    title: "Domains",
    items: [
      { icon: Globe, label: "Domains", to: "/domains" },
      { icon: ArrowRightLeft, label: "Redirects", to: "/redirects" },
      { icon: Network, label: "Zone Editor", to: "/dns" },
    ],
  },
  {
    title: "Metrics",
    items: [
      { icon: BarChart3, label: "Metrics", to: "/metrics" },
      { icon: Clock, label: "Cron Jobs", to: "/cron" },
    ],
  },
  {
    title: "Software",
    items: [
      { icon: Boxes, label: "Software", to: "/software" },
      { icon: Braces, label: "MultiPHP", to: "/php" },
      { icon: Server, label: "Runtime", to: "/runtime" },
    ],
  },
  {
    title: "Security",
    items: [
      { icon: ShieldCheck, label: "SSL/TLS", to: "/ssl" },
      { icon: ShieldOff, label: "IP Blocker", to: "/ip-blocker" },
      { icon: Link2Off, label: "Hotlink Protection", to: "/hotlink" },
      { icon: Flame, label: "WAF", to: "/waf" },
      { icon: KeyRound, label: "Two-Factor Auth", to: "/totp" },
    ],
  },
  {
    title: "Advanced",
    items: [
      { icon: Terminal, label: "SSH Access", to: "/ssh" },
      { icon: Zap, label: "Cache Manager", to: "/cache" },
    ],
  },
];

function formatBytes(bytes: number) {
  if (!bytes) return "0 B";
  const units = ["B", "KB", "MB", "GB", "TB"];
  const i = Math.min(units.length - 1, Math.floor(Math.log(bytes) / Math.log(1024)));
  return `${(bytes / Math.pow(1024, i)).toFixed(2)} ${units[i]}`;
}

export default function Dashboard() {
  const navigate = useNavigate();
  const [accounts, setAccounts] = useState<any[]>([]);
  const [stats, setStats] = useState<any[]>([]);

  useEffect(() => {
    api<any[]>("/accounts").then(setAccounts).catch(() => {});
    api<any[]>("/stats").then(setStats).catch(() => {});
  }, []);

  const active = accounts.filter((a) => a.status === "active").length;

  const totalDisk = stats.reduce((s, a) => s + (a.disk_bytes || 0), 0);
  const totalBw = stats.reduce((s, a) => s + (a.bandwidth_bytes || 0), 0);
  const totalDb = stats.reduce((s, a) => s + (a.databases || 0), 0);
  const totalDomains = stats.reduce((s, a) => s + (a.domains || 0), 0);
  const totalMail = accounts.reduce((s, a) => s + (a.mailbox_used || 0), 0);

  const generalInfo: [string, any][] = [
    ["Current User", "admin"],
    ["Primary Domain", "kricak.ivpan.com"],
    ["Shared IP Address", "157.15.125.2"],
    ["Home Directory", "/home/admin"],
    ["Server Management", "Full Access"],
    ["Accounts", `${accounts.length} total`],
    ["Active Accounts", String(active)],
  ];

  return (
    <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
      <div className="lg:col-span-2 space-y-6">
        {sections.map((section) => (
          <div
            key={section.title}
            className="rounded-lg border border-gray-200 bg-white shadow-sm"
          >
            <div className="border-b border-gray-100 px-5 py-3">
              <h2 className="text-sm font-bold uppercase tracking-wide text-gray-500">
                {section.title}
              </h2>
            </div>
            <div className="grid grid-cols-2 gap-0 sm:grid-cols-3 md:grid-cols-4 xl:grid-cols-5">
              {section.items.map((item) => (
                <button
                  key={item.to}
                  onClick={() => navigate(item.to)}
                  className="flex flex-col items-center gap-2 border-r border-b border-gray-100 px-4 py-5 text-center transition hover:bg-blue-50 group"
                >
                  <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-gray-100 text-gray-500 transition group-hover:bg-blue-100 group-hover:text-blue-600">
                    <item.icon className="h-6 w-6" />
                  </div>
                  <span className="text-xs font-medium text-gray-700 group-hover:text-blue-700">
                    {item.label}
                  </span>
                </button>
              ))}
            </div>
          </div>
        ))}
      </div>

      <div className="space-y-6">
        <div className="rounded-lg border border-gray-200 bg-white shadow-sm">
          <div className="border-b border-gray-100 px-5 py-3">
            <h2 className="text-sm font-bold uppercase tracking-wide text-gray-500">
              General Information
            </h2>
          </div>
          <dl className="divide-y divide-gray-100">
            {generalInfo.map(([k, v]) => (
              <div key={k} className="flex items-start justify-between gap-3 px-5 py-2">
                <dt className="text-xs text-gray-500">{k}</dt>
                <dd className="text-right text-xs font-medium text-gray-800">
                  {typeof v === "string" && v.includes("http") ? (
                    <a href={v} className="text-blue-600 hover:underline">
                      {v}
                    </a>
                  ) : (
                    v
                  )}
                </dd>
              </div>
            ))}
          </dl>
        </div>

        <div className="rounded-lg border border-gray-200 bg-white shadow-sm">
          <div className="border-b border-gray-100 px-5 py-3">
            <h2 className="text-sm font-bold uppercase tracking-wide text-gray-500">
              Server Statistics
            </h2>
          </div>
          <dl className="divide-y divide-gray-100">
            <div className="flex items-start justify-between gap-3 px-5 py-2">
              <dt className="text-xs text-gray-500">Accounts</dt>
              <dd className="text-right text-xs font-medium text-gray-800">
                {accounts.length} / ∞
              </dd>
            </div>
            <div className="flex items-start justify-between gap-3 px-5 py-2">
              <dt className="text-xs text-gray-500">Active Accounts</dt>
              <dd className="text-right text-xs font-medium text-gray-800">
                {active} / ∞
              </dd>
            </div>
            <div className="flex items-start justify-between gap-3 px-5 py-2">
              <dt className="text-xs text-gray-500">Disk Usage</dt>
              <dd className="text-right text-xs font-medium text-gray-800">
                {formatBytes(totalDisk)} / ∞
              </dd>
            </div>
            <div className="flex items-start justify-between gap-3 px-5 py-2">
              <dt className="text-xs text-gray-500">Bandwidth</dt>
              <dd className="text-right text-xs font-medium text-gray-800">
                {formatBytes(totalBw)} / ∞
              </dd>
            </div>
            <div className="flex items-start justify-between gap-3 px-5 py-2">
              <dt className="text-xs text-gray-500">Domains</dt>
              <dd className="text-right text-xs font-medium text-gray-800">
                {totalDomains} / ∞
              </dd>
            </div>
            <div className="flex items-start justify-between gap-3 px-5 py-2">
              <dt className="text-xs text-gray-500">Databases</dt>
              <dd className="text-right text-xs font-medium text-gray-800">
                {totalDb} / ∞
              </dd>
            </div>
            <div className="flex items-start justify-between gap-3 px-5 py-2">
              <dt className="text-xs text-gray-500">Email Accounts</dt>
              <dd className="text-right text-xs font-medium text-gray-800">
                {totalMail} / ∞
              </dd>
            </div>
          </dl>
        </div>
      </div>
    </div>
  );
}
