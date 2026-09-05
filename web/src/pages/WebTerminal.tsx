import { Terminal, RefreshCw, X } from "lucide-react";
import { useState } from "react";

export default function WebTerminal() {
  const [key, setKey] = useState(0);

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 className="flex items-center gap-2 text-xl font-semibold text-gray-800">
            <Terminal className="h-5 w-5 text-brand-600" /> Web Terminal
          </h2>
          <p className="text-sm text-gray-500">
            Access a live shell on the server via ttyd. Log in with a valid system
            username and password (e.g. root).
          </p>
        </div>
        <div className="flex gap-2">
          <button
            onClick={() => setKey((k) => k + 1)}
            className="flex items-center gap-2 rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-brand-700"
          >
            <RefreshCw className="h-3.5 w-3.5" /> Reconnect
          </button>
          <button
            onClick={() => setKey((k) => k + 1)}
            className="flex items-center gap-2 rounded-lg border border-gray-300 px-2.5 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50"
          >
            <X className="h-3.5 w-3.5" /> Reset
          </button>
        </div>
      </div>

      <div className="overflow-hidden rounded-xl border border-gray-200 bg-gray-900 shadow-sm">
        <iframe
          key={key}
          src="/terminal/"
          title="Web Terminal"
          className="h-[68vh] w-full bg-gray-900"
          sandbox="allow-scripts allow-same-origin allow-forms allow-modals allow-popups"
        />
      </div>
    </div>
  );
}
