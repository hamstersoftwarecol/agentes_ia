import { cn } from "@/components/ui";

/** Tabla simple de listados del panel de plataforma. */
export function Table({ head, children, className }: { head: string[]; children: React.ReactNode; className?: string }) {
  return (
    <div className={cn("overflow-x-auto rounded-xl border border-border bg-card", className)}>
      <table className="w-full min-w-[36rem] text-left text-sm">
        <thead className="border-b border-border text-xs text-muted">
          <tr>
            {head.map((h, i) => (
              <th key={i} className={cn("px-4 py-3 font-medium", h.startsWith(">") && "text-right")}>
                {h.replace(/^>/, "")}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>{children}</tbody>
      </table>
    </div>
  );
}

export const Td = ({ children, right, className }: { children?: React.ReactNode; right?: boolean; className?: string }) => (
  <td className={cn("border-t border-border px-4 py-2.5 align-top first:border-t-0", right && "text-right tabular-nums", className)}>{children}</td>
);

export function Pager({ page, last, onPage }: { page: number; last: number; onPage: (page: number) => void }) {
  if (last <= 1) return null;
  return (
    <div className="flex items-center justify-between text-sm">
      <button type="button" disabled={page <= 1} onClick={() => onPage(page - 1)} className="rounded-lg border border-border px-3 py-1.5 disabled:opacity-50">
        Anterior
      </button>
      <span className="text-muted">
        {page} / {last}
      </span>
      <button type="button" disabled={page >= last} onClick={() => onPage(page + 1)} className="rounded-lg border border-border px-3 py-1.5 disabled:opacity-50">
        Siguiente
      </button>
    </div>
  );
}
