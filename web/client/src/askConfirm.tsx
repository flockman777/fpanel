import { useSyncExternalStore } from "react";
import { createRoot } from "react-dom/client";

interface Ctx {
  message: string;
  resolve: (ok: boolean) => void;
}

let current: Ctx | null = null;
const listeners = new Set<() => void>();

function emit() {
  listeners.forEach((l) => l());
}
function subscribe(l: () => void) {
  listeners.add(l);
  return () => {
    listeners.delete(l);
  };
}
function getSnapshot() {
  return current;
}

export function askConfirm(message: string): Promise<boolean> {
  return new Promise((resolve) => {
    current = { message, resolve };
    emit();
  });
}

function Portal() {
  const ctx = useSyncExternalStore(subscribe, getSnapshot, getSnapshot);
  if (!ctx) return null;
  return (
    <div className="fixed inset-0 z-[100] flex items-center justify-center bg-black/40 p-4">
      <div className="w-full max-w-md rounded-xl bg-white p-5 shadow-2xl">
        <h3 className="mb-2 text-base font-semibold text-gray-800">Confirm</h3>
        <p className="mb-5 whitespace-pre-line text-sm text-gray-600">{ctx.message}</p>
        <div className="flex justify-end gap-2">
          <button
            onClick={() => {
              ctx.resolve(false);
              current = null;
              emit();
            }}
            className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50"
          >
            Cancel
          </button>
          <button
            onClick={() => {
              ctx.resolve(true);
              current = null;
              emit();
            }}
            className="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700"
          >
            Confirm
          </button>
        </div>
      </div>
    </div>
  );
}

let mounted = false;

export function mountConfirm() {
  if (mounted) return;
  mounted = true;
  const el = document.createElement("div");
  document.body.appendChild(el);
  createRoot(el).render(<Portal />);
}