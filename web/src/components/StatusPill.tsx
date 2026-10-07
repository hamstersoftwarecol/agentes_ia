import { CONVERSATION_STATUS } from "@/lib/status";
import type { ConversationStatus } from "@/lib/types";
import { cn } from "./ui";

/** Estado de una conversación: punto de color + texto (el color nunca es el único canal). */
export function StatusPill({ status, className }: { status: ConversationStatus; className?: string }) {
  const { label, dot } = CONVERSATION_STATUS[status];
  return (
    <span className={cn("inline-flex items-center gap-1.5 text-xs font-medium", className)}>
      <span aria-hidden className={cn("size-2 rounded-full", dot)} />
      {label}
    </span>
  );
}
