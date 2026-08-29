import { useEffect, useState } from "react";
import {
  Database,
  Globe,
  HardDrive,
  Inbox,
  Mail,
  ShieldCheck,
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
    <div className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-2 text-sm font-medium text-gray-700">
          <Icon className="h-4 w-4 text-brand-600" />
          {label}
        </div>
        <span className="text-xs text-gray-400">
          {used} / {limit}
        </span>
      </div>
      <div className="mt-3 h-2.5 w-full overflow-hidden rounded-full bg-gray-100">
        <div className={`h-full rounded-full ${color}`} style={{ width: `${pct}%` }} />
      </div>
      <div className="mt-1.5 text-xs text-gray-400">{pct}% used</div>
    </div>
  );
}

export default function ClientHome() {
  const [data, setData] = useState<ClientData | null>(null);
  const [error, setError] = useState("");

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

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
          <div className="flex items-center justify-between">
            <div>
              <div className="text-sm text-gray-500">Main Domain</div>
              <div className="mt-1 text-2xl font-bold text-gray-800">
                {data?.account.username || "—"}
              </div>
              <div className="mt-1 text-xs text-gray-400">
                {data?.account.email || ""}
              </div>
            </div>
            <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-brand-50 text-brand-600">
              <Globe className="h-7 w-7" />
            </div>
          </div>
        </div>

        <div className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
          <div className="flex items-center justify-between">
            <div>
              <div className="text-sm text-gray-500">Hosting Package</div>
              <div className="mt-1 text-2xl font-bold text-gray-800">
                {data?.package.name || "—"}
              </div>
              <div className="mt-1 text-xs font-medium text-green-600 uppercase">
                {data?.account.status || ""}
              </div>
            </div>
            <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-brand-50 text-brand-600">
              <ShieldCheck className="h-7 w-7" />
            </div>
          </div>
        </div>
      </div>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <UsageBar
          label="Disk Usage"
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

      <div className="rounded-xl border border-gray-200 bg-white shadow-sm">
        <div className="flex items-center justify-between border-b border-gray-100 px-5 py-4">
          <h3 className="font-semibold text-gray-800">Account Details</h3>
          <span className="flex items-center gap-1.5 text-xs text-gray-400">
            <Inbox className="h-3.5 w-3.5" /> FPanel
          </span>
        </div>
        <dl className="grid grid-cols-1 gap-x-8 gap-y-4 p-5 sm:grid-cols-2">
          <div>
            <dt className="text-xs text-gray-400">Username</dt>
            <dd className="mt-0.5 font-medium text-gray-800">
              {data?.account.username || "—"}
            </dd>
          </div>
          <div>
            <dt className="text-xs text-gray-400">Email</dt>
            <dd className="mt-0.5 font-medium text-gray-800">
              {data?.account.email || "—"}
            </dd>
          </div>
          <div>
            <dt className="text-xs text-gray-400">Bandwidth Limit</dt>
            <dd className="mt-0.5 font-medium text-gray-800">
              {data?.package.bandwidth_limit_gb ?? 0} GB
            </dd>
          </div>
          <div>
            <dt className="text-xs text-gray-400">Status</dt>
            <dd className="mt-0.5 font-medium text-green-600 uppercase">
              {data?.account.status || "—"}
            </dd>
          </div>
        </dl>
      </div>
    </div>
  );
}