import { askConfirm } from "../askConfirm";
import {
  Archive,
  ChevronRight,
  Download,
  File,
  FileArchive,
  FilePlus2,
  Folder,
  FolderPlus,
  Move,
  Pencil,
  RefreshCw,
  Search,
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

const joinPath = (parent: string, name: string) =>
  parent ? `${parent}/${name}` : name;

const fmtSize = (n: number) => {
  if (n < 1024) return `${n} B`;
  if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KB`;
  return `${(n / (1024 * 1024)).toFixed(1)} MB`;
};

export default function FileManager() {
  const [path, setPath] = useState("");
  const [entries, setEntries] = useState<Entry[]>([]);
  const [selected, setSelected] = useState<string[]>([]);
  const [query, setQuery] = useState("");
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
    try {
      const res = await api<ListResponse>(
        `/client/files/list${p ? `?path=${encodeURIComponent(p)}` : ""}`
      );
      setEntries(res.entries);
      setPath(res.path);
      setSelected([]);
    } catch (e: any) {
      notify(String(e.message || e), "err");
    }
  };

  useEffect(() => {
    load("");
  }, []);

  const readAsText = async (filePath: string) => {
    const sess = localStorage.getItem("fpanel_sess");
    const token = localStorage.getItem("fpanel_token");
    const res = await fetch(
      `/api/s/${sess}/client/files/read?path=${encodeURIComponent(filePath)}`,
      { headers: { Authorization: `Bearer ${token}` } }
    );
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
      await api(`/client/files/${ep}`, {
        method: "POST",
        body: JSON.stringify(body),
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
      const res = await api<{ uploaded: number }>(
        `/client/files/upload${path ? `?path=${encodeURIComponent(path)}` : ""}`,
        { method: "POST", body: fd }
      );
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
      await api("/client/files/write", {
        method: "POST",
        body: JSON.stringify({ path: editor.path, content: editor.content }),
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
      const res = await fetch(
        `/api/s/${sess}/client/files/download?path=${encodeURIComponent(joinPath(path, e.name))}`,
        { headers: { Authorization: `Bearer ${token}` } }
      );
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

  const downloadSelection = async () => {
    if (selected.length === 0) return;
    try {
      const sess = localStorage.getItem("fpanel_sess");
      const token = localStorage.getItem("fpanel_token");
      const q = new URLSearchParams();
      selected.forEach((p) => q.append("path", p));
      const res = await fetch(`/api/s/${sess}/client/files/download?${q.toString()}`, {
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
      a.download =
        res.headers.get("content-disposition")?.match(/filename="?([^";]+)/)?.[1] || "download";
      a.click();
      URL.revokeObjectURL(url);
    } catch (err: any) {
      notify(String(err.message || err), "err");
    }
  };

  const toggle = (entry: Entry) => {
    const p = joinPath(path, entry.name);
    setSelected((s) => (s.includes(p) ? s.filter((x) => x !== p) : [...s, p]));
  };

  const toggleAll = () => {
    if (selected.length === entries.length) {
      setSelected([]);
    } else {
      setSelected(entries.map((e) => joinPath(path, e.name)));
    }
  };

  const rename = async (e: Entry) => {
    const to = window.prompt("New name:", e.name);
    if (!to || to === e.name) return;
    await doPost("rename", { from: joinPath(path, e.name), to: joinPath(path, to) }, "Renamed");
  };

  const copy = async (e: Entry) => {
    const to = window.prompt("Copy as (relative to this folder):", e.name);
    if (!to || to === e.name) return;
    await doPost("copy", { from: joinPath(path, e.name), to: joinPath(path, to) }, "Copied");
  };

  const move = async (e: Entry) => {
    const to = window.prompt("Move to folder (relative to Home):");
    if (!to) return;
    const dstPath = `${to.replace(/^\/+|\/+$/g, "")}/${e.name}`;
    await doPost("rename", { from: joinPath(path, e.name), to: dstPath }, "Moved");
  };

  const remove = async (e: Entry) => {
    if (!await askConfirm(`Delete "${e.name}"?`)) return;
    await doPost("delete", { path: joinPath(path, e.name) }, "Deleted");
  };

  const removeSelection = async () => {
    if (selected.length === 0) return;
    if (!await askConfirm(`Delete ${selected.length} item(s)?`)) return;
    await doPost("delete", { paths: selected }, "Deleted");
  };

  const compress = async (e: Entry) => {
    await doPost("compress", { path: joinPath(path, e.name) }, "Compressed");
  };

  const compressSelection = async () => {
    if (selected.length === 0) return;
    for (const p of selected) {
      await doPost("compress", { path: p }, "Compressed");
    }
  };

  const extract = async (e: Entry) => {
    await doPost("extract", { path: joinPath(path, e.name) }, "Extracted");
  };

  const crumbs = path ? path.split("/") : [];
  const visible = query
    ? entries.filter((e) => e.name.toLowerCase().includes(query.toLowerCase()))
    : entries;

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

      <div>
        <h2 className="text-xl font-semibold text-gray-800">File Manager</h2>
        <p className="text-sm text-gray-500">Manage the files of your website</p>
      </div>

      <div className="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
        <div className="flex flex-wrap items-center gap-1 border-b border-gray-200 bg-slate-50 px-4 py-2.5">
          <ToolbarBtn icon={<FolderPlus className="h-4 w-4" />} label="New Folder" onClick={() => newEntry("dir")} />
          <ToolbarBtn icon={<FilePlus2 className="h-4 w-4" />} label="New File" onClick={() => newEntry("file")} />
          <Divider />
          <ToolbarBtn
            icon={<Upload className="h-4 w-4" />}
            label={uploading ? "Uploading..." : "Upload"}
            disabled={uploading}
            accent
            onClick={() => fileRef.current?.click()}
          />
          <ToolbarBtn
            icon={<Download className="h-4 w-4" />}
            label="Download"
            disabled={selected.length === 0}
            onClick={downloadSelection}
          />
          <Divider />
          <ToolbarBtn
            icon={<Trash2 className="h-4 w-4" />}
            label="Delete"
            disabled={selected.length === 0}
            danger
            onClick={removeSelection}
          />
          <ToolbarBtn
            icon={<FileArchive className="h-4 w-4" />}
            label="Compress"
            disabled={selected.length === 0}
            onClick={compressSelection}
          />
          <Divider />
          <div className="relative ml-auto">
            <Search className="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
            <input
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              placeholder="Search..."
              className="w-44 rounded-lg border border-gray-300 bg-white py-2 pl-8 pr-3 text-sm focus:border-brand-500 focus:outline-none"
            />
          </div>
          <input
            ref={fileRef}
            type="file"
            multiple
            className="hidden"
            onChange={(e) => onUpload(e.target.files)}
          />
        </div>

        <div className="flex items-center gap-1.5 overflow-x-auto border-b border-gray-200 bg-white px-4 py-2.5 text-sm">
          <span className="text-xs font-semibold uppercase tracking-wide text-gray-400">
            Path
          </span>
          <button
            onClick={() => load("")}
            className="rounded px-1.5 text-xs font-semibold text-brand-700 hover:bg-brand-50"
          >
            Home
          </button>
          {crumbs.map((c, i) => (
            <span key={i} className="flex items-center gap-1 whitespace-nowrap">
              <ChevronRight className="h-3.5 w-3.5 text-gray-300" />
              <button
                onClick={() => load(crumbs.slice(0, i + 1).join("/"))}
                className="rounded px-1.5 text-xs font-semibold text-brand-700 hover:bg-brand-50"
              >
                {c}
              </button>
            </span>
          ))}
        </div>

        <table className="w-full text-left text-sm">
          <thead className="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
            <tr>
              <th className="w-10 px-4 py-3">
                <input
                  type="checkbox"
                  checked={entries.length > 0 && selected.length === entries.length}
                  onChange={toggleAll}
                  className="h-4 w-4 accent-brand-600"
                />
              </th>
              <th className="px-3 py-3.5">Name</th>
              <th className="px-3 py-3.5">Type</th>
              <th className="px-3 py-3.5">Size</th>
              <th className="px-3 py-3.5">Modified</th>
              <th className="px-3 py-3.5">Permissions</th>
              <th className="px-3 py-3.5 text-right">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100">
            {visible.length === 0 ? (
              <tr>
                <td colSpan={7} className="px-5 py-10 text-center text-gray-400">
                  {query ? "No matching files." : "Empty directory."}
                </td>
              </tr>
            ) : (
              visible.map((e) => {
                const p = joinPath(path, e.name);
                const check = selected.includes(p);
                return (
                  <tr
                    key={e.name}
                    className={`hover:bg-gray-50 ${check ? "bg-brand-50/60" : ""}`}
                  >
                    <td className="px-4 py-3">
                      <input
                        type="checkbox"
                        checked={check}
                        onChange={() => toggle(e)}
                        className="h-4 w-4 accent-brand-600"
                      />
                    </td>
                    <td
                      className="cursor-pointer px-3 py-3 font-medium text-gray-800"
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
                    <td className="px-3 py-3 text-gray-500">
                      {e.kind === "dir" ? "Folder" : e.name.includes(".") ? e.name.split(".").pop() : "File"}
                    </td>
                    <td className="px-3 py-3 text-gray-500">
                      {e.kind === "dir" ? "—" : fmtSize(e.size)}
                    </td>
                    <td className="px-3 py-3 text-gray-500">{e.modified}</td>
                    <td className="px-3 py-3 font-mono text-xs text-gray-500">
                      {e.perms}
                    </td>
                    <td className="px-3 py-3 text-right whitespace-nowrap">
                      <RowBtn onClick={() => rename(e)} title="Rename" icon={<Pencil className="h-4 w-4" />} />
                      <RowBtn
                        onClick={() => move(e)}
                        title="Move to folder"
                        icon={<Move className="h-4 w-4" />}
                      />
                      {e.kind !== "dir" && (
                        <>
                          <RowBtn
                            onClick={() => open(e)}
                            title="Edit"
                            icon={<Pencil className="h-4 w-4" />}
                          />
                          <RowBtn onClick={() => copy(e)} title="Copy" icon={<Copy className="h-4 w-4" />} />
                          <RowBtn onClick={() => download(e)} title="Download" icon={<Download className="h-4 w-4" />} />
                        </>
                      )}
                      <RowBtn onClick={() => compress(e)} title="Compress" icon={<FileArchive className="h-4 w-4" />} />
                      {e.name.toLowerCase().endsWith(".zip") && (
                        <RowBtn onClick={() => extract(e)} title="Extract" icon={<Archive className="h-4 w-4" />} />
                      )}
                      <RowBtn
                        onClick={() => remove(e)}
                        title="Delete"
                        danger
                        icon={<Trash2 className="h-4 w-4" />}
                      />
                    </td>
                  </tr>
                );
              })
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
              onChange={(e) => setEditor({ ...editor, content: e.target.value })}
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

function ToolbarBtn({
  icon,
  label,
  onClick,
  disabled,
  accent,
  danger,
}: {
  icon: React.ReactNode;
  label: string;
  onClick: () => void;
  disabled?: boolean;
  accent?: boolean;
  danger?: boolean;
}) {
  const base = "flex items-center gap-1.5 rounded-lg px-3 py-2 text-xs font-semibold transition disabled:cursor-not-allowed disabled:opacity-40";
  const cls = accent
    ? `${base} bg-brand-600 text-white hover:bg-brand-700`
    : danger
    ? `${base} bg-white text-red-600 border border-gray-300 hover:bg-red-50`
    : `${base} bg-white text-gray-600 border border-gray-300 hover:bg-gray-50`;
  return (
    <button onClick={onClick} disabled={disabled} className={cls}>
      {icon}
      {label}
    </button>
  );
}

function Divider() {
  return <span className="my-1 h-6 w-px bg-gray-200" />;
}

function RowBtn({
  icon,
  title,
  onClick,
  danger,
}: {
  icon: React.ReactNode;
  title: string;
  onClick: () => void;
  danger?: boolean;
}) {
  return (
    <button
      onClick={onClick}
      title={title}
      className={`rounded-lg p-1.5 transition ${
        danger
          ? "text-gray-400 hover:bg-red-50 hover:text-red-600"
          : "text-gray-400 hover:bg-brand-50 hover:text-brand-600"
      }`}
    >
      {icon}
    </button>
  );
}