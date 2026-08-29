import {
  Download,
  File,
  FilePlus2,
  Folder,
  FolderPlus,
  Pencil,
  RefreshCw,
  Trash2,
  Upload,
  X,
  Copy,
} from "lucide-react";
import { useEffect, useRef, useState } from "react";
import { api } from "../App";

interface Entry {
  name: string;
  kind: string;
  size: number;
  modified: string;
  perms: string;
}

interface ListResponse {
  path: string;
  parent: string;
  entries: Entry[];
}

interface Account {
  id: number;
  username: string;
}

const joinPath = (parent: string, name: string) =>
  parent ? `${parent}/${name}` : name;

const fmtSize = (n: number) => {
  if (n < 1024) return `${n} B`;
  if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KB`;
  return `${(n / (1024 * 1024)).toFixed(1)} MB`;
};

export default function FileManager() {
  const [accounts, setAccounts] = useState<Account[]>([]);
  const [accountId, setAccountId] = useState("");
  const [path, setPath] = useState("");
  const [entries, setEntries] = useState<Entry[]>([]);
  const [editor, setEditor] = useState<{ path: string; content: string } | null>(null);
  const [editorSaving, setEditorSaving] = useState(false);
  const [uploading, setUploading] = useState(false);
  const [toast, setToast] = useState<{ type: "ok" | "err"; msg: string } | null>(null);
  const toastTimer = useRef<number>();
  const fileRef = useRef<HTMLInputElement>(null);

  const notify = (msg: string, type: "ok" | "err" = "ok") => {
    setToast({ type, msg });
    window.clearTimeout(toastTimer.current);
    toastTimer.current = window.setTimeout(() => setToast(null), 4000);
  };

  const load = async (p: string) => {
    if (!accountId) return;
    try {
      const q = new URLSearchParams({ account_id: accountId });
      if (p) q.set("path", p);
      const res = await api<ListResponse>(`/files/list?${q.toString()}`);
      setEntries(res.entries);
      setPath(res.path);
    } catch (e: any) {
      notify(String(e.message || e), "err");
    }
  };

  useEffect(() => {
    api<Account[]>("/accounts")
      .then((accs) => {
        setAccounts(accs);
        if (accs[0]) setAccountId(String(accs[0].id));
      })
      .catch((e: any) => notify(String(e.message || e), "err"));
  }, []);

  useEffect(() => {
    if (accountId) {
      setPath("");
      setEntries([]);
      load("");
    }
  }, [accountId]);

  const readAsText = async (filePath: string) => {
    const sess = localStorage.getItem("fpanel_sess");
    const token = localStorage.getItem("fpanel_token");
    const q = new URLSearchParams({ account_id: accountId, path: filePath });
    const res = await fetch(`/api/s/${sess}/files/read?${q.toString()}`, {
      headers: { Authorization: `Bearer ${token}` },
    });
    if (!res.ok) {
      let msg = `Error ${res.status}`;
      try {
        const d = await res.json();
        if (d?.error) msg = d.error;
      } catch {}
      throw new Error(msg);
    }
    return res.text();
  };

  const doPost = async (ep: string, body: any, okMsg: string) => {
    try {
      await api(`/files/${ep}`, {
        method: "POST",
        body: JSON.stringify({ ...body, account_id: Number(accountId) }),
      });
      notify(okMsg);
      load(path);
      return true;
    } catch (e: any) {
      notify(String(e.message || e), "err");
      return false;
    }
  };

  const newEntry = async (kind: string) => {
    const name = window.prompt(
      kind === "dir" ? "Folder name:" : "File name (with extension):"
    );
    if (!name) return;
    await doPost(
      kind === "dir" ? "create-dir" : "create-file",
      { path, name },
      kind === "dir" ? "Folder created" : "File created"
    );
  };

  const onUpload = async (files: FileList | null) => {
    if (!files || files.length === 0) return;
    setUploading(true);
    try {
      const fd = new FormData();
      Array.from(files).forEach((f) => fd.append("file", f));
      const q = new URLSearchParams({ account_id: accountId });
      if (path) q.set("path", path);
      const res = await api<{ uploaded: number }>(`/files/upload?${q.toString()}`, {
        method: "POST",
        body: fd,
      });
      notify(`Uploaded ${res.uploaded} file(s)`);
      load(path);
    } catch (e: any) {
      notify(String(e.message || e), "err");
    } finally {
      setUploading(false);
      if (fileRef.current) fileRef.current.value = "";
    }
  };

  const open = async (e: Entry) => {
    if (e.kind === "dir") {
      load(joinPath(path, e.name));
      return;
    }
    try {
      const content = await readAsText(joinPath(path, e.name));
      setEditor({ path: joinPath(path, e.name), content });
    } catch (err: any) {
      notify(err.message || "Cannot open file", "err");
    }
  };

  const saveEditor = async () => {
    if (!editor) return;
    setEditorSaving(true);
    try {
      await api("/files/write", {
        method: "POST",
        body: JSON.stringify({
          account_id: Number(accountId),
          path: editor.path,
          content: editor.content,
        }),
      });
      setEditor(null);
      notify("File saved");
    } catch (e: any) {
      notify(String(e.message || e), "err");
    } finally {
      setEditorSaving(false);
    }
  };

  const download = async (e: Entry) => {
    try {
      const sess = localStorage.getItem("fpanel_sess");
      const token = localStorage.getItem("fpanel_token");
      const q = new URLSearchParams({ account_id: accountId, path: joinPath(path, e.name) });
      const res = await fetch(`/api/s/${sess}/files/download?${q.toString()}`, {
        headers: { Authorization: `Bearer ${token}` },
      });
      if (!res.ok) {
        notify(`Download failed (${res.status})`, "err");
        return;
      }
      const blob = await res.blob();
      const url = URL.createObjectURL(blob);
      const a = document.createElement("a");
      a.href = url;
      a.download = e.name;
      a.click();
      URL.revokeObjectURL(url);
    } catch (err: any) {
      notify(String(err.message || err), "err");
    }
  };

  const rename = async (e: Entry) => {
    const to = window.prompt("New name:", e.name);
    if (!to || to === e.name) return;
    await doPost("rename", { from: joinPath(path, e.name), to: joinPath(path, to) }, "Renamed");
  };

  const copy = async (e: Entry) => {
    const to = window.prompt("Copy as:", e.name);
    if (!to || to === e.name) return;
    await doPost("copy", { from: joinPath(path, e.name), to: joinPath(path, to) }, "Copied");
  };

  const remove = async (e: Entry) => {
    if (!window.confirm(`Delete "${e.name}"?`)) return;
    await doPost("delete", { path: joinPath(path, e.name) }, "Deleted");
  };

  const crumbs = path ? path.split("/") : [];

  return (
    <div className="space-y-6">
      {toast && (
        <div
          className={`fixed right-4 top-4 z-[60] rounded-lg px-4 py-3 text-sm font-medium text-white shadow-lg ${
            toast.type === "ok" ? "bg-green-600" : "bg-red-600"
          }`}
        >
          {toast.msg}
        </div>
      )}

      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-xl font-semibold text-gray-800">File Manager</h2>
          <p className="text-sm text-gray-500">
            Browse and manage files of any account
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          <select
            value={accountId}
            onChange={(e) => setAccountId(e.target.value)}
            className="rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm focus:border-brand-500 focus:outline-none"
          >
            {accounts.map((a) => (
              <option key={a.id} value={a.id}>
                {a.username}
              </option>
            ))}
          </select>
          <button
            onClick={() => load(path)}
            className="flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm font-medium text-gray-600 hover:bg-gray-50"
          >
            <RefreshCw className="h-4 w-4" /> Refresh
          </button>
          <button
            onClick={() => newEntry("dir")}
            className="flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm font-medium text-gray-600 hover:bg-gray-50"
          >
            <FolderPlus className="h-4 w-4" /> New Folder
          </button>
          <button
            onClick={() => newEntry("file")}
            className="flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm font-medium text-gray-600 hover:bg-gray-50"
          >
            <FilePlus2 className="h-4 w-4" /> New File
          </button>
          <button
            onClick={() => fileRef.current?.click()}
            disabled={uploading}
            className="flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-700"
          >
            <Upload className="h-4 w-4" />
            {uploading ? "Uploading..." : "Upload"}
          </button>
          <input
            ref={fileRef}
            type="file"
            multiple
            className="hidden"
            onChange={(e) => onUpload(e.target.files)}
          />
        </div>
      </div>

      <div className="flex items-center gap-1.5 overflow-x-auto rounded-lg border border-gray-200 bg-white px-4 py-3 text-sm">
        <button
          onClick={() => load("")}
          className="rounded px-2 py-1 font-medium text-brand-700 hover:bg-brand-50"
        >
          Home
        </button>
        {crumbs.map((c, i) => (
          <span key={i} className="flex items-center gap-1.5 whitespace-nowrap">
            <span className="text-gray-300">/</span>
            <button
              onClick={() => load(crumbs.slice(0, i + 1).join("/"))}
              className="rounded px-2 py-1 font-medium text-brand-700 hover:bg-brand-50"
            >
              {c}
            </button>
          </span>
        ))}
      </div>

      <div className="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
        <table className="w-full text-left text-sm">
          <thead className="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
            <tr>
              <th className="px-5 py-3.5">Name</th>
              <th className="px-5 py-3.5">Size</th>
              <th className="px-5 py-3.5">Modified</th>
              <th className="px-5 py-3.5">Permissions</th>
              <th className="px-5 py-3.5 text-right">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100">
            {entries.length === 0 ? (
              <tr>
                <td colSpan={5} className="px-5 py-10 text-center text-gray-400">
                  {accountId ? "Empty directory." : "Select an account to start."}
                </td>
              </tr>
            ) : (
              entries.map((e) => (
                <tr key={e.name} className="hover:bg-gray-50">
                  <td
                    className="cursor-pointer px-5 py-3 font-medium text-gray-800"
                    onClick={() => open(e)}
                  >
                    <span className="flex items-center gap-2.5">
                      {e.kind === "dir" ? (
                        <Folder className="h-4 w-4 shrink-0 text-amber-500" />
                      ) : (
                        <File className="h-4 w-4 shrink-0 text-gray-400" />
                      )}
                      <span className="truncate">{e.name}</span>
                    </span>
                  </td>
                  <td className="px-5 py-3 text-gray-500">
                    {e.kind === "dir" ? "—" : fmtSize(e.size)}
                  </td>
                  <td className="px-5 py-3 text-gray-500">{e.modified}</td>
                  <td className="px-5 py-3 font-mono text-xs text-gray-500">
                    {e.perms}
                  </td>
                  <td className="px-5 py-3 text-right whitespace-nowrap">
                    {e.kind !== "dir" && (
                      <>
                        <button
                          onClick={() => open(e)}
                          className="rounded-lg p-2 text-gray-400 transition hover:bg-brand-50 hover:text-brand-600"
                          title="Edit"
                        >
                          <Pencil className="h-4 w-4" />
                        </button>
                        <button
                          onClick={() => download(e)}
                          className="rounded-lg p-2 text-gray-400 transition hover:bg-brand-50 hover:text-brand-600"
                          title="Download"
                        >
                          <Download className="h-4 w-4" />
                        </button>
                        <button
                          onClick={() => copy(e)}
                          className="rounded-lg p-2 text-gray-400 transition hover:bg-brand-50 hover:text-brand-600"
                          title="Copy"
                        >
                          <Copy className="h-4 w-4" />
                        </button>
                      </>
                    )}
                    <button
                      onClick={() => rename(e)}
                      className="rounded-lg p-2 text-gray-400 transition hover:bg-brand-50 hover:text-brand-600"
                      title="Rename"
                    >
                      <Pencil className="h-4 w-4" />
                    </button>
                    <button
                      onClick={() => remove(e)}
                      className="rounded-lg p-2 text-gray-400 transition hover:bg-red-50 hover:text-red-600"
                      title="Delete"
                    >
                      <Trash2 className="h-4 w-4" />
                    </button>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      {editor && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
          <div className="flex h-[80vh] w-full max-w-3xl flex-col rounded-xl bg-white shadow-xl">
            <div className="flex items-center justify-between border-b border-gray-200 px-5 py-3">
              <div className="flex items-center gap-2 font-semibold text-gray-800">
                <File className="h-4 w-4 text-gray-400" />
                Editor — {editor.path}
              </div>
              <button
                onClick={() => setEditor(null)}
                className="rounded-lg p-2 text-gray-400 hover:bg-gray-100"
              >
                <X className="h-4 w-4" />
              </button>
            </div>
            <textarea
              value={editor.content}
              onChange={(e) =>
                setEditor({ ...editor, content: e.target.value })
              }
              spellCheck={false}
              className="flex-1 resize-none p-4 font-mono text-sm focus:outline-none"
            />
            <div className="flex justify-end gap-3 border-t border-gray-200 px-5 py-3">
              <button
                onClick={() => setEditor(null)}
                className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50"
              >
                Cancel
              </button>
              <button
                onClick={saveEditor}
                disabled={editorSaving}
                className="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50"
              >
                {editorSaving ? "Saving..." : "Save changes"}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}