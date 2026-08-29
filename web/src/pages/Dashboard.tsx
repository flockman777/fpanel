import { useEffect, useState } from "react";
import {
  Database,
  Folder,
  Globe,
  HardDrive,
  Mail,
  ShieldCheck,
  Users,
} from "lucide-react";
import { api } from "../App";

interface Stat {
  label: string;
  value: string;
  sub: string;
  icon: typeof Users;
}

export default function Dashboard() {
  const [accounts, setAccounts] = useState<any[]>([]);
  const [packages, setPackages] = useState<any[]>([]);
  const [loadError, setLoadError] = useState("");

  useEffect(() => {
    setLoadError("");
    api<any[]>("/accounts")
      .then(setAccounts)
      .catch((e) => setLoadError(String(e.message || e)));
    api<any[]>("/packages")
      .then(setPackages)
      .catch((e) => setLoadError(String(e.message || e)));
  }, []);

  const active = accounts.filter((a) => a.status === "active").length;
  const stats: Stat[] = [
    {
      label: "Total Accounts",
      value: String(accounts.length),
      sub: `${active} active`,
      icon: Users,
    },
    {
      label: "Hosting Packages",
      value: String(packages.length),
      sub: "plans available",
      icon: Globe,
    },
    { label: "Domains", value: "0", sub: "module soon", icon: Globe },
    { label: "Databases", value: "0", sub: "module soon", icon: Database },
  ];

  return (
    <div className="space-y-6">
      {loadError && (
        <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
          Error loading data: {loadError}
        </div>
      )}
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {stats.map((s) => (
          <div
            key={s.label}
            className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm"
          >
            <div className="flex items-center justify-between">
              <div>
                <div className="text-sm text-gray-500">{s.label}</div>
                <div className="mt-1 text-3xl font-bold text-gray-800">
                  {s.value}
                </div>
                <div className="mt-1 text-xs text-gray-400">{s.sub}</div>
              </div>
              <div className="flex h-11 w-11 items-center justify-center rounded-lg bg-brand-50 text-brand-600">
                <s.icon className="h-6 w-6" />
              </div>
            </div>
          </div>
        ))}
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <div className="rounded-xl border border-gray-200 bg-white shadow-sm">
          <div className="flex items-center justify-between border-b border-gray-100 px-5 py-4">
            <h3 className="font-semibold text-gray-800">Recent Accounts</h3>
            <span className="flex items-center gap-1.5 text-xs text-gray-400">
              <HardDrive className="h-3.5 w-3.5" /> FPanel
            </span>
          </div>
          {accounts.length === 0 ? (
            <div className="px-5 py-10 text-center text-sm text-gray-400">
              No accounts yet. Create your first account from the Accounts menu.
            </div>
          ) : (
            <ul className="divide-y divide-gray-100">
              {accounts.slice(0, 5).map((a) => (
                <li
                  key={a.id}
                  className="flex items-center justify-between px-5 py-3.5"
                >
                  <div>
                    <div className="font-medium text-gray-800">{a.username}</div>
                    <div className="text-xs text-gray-400">{a.email}</div>
                  </div>
                  <span
                    className={`rounded-full px-2.5 py-1 text-xs font-medium ${
                      a.status === "active"
                        ? "bg-green-50 text-green-700"
                        : "bg-red-50 text-red-700"
                    }`}
                  >
                    {a.status}
                  </span>
                </li>
              ))}
            </ul>
          )}
        </div>

        <div className="rounded-xl border border-gray-200 bg-white shadow-sm">
          <div className="border-b border-gray-100 px-5 py-4">
            <h3 className="font-semibold text-gray-800">FPanel Modules</h3>
          </div>
          <div className="grid grid-cols-3 gap-4 p-5">
            {[
              { icon: Folder, label: "File Manager" },
              { icon: Database, label: "Database" },
              { icon: Globe, label: "Domain" },
              { icon: ShieldCheck, label: "SSL" },
              { icon: Mail, label: "Email" },
              { icon: HardDrive, label: "Backup" },
            ].map((m) => (
              <div
                key={m.label}
                className="flex flex-col items-center gap-2 rounded-lg border border-dashed border-gray-200 py-4 text-gray-400"
              >
                <m.icon className="h-6 w-6" />
                <span className="text-xs">{m.label}</span>
              </div>
            ))}
          </div>
        </div>
      </div>
    </div>
  );
}