import type { ConversationStatus, LeadStatus } from "./types";

export const CONVERSATION_STATUS: Record<ConversationStatus, { label: string; dot: string }> = {
  ai: { label: "IA atendiendo", dot: "bg-success" },
  waiting: { label: "Esperando a una persona", dot: "bg-brand" },
  human: { label: "Persona atendiendo", dot: "bg-info" },
  closed: { label: "Cerrada", dot: "bg-muted" },
};

export const LEAD_STATUSES: { value: LeadStatus; label: string }[] = [
  { value: "new", label: "Nuevo" },
  { value: "contacted", label: "Contactado" },
  { value: "qualified", label: "Calificado" },
  { value: "converted", label: "Convertido" },
  { value: "lost", label: "Perdido" },
];

export const leadStatusLabel = (status: LeadStatus) => LEAD_STATUSES.find((s) => s.value === status)?.label ?? status;

/** Teléfono tal como llega (solo dígitos, con + opcional) en un formato legible: 300 123 4567. */
export function formatPhone(phone: string) {
  const digits = phone.replace(/^\+/, "");
  const prefix = phone.startsWith("+") ? "+" : "";
  if (digits.length === 10) return `${prefix}${digits.slice(0, 3)} ${digits.slice(3, 6)} ${digits.slice(6)}`;
  return phone;
}
