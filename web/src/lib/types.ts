export type DayHours = { enabled: boolean; open: string; close: string };

export const DAYS = [
  "monday",
  "tuesday",
  "wednesday",
  "thursday",
  "friday",
  "saturday",
  "sunday",
] as const;
export type Day = (typeof DAYS)[number];
export type BusinessHours = Record<Day, DayHours>;

export const DAY_LABELS: Record<Day, string> = {
  monday: "Lunes",
  tuesday: "Martes",
  wednesday: "Miércoles",
  thursday: "Jueves",
  friday: "Viernes",
  saturday: "Sábado",
  sunday: "Domingo",
};

export type Organization = {
  id: number;
  name: string;
  slug: string;
  logo_url: string | null;
  website: string | null;
  email: string | null;
  phone: string | null;
  address: string | null;
  country: string | null;
  timezone: string;
  business_hours: BusinessHours;
};

export type Role = "owner" | "admin" | "agent";

export type User = {
  id: number;
  name: string;
  email: string;
  phone: string | null;
  email_verified: boolean;
  is_superadmin: boolean;
  organization: Organization | null;
  role: Role | null;
};

export const TONES = [
  { value: "friendly_professional", label: "Amable y profesional" },
  { value: "formal", label: "Formal y respetuoso" },
  { value: "casual", label: "Cercano e informal" },
  { value: "enthusiastic", label: "Entusiasta y motivador" },
  { value: "concise", label: "Directo y conciso" },
] as const;

export const LANGUAGES = [
  { value: "es", label: "Español" },
  { value: "en", label: "English" },
  { value: "pt", label: "Português" },
] as const;

export type Agent = {
  id: number;
  public_id: string;
  name: string;
  role: string | null;
  goal: string | null;
  tone: string;
  language: string;
  instructions: string | null;
  welcome_message: string | null;
  is_active: boolean;
  widget_position: "left" | "right";
  widget_color: string;
  allowed_domains: string[];
  knowledge_base_id: number | null;
  knowledge_base?: { id: number; name: string } | null;
};

export type KnowledgeBase = {
  id: number;
  name: string;
  description: string | null;
  documents_count?: number;
  ready_documents_count?: number;
};

export type DocumentType = "text" | "faq" | "file";
export type DocumentStatus = "pending" | "processing" | "ready" | "failed";

export type KnowledgeDocument = {
  id: number;
  knowledge_base_id: number;
  type: DocumentType;
  title: string;
  original_filename: string | null;
  size_bytes: number | null;
  status: DocumentStatus;
  error: string | null;
  chunk_count: number;
  content?: string | null;
};

export type SearchResult = {
  document_id: number;
  title: string;
  content: string;
  similarity: number;
};

export type ChatMessage = { role: "user" | "assistant"; content: string };

export type TestChatResponse = {
  reply: string;
  action: "none" | "request_human";
  lead: { name: string | null; phone: string | null; email: string | null; interest: string | null } | null;
  model: string;
  usage: { input_tokens: number; output_tokens: number };
  sources: { document_id: number; title: string; similarity: number; excerpt: string }[];
  system_prompt: string;
};

export type Paginated<T> = {
  data: T[];
  meta: { current_page: number; last_page: number; total: number };
};

export type ConversationStatus = "ai" | "waiting" | "human" | "closed";

export type ConversationSummary = {
  id: number;
  agent_id: number | null;
  agent_name: string;
  contact: { id: number; name: string };
  status: ConversationStatus;
  handoff_reason: "visitor" | "ai" | null;
  handoff_at: string | null;
  closed_at: string | null;
  assigned_to?: { id: number; name: string } | null;
  lead?: Lead | null;
  channel: string;
  page_url: string | null;
  message_count: number;
  last_message_at: string | null;
  created_at: string;
  last_message: { role: "user" | "assistant" | "human"; preview: string } | null;
};

export type ConversationMessage = {
  id: number;
  role: "user" | "assistant" | "human";
  sender_name: string | null;
  content: string;
  model: string | null;
  input_tokens: number;
  output_tokens: number;
  sources: { document_id: number; title: string; similarity: number }[];
  created_at: string;
};

export type ConversationDetail = ConversationSummary & { messages: ConversationMessage[] };

export type LeadStatus = "new" | "contacted" | "qualified" | "converted" | "lost";

export type Lead = {
  id: number;
  name: string | null;
  phone: string | null;
  email: string | null;
  interest: string | null;
  status: LeadStatus;
  notes: string | null;
  conversation_id: number | null;
  agent_id: number | null;
  created_at: string;
  updated_at: string;
};

export type DashboardSummary = {
  conversations_30d: number;
  messages_30d: number;
  leads_30d: number;
  tokens_30d: number;
  conversations_total: number;
  waiting_conversations: number;
  new_leads: number;
  daily: { date: string; conversations: number }[];
  recent_leads: Lead[];
};

export type Alerts = { waiting_conversations: number; new_leads: number };

export type Plan = {
  slug: string;
  name: string;
  description: string | null;
  price_cents: number;
  currency: string;
  max_agents: number;
  max_monthly_messages: number;
  max_sites: number | null;
  max_users: number;
  features: { human_inbox: boolean; widget_customization: boolean; analytics: boolean };
  purchasable: boolean;
};

export type BillingState =
  | "active"
  | "trialing"
  | "past_due"
  | "expired"
  | "canceled"
  | "unpaid"
  | "incomplete"
  | "suspended"
  | "none";

export type BillingSummary = {
  plan: Plan | null;
  state: BillingState;
  status: string | null;
  source: "stripe" | "manual" | "trial" | null;
  trial_ends_at: string | null;
  period_start: string;
  period_end: string;
  cancel_at_period_end: boolean;
  usage: { messages_used: number; messages_limit: number; percent: number };
  agents: { active: number; limit: number };
  users: { members: number; limit: number };
  suspension_reason: string | null;
  stripe: { configured: boolean; has_customer: boolean; paying: boolean };
  can_manage: boolean;
  plans: Plan[];
};

// ---------- equipo ----------

export type TeamMember = {
  id: number;
  name: string;
  email: string;
  role: Role;
  joined_at: string | null;
  is_you: boolean;
  /** Si quien consulta puede cambiarle el rol o quitarlo. */
  can_edit: boolean;
};

export type TeamInvitation = {
  id: number;
  email: string;
  role: Exclude<Role, "owner">;
  invited_by: string | null;
  created_at: string | null;
  expires_at: string;
  expired: boolean;
  can_edit: boolean;
};

export type Team = {
  members: TeamMember[];
  /** Solo para quien administra el equipo. */
  invitations: TeamInvitation[];
  seats: { members: number; pending: number; used: number; limit: number };
  can_manage: boolean;
};

/**
 * Respuesta de crear o reenviar una invitación. El enlace NO viene: solo llega por correo, porque es lo que prueba que quien
 * se une controla ese buzón (si lo viera quien invita, podría crear cuentas verificadas para correos ajenos).
 */
export type IssuedInvitation = { invitation: TeamInvitation; emailed: boolean };

export type InvitationPreview = {
  email: string;
  role: Exclude<Role, "owner">;
  organization: { name: string; logo_url: string | null };
  invited_by: string | null;
  expires_at: string;
  account_exists: boolean;
};

// ---------- analíticas ----------

export type AnalyticsTotals = {
  conversations: number;
  visitors: number;
  visitor_messages: number;
  leads: number;
  /** Porcentajes (0–100) o null si no hubo conversaciones. */
  with_lead_rate: number | null;
  handoff_rate: number | null;
  ai_only_rate: number | null;
  messages_per_conversation: number | null;
  first_human_response_minutes: number | null;
};

export type Analytics = {
  days: 7 | 30 | 90;
  from: string;
  to: string;
  totals: AnalyticsTotals;
  previous: AnalyticsTotals;
  daily: { date: string; conversations: number; leads: number }[];
  /** Conversaciones que empezaron en cada hora del día (índice 0–23), en la zona horaria de la empresa. */
  by_hour: number[];
  by_agent: { agent: string; conversations: number; leads: number; handoffs: number }[];
  gaps: { question: string; count: number; last_at: string }[];
};

// ---------- superadmin ----------

export type AdminOverview = {
  month: string;
  mrr_cents: number;
  ai_cost_cents: number;
  infrastructure_cost_cents: number;
  margin_cents: number;
  organizations_total: number;
  organizations_new_30d: number;
  subscriptions: { paying: number; trialing: number; manual: number; past_due: number; canceled: number };
  messages_month: number;
  tokens_month: number;
  daily: { date: string; ai_cost_cents: number; messages: number }[];
};

export type AdminOrganization = {
  id: number;
  name: string;
  created_at: string;
  owner: { name: string; email: string } | null;
  members_count: number;
  agents_count: number;
  plan: { slug: string; name: string } | null;
  state: BillingState;
  source: string | null;
  messages_used: number;
  messages_limit: number;
  suspended: boolean;
};

export type AuditEntry = {
  id: number;
  action: string;
  actor: string | null;
  organization_id: number | null;
  organization: string | null;
  metadata: Record<string, unknown> | null;
  created_at: string;
};

export type AdminOrganizationDetail = AdminOrganization & {
  slug: string;
  suspension_reason: string | null;
  subscription: {
    status: string;
    source: string;
    trial_ends_at: string | null;
    current_period_end: string | null;
    cancel_at_period_end: boolean;
    stripe_customer_id: string | null;
    stripe_subscription_id: string | null;
  } | null;
  period_start: string;
  period_end: string;
  ai_cost_micros_month: number;
  members: { id: number; name: string; email: string; role: string }[];
  agents: { id: number; name: string; is_active: boolean; domains: string[] }[];
  audit: AuditEntry[];
};

export type AdminPlan = {
  id: number;
  slug: string;
  name: string;
  description: string | null;
  price_cents: number;
  currency: string;
  stripe_price_id: string | null;
  max_agents: number;
  max_monthly_messages: number;
  max_sites: number | null;
  max_users: number;
  is_active: boolean;
  features: { human_inbox: boolean; widget_customization: boolean; analytics: boolean };
  subscribers: number;
};

export type AdminUser = {
  id: number;
  name: string;
  email: string;
  verified: boolean;
  is_superadmin: boolean;
  organizations: { id: number; name: string; role: string }[];
  created_at: string;
};

export type AdminUsage = {
  days: number;
  by_model: { model: string; type: string; messages: number; calls: number; input_tokens: number; output_tokens: number; cost_micros: number }[];
  by_organization: { id: number; name: string; messages: number; tokens: number; cost_micros: number }[];
  by_source: { source: string; calls: number; cost_micros: number }[];
};

export type AdminSettings = {
  ai: {
    chat_provider: string;
    embeddings_provider: string;
    anthropic: { key_set: boolean; model: string; effort: string };
    openai: { key_set: boolean; embedding_model: string };
    pricing: Record<string, { input: number; output: number }>;
  };
  billing: {
    stripe_secret_set: boolean;
    stripe_webhook_secret_set: boolean;
    trial_days: number;
    trial_plan: string;
    infrastructure_cost_usd: number;
  };
  runtime: { queue: string; mail: string; environment: string };
};
