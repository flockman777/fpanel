import { askConfirm } from "../askConfirm";
import {
  Database,
  KeyRound,
  Pencil,
  Plus,
  Server,
  Trash2,
  UserPlus,
  Users,
  X,
} from "lucide-react";
import { useEffect, useRef, useState } from "react";
import { api } from "../App";

interface BoundUser {
  user_id: number;
  username: string;
  privileges: string;
}

interface DatabaseRow {
  id: number;
  account_id: number;
  name: string;
  db_user: string;
  status: string;
  created_at: string;
  bound_users: BoundUser[];
}

interface DbUser {
  id: number;
  account_id: number;
  username: string;
  status: string;
  created_at: string;
}

interface Privilege {
  id: number;
  db_id: number;
  db_name: string;
  user_id: number;
  username: string;
  privileges: string;
}

const PRIV_OPTIONS = [
  "ALL PRIVILEGES",
  "SELECT",
  "INSERT",
  "UPDATE",
  "DELETE",
  "CREATE",
  "DROP",
  "INDEX",
  "ALTER",
];

const splitPrivs = (s: string) => s.split(",").map((x) => x.trim()).filter(Boolean);

export default function Databases() {
  const [dbs, setDbs] = useState<DatabaseRow[]>([]);
  const [users, setUsers] = useState<DbUser[]>([]);
  const [myUser, setMyUser] = useState("");
  const [toast, setToast] = useState<{ type: "ok" | "err"; msg: string } | null>(null);
  const toastTimer = useRef<number>();
  const [showDbForm, setShowDbForm] = useState(false);
  const [dbName, setDbName] = useState("");
  const [showUserForm, setShowUserForm] = useState(false);
  const [uname, setUname] = useState("");
  const [pass, setPass] = useState("");
  const [manage, setManage] = useState<DatabaseRow | null>(null);
  const [grants, setGrants] = useState<Privilege[]>([]);
  const [edit, setEdit] = useState<{ d: DatabaseRow; bu: BoundUser; privs: string[] } | null>(null);

  const [addUserId, setAddUserId] = useState("");
  const [addDbId, setAddDbId] = useState("");
  const [addPrivs, setAddPrivs] = useState<string[]>(["ALL PRIVILEGES"]);

  const notify = (msg: string, type: "ok" | "err" = "ok") => {
    setToast({ type, msg });
    window.clearTimeout(toastTimer.current);
    toastTimer.current = window.setTimeout(() => setToast(null), 4000);
  };

  const load = async () => {
    try {
      const [d, u, me] = await Promise.all([
        api<DatabaseRow[]>("/client/databases"),
        api<DbUser[]>("/client/databases/db-users"),
        api<any>("/client/me"),
      ]);
      setDbs(d);
      setUsers(u);
      setMyUser(me?.account?.username || "");
    } catch (e: any) {
      notify(String(e.message || e), "err");
    }
  };

  useEffect(() => {
    load();
  }, []);

  const createDb = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      await api("/client/databases", { method: "POST", body: JSON.stringify({ name: dbName.trim() }) });
      setDbName("");
      setShowDbForm(false);
      notify("Database created");
      load();
    } catch (err: any) {
      notify(String(err.message || err), "err");
    }
  };

  const createUser = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      await api("/client/databases/db-users", {
        method: "POST",
        body: JSON.stringify({ username: uname.trim(), password: pass }),
      });
      setUname("");
      setPass("");
      setShowUserForm(false);
      notify("MySQL user created");
      load();
    } catch (err: any) {
      notify(String(err.message || err), "err");
    }
  };

  const dropDb = async (d: DatabaseRow) => {
    if (!await askConfirm(`Delete database "${d.name}"? This removes it from the server.`)) return;
    try {
      await api(`/client/databases/${d.id}`, { method: "DELETE" });
      if (manage?.id === d.id) setManage(null);
      notify("Database deleted");
      load();
    } catch (e: any) {
      notify(String(e.message || e), "err");
    }
  };

  const dropUser = async (u: DbUser) => {
    if (!await askConfirm(`Delete MySQL user "${u.username}"?`)) return;
    try {
      await api(`/client/databases/db-users/${u.id}`, { method: "DELETE" });
      notify("MySQL user deleted");
      load();
    } catch (e: any) {
      notify(String(e.message || e), "err");
    }
  };

  const openManage = async (d: DatabaseRow) => {
    setManage(d);
    setAddUserId("");
    setAddPrivs(["ALL PRIVILEGES"]);
    try {
      const g = await api<Privilege[]>(`/client/databases/db-privileges?db_id=${d.id}`);
      setGrants(g);
    } catch (e: any) {
      setGrants([]);
      notify(String(e.message || e), "err");
    }
  };

  const addUser = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!addUserId || !addDbId) return;
    try {
      await api("/client/databases/db-privileges", {
        method: "POST",
        body: JSON.stringify({ db_id: Number(addDbId), user_id: Number(addUserId), privileges: addPrivs }),
      });
      setAddUserId("");
      setAddDbId("");
      setAddPrivs(["ALL PRIVILEGES"]);
      notify("User added to database");
      load();
      if (manage && Number(addDbId) === manage.id) openManage(manage);
    } catch (err: any) {
      notify(String(err.message || err), "err");
    }
  };

  const grantInManage = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!manage || !addUserId) return;
    try {
      await api("/client/databases/db-privileges", {
        method: "POST",
        body: JSON.stringify({ db_id: manage.id, user_id: Number(addUserId), privileges: addPrivs }),
      });
      notify("Privileges granted");
      openManage(manage);
      load();
    } catch (err: any) {
      notify(String(err.message || err), "err");
    }
  };

  const revoke = async (g: Privilege) => {
    if (!await askConfirm(`Revoke permissions of "${g.username}" on this database?`)) return;
    try {
      await api(`/client/databases/db-privileges/${g.id}`, { method: "DELETE" });
      setGrants(grants.filter((x) => x.id !== g.id));
      notify("Permissions revoked");
      load();
    } catch (e: any) {
      notify(String(e.message || e), "err");
    }
  };

  const saveEdit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!edit) return;
    if (edit.privs.length === 0) {
      notify("Select at least one privilege", "err");
      return;
    }
    try {
      await api("/client/databases/db-privileges", {
        method: "POST",
        body: JSON.stringify({ db_id: edit.d.id, user_id: edit.bu.user_id, privileges: edit.privs }),
      });
      notify("Privileges updated");
      setEdit(null);
      load();
      if (manage && manage.id === edit.d.id) openManage(edit.d);
    } catch (err: any) {
      notify(String(err.message || err), "err");
    }
  };

  const togglePriv = (p: string, set: (v: string[]) => void) => {
    if (p === "ALL PRIVILEGES") {
      set(["ALL PRIVILEGES"]);
      return;
    }
    set((prev: string[]) => {
      const next = prev.includes("ALL PRIVILEGES")
        ? prev.filter((x) => x !== "ALL PRIVILEGES")
        : prev;
      return next.includes(p) ? next.filter((x) => x !== p) : [...next, p];
    });
  };

  const base = "w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none";
  const btn = "flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-700";
  const btnGhost = "rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-600 hover:bg-gray-50";
  const chip = "rounded-full bg-brand-50 px-2.5 py-1 text-xs font-medium text-brand-700";

  const privChecklist = (privs: string[], set: (v: string[]) => void) => (
    <div className="flex flex-wrap gap-2">
      {PRIV_OPTIONS.map((p) => {
        const on = privs.includes(p);
        return (
          <label
            key={p}
            className={`flex cursor-pointer items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-medium transition ${
              on ? "bg-brand-600 text-white" : "bg-white text-gray-600 ring-1 ring-gray-200 hover:bg-gray-50"
            }`}
          >
            <input
              type="checkbox"
              className="accent-brand-600"
              checked={on}
              onChange={() => togglePriv(p, set)}
            />
            {p}
          </label>
        );
      })}
    </div>
  );

  return (
    <div className="space-y-6">
      {toast && (
        <div
          className={`fixed top-4 right-4 z-[60] rounded-lg px-4 py-3 text-sm font-medium text-white shadow-lg ${
            toast.type === "ok" ? "bg-green-600" : "bg-red-600"
          }`}
        >
          {toast.msg}
        </div>
      )}

      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-xl font-semibold text-gray-800">MySQL Databases</h2>
          <p className="text-sm text-gray-500">
            Databases, users and privileges on this server
          </p>
        </div>
      </div>

      <div className="rounded-xl border border-brand-200 bg-brand-50 p-5">
        <div className="mb-2 flex items-center gap-2 text-brand-700">
          <Server className="h-4 w-4" />
          <span className="font-semibold">Connection details</span>
        </div>
        <div className="grid grid-cols-1 gap-2 text-sm text-gray-700 sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <span className="text-gray-500">Host:</span>{" "}
            <code className="rounded bg-white/70 px-1.5 py-0.5">localhost</code>
          </div>
          <div>
            <span className="text-gray-500">Port:</span>{" "}
            <code className="rounded bg-white/70 px-1.5 py-0.5">3306</code>
          </div>
          <div>
            <span className="text-gray-500">Database prefix:</span>{" "}
            <code className="rounded bg-white/70 px-1.5 py-0.5">{myUser ? `${myUser}_` : "(account)"}</code>
          </div>
          <div>
            <span className="text-gray-500">User prefix:</span>{" "}
            <code className="rounded bg-white/70 px-1.5 py-0.5">{myUser ? `${myUser}_` : "(account)"}</code>
          </div>
        </div>
      </div>

      <section className="rounded-xl border border-brand-200 bg-brand-50/50 p-5">
        <form onSubmit={addUser}>
          <div className="mb-3 flex items-center gap-2 font-semibold text-brand-700">
            <KeyRound className="h-5 w-5" />
            Add User to Database
            <span className="text-xs font-normal text-gray-500">
              Link a MySQL user to a database
            </span>
          </div>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <select
              value={addUserId}
              onChange={(e) => setAddUserId(e.target.value)}
              className={base}
            >
              <option value="">Select MySQL user...</option>
              {users.map((u) => (
                <option key={u.id} value={u.id}>
                  {u.username}
                </option>
              ))}
            </select>
            <select
              value={addDbId}
              onChange={(e) => setAddDbId(e.target.value)}
              className={base}
            >
              <option value="">Select database...</option>
              {dbs.map((d) => (
                <option key={d.id} value={d.id}>
                  {d.name}
                </option>
              ))}
            </select>
          </div>
          <div className="mt-3">{privChecklist(addPrivs, setAddPrivs)}</div>
          <div className="mt-4">
            <button type="submit" className={btn} disabled={!addUserId || !addDbId}>
              Add
            </button>
          </div>
        </form>
      </section>

      <section className="rounded-xl border border-gray-200 bg-white shadow-sm">
        <div className="flex items-center justify-between border-b border-gray-100 px-5 py-4">
          <div className="flex items-center gap-2 font-semibold text-gray-800">
            <Database className="h-4 w-4 text-brand-600" />
            Databases
            <span className="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-500">{dbs.length}</span>
          </div>
          <button onClick={() => setShowDbForm(!showDbForm)} className={btn}>
            <Plus className="h-4 w-4" /> Create Database
          </button>
        </div>

        {showDbForm && (
          <form onSubmit={createDb} className="border-b border-gray-100 bg-brand-50/50 p-5">
            <div className="flex flex-wrap items-end gap-3">
              {myUser && (
                <span className="pb-2.5 text-sm font-medium text-gray-500">{myUser}_</span>
              )}
              <input
                value={dbName}
                onChange={(e) => setDbName(e.target.value)}
                placeholder="db name (letters, numbers, _)"
                className={`${base} max-w-xs`}
                required
              />
              <button type="submit" className={btn}>Create</button>
              <button type="button" onClick={() => setShowDbForm(false)} className={btnGhost}>
                Cancel
              </button>
            </div>
          </form>
        )}

        <table className="w-full text-left text-sm">
          <thead className="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
            <tr>
              <th className="px-5 py-3.5">Database</th>
              <th className="px-5 py-3.5">MySQL Database</th>
              <th className="px-5 py-3.5">Bound users</th>
              <th className="px-5 py-3.5 text-right">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100">
            {dbs.length === 0 ? (
              <tr>
                <td colSpan={4} className="px-5 py-10 text-center text-gray-400">
                  No databases yet. Create your first database.
                </td>
              </tr>
            ) : (
              dbs.map((d) => (
                <tr key={d.id} className="hover:bg-gray-50">
                  <td className="px-5 py-3.5 font-medium text-gray-800">{d.name}</td>
                  <td className="px-5 py-3.5">
                    <code className="rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-700">
                      {myUser ? `${myUser}_${d.name}` : d.name}
                    </code>
                  </td>
                  <td className="px-5 py-3.5">
                    <div className="flex flex-wrap items-center gap-1.5">
                      {d.bound_users.length === 0 && (
                        <span className="text-xs text-gray-400">None</span>
                      )}
                      {d.bound_users.map((b) => (
                        <span key={b.user_id} className={chip} title={b.privileges}>
                          {b.username}
                        </span>
                      ))}
                    </div>
                  </td>
                  <td className="px-5 py-3.5 text-right">
                    <button
                      onClick={() => openManage(d)}
                      className="rounded-lg px-3 py-1.5 text-sm font-medium text-brand-600 transition hover:bg-brand-50"
                    >
                      Manage
                    </button>
                    <button
                      onClick={() => dropDb(d)}
                      className="ml-1 rounded-lg p-2 text-gray-400 transition hover:bg-red-50 hover:text-red-600"
                      title="Delete database"
                    >
                      <Trash2 className="h-4 w-4" />
                    </button>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </section>

      <section className="rounded-xl border border-gray-200 bg-white shadow-sm">
        <div className="flex items-center justify-between border-b border-gray-100 px-5 py-4">
          <div className="flex items-center gap-2 font-semibold text-gray-800">
            <Users className="h-4 w-4 text-brand-600" />
            MySQL Users
            <span className="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-500">{users.length}</span>
          </div>
          <button onClick={() => setShowUserForm(!showUserForm)} className={btn}>
            <UserPlus className="h-4 w-4" /> Create User
          </button>
        </div>

        {showUserForm && (
          <form onSubmit={createUser} className="border-b border-gray-100 bg-brand-50/50 p-5">
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
              <div className="flex items-end gap-1">
                {myUser && (
                  <span className="pb-2.5 text-sm font-medium text-gray-500">{myUser}_</span>
                )}
                <input
                  value={uname}
                  onChange={(e) => setUname(e.target.value)}
                  placeholder="username"
                  className={base}
                  required
                />
              </div>
              <input
                type="password"
                value={pass}
                onChange={(e) => setPass(e.target.value)}
                placeholder="password (min 6 chars)"
                className={base}
                required
              />
              <div className="flex gap-2">
                <button type="submit" className={btn}>Create</button>
                <button type="button" onClick={() => setShowUserForm(false)} className={btnGhost}>
                  Cancel
                </button>
              </div>
            </div>
          </form>
        )}

        <table className="w-full text-left text-sm">
          <thead className="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
            <tr>
              <th className="px-5 py-3.5">User</th>
              <th className="px-5 py-3.5">MySQL User</th>
              <th className="px-5 py-3.5">Status</th>
              <th className="px-5 py-3.5 text-right">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100">
            {users.length === 0 ? (
              <tr>
                <td colSpan={4} className="px-5 py-10 text-center text-gray-400">
                  No MySQL users yet. Create one to connect to your databases.
                </td>
              </tr>
            ) : (
              users.map((u) => (
                <tr key={u.id} className="hover:bg-gray-50">
                  <td className="px-5 py-3.5 font-medium text-gray-800">{u.username}</td>
                  <td className="px-5 py-3.5">
                    <code className="rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-700">
                      {myUser ? `${myUser}_${u.username}` : u.username}
                    </code>
                  </td>
                  <td className="px-5 py-3.5">
                    <span className="rounded-full bg-green-50 px-2.5 py-1 text-xs font-medium text-green-700">
                      {u.status}
                    </span>
                  </td>
                  <td className="px-5 py-3.5 text-right">
                    <button
                      onClick={() => dropUser(u)}
                      className="rounded-lg p-2 text-gray-400 transition hover:bg-red-50 hover:text-red-600"
                      title="Delete user"
                    >
                      <Trash2 className="h-4 w-4" />
                    </button>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </section>

      {manage && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
          <div className="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-xl bg-white p-6 shadow-xl">
            <div className="mb-4 flex items-center justify-between">
              <div className="flex items-center gap-2 font-semibold text-gray-800">
                <Database className="h-5 w-5 text-brand-600" />
                Manage: {myUser ? `${myUser}_` : ""}{manage.name}
              </div>
              <button
                onClick={() => setManage(null)}
                className="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100"
              >
                <X className="h-5 w-5" />
              </button>
            </div>

            <div className="mb-2 text-sm font-medium text-gray-700">Users with access</div>
            {grants.length === 0 ? (
              <div className="rounded-lg border border-dashed border-gray-200 py-6 text-center text-sm text-gray-400">
                No users have access to this database yet.
              </div>
            ) : (
              <div className="space-y-2">
                {grants.map((g) => (
                  <div
                    key={g.id}
                    className="flex items-center justify-between rounded-lg border border-gray-200 px-4 py-3"
                  >
                    <div>
                      <div className="flex items-center gap-2">
                        <span
                          className="text-sm font-medium text-gray-800"
                          title={g.username}
                        >
                          {g.username}
                        </span>
                        <span className="rounded-full bg-gray-100 px-2 py-0.5 text-[11px] text-gray-500">
                          {g.privileges}
                        </span>
                      </div>
                    </div>
                    <div className="flex gap-1">
                      <button
                        onClick={() =>
                          setEdit({ d: manage, bu: { user_id: g.user_id, username: g.username, privileges: g.privileges }, privs: splitPrivs(g.privileges) })
                        }
                        className="rounded-lg p-2 text-brand-600 transition hover:bg-brand-50"
                        title="Edit privileges"
                      >
                        <Pencil className="h-4 w-4" />
                      </button>
                      <button
                        onClick={() => revoke(g)}
                        className="rounded-lg p-2 text-gray-400 transition hover:bg-red-50 hover:text-red-600"
                        title="Revoke access"
                      >
                        <Trash2 className="h-4 w-4" />
                      </button>
                    </div>
                  </div>
                ))}
              </div>
            )}

            <form onSubmit={grantInManage} className="mt-5 rounded-xl border border-brand-200 bg-brand-50 p-4">
              <div className="mb-3 text-sm font-semibold text-brand-700">
                Add another user to this database
              </div>
              <select
                value={addUserId}
                onChange={(e) => setAddUserId(e.target.value)}
                className={`${base} mb-3`}
                required
              >
                <option value="">Select a MySQL user...</option>
                {users
                  .filter((u) => !manage.bound_users.some((b) => b.user_id === u.id))
                  .map((u) => (
                    <option key={u.id} value={u.id}>
                      {u.username}
                    </option>
                  ))}
              </select>
              <div className="mb-4">{privChecklist(addPrivs, setAddPrivs)}</div>
              <button type="submit" className={btn} disabled={!addUserId}>
                Grant
              </button>
            </form>
          </div>
        </div>
      )}

      {edit && (
        <div className="fixed inset-0 z-[55] flex items-center justify-center bg-black/40 p-4">
          <div className="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl">
            <div className="mb-4 flex items-center justify-between">
              <div className="flex items-center gap-2 font-semibold text-gray-800">
                <KeyRound className="h-5 w-5 text-brand-600" />
                Edit privileges: {edit.bu.username} on {edit.d.name}
              </div>
              <button
                onClick={() => setEdit(null)}
                className="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100"
              >
                <X className="h-5 w-5" />
              </button>
            </div>
            <form onSubmit={saveEdit}>
              <div className="mb-4">{privChecklist(edit.privs, (v) => setEdit({ ...edit, privs: v }))}</div>
              <div className="flex gap-3">
                <button type="submit" className={btn}>Save</button>
                <button type="button" onClick={() => setEdit(null)} className={btnGhost}>
                  Cancel
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}