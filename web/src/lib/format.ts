const dateTime = new Intl.DateTimeFormat("es", { dateStyle: "medium", timeStyle: "short" });
const time = new Intl.DateTimeFormat("es", { timeStyle: "short" });
const day = new Intl.DateTimeFormat("es", { dateStyle: "medium" });

export const formatDateTime = (iso: string) => dateTime.format(new Date(iso));
export const formatDay = (iso: string) => day.format(new Date(iso));

/** «14:05» si es de hoy; si no, la fecha. Para listas de conversaciones. */
export function formatRecent(iso: string) {
  const date = new Date(iso);
  return date.toDateString() === new Date().toDateString() ? time.format(date) : day.format(date);
}

const compact = new Intl.NumberFormat("es", { notation: "compact", maximumFractionDigits: 1 });
const full = new Intl.NumberFormat("es");

/** 1284 → «1284», 12900 → «12,9 mil», 4 200 000 → «4,2 M». */
export const formatCompact = (n: number) => (n < 10000 ? full.format(n) : compact.format(n));
export const formatNumber = (n: number) => full.format(n);

/** 1900 → «$19»; 1950 → «$19,50». Valores de dinero en la API van en centavos de dólar. */
export function formatMoney(cents: number) {
  const whole = cents % 100 === 0;
  // «$19» (en-US): en español el símbolo iría al final («19 $») y los dólares se escriben delante.
  return new Intl.NumberFormat("en-US", {
    style: "currency",
    currency: "USD",
    minimumFractionDigits: whole ? 0 : 2,
    maximumFractionDigits: 2,
  }).format(cents / 100);
}

/** Costo de IA: micro-USD con la precisión que haga falta (los céntimos de un mensaje son fracciones de centavo). */
export function formatMicros(micros: number) {
  const usd = micros / 1_000_000;
  return new Intl.NumberFormat("en-US", {
    style: "currency",
    currency: "USD",
    minimumFractionDigits: 2,
    maximumFractionDigits: usd < 1 ? 4 : 2,
  }).format(usd);
}

/** Días completos que faltan hasta una fecha (0 si ya pasó). */
export const daysUntil = (iso: string) => Math.max(0, Math.ceil((new Date(iso).getTime() - Date.now()) / 86_400_000));

/** «2026-10-15» (solo fecha, sin hora ni zona) como día local: new Date("2026-10-15") sería UTC y podría salir el día anterior. */
export function formatDateOnly(iso: string) {
  const [y, m, d] = iso.split("-").map(Number);
  return day.format(new Date(y, m - 1, d));
}

const decimal = new Intl.NumberFormat("es", { maximumFractionDigits: 1 });

/** 12.5 → «12,5 %»; null (no hubo datos) → «—». */
export const formatPercent = (value: number | null) => (value === null ? "—" : `${decimal.format(value)} %`);

/** Un decimal como mucho: 2.3 → «2,3». */
export const formatDecimal = (value: number | null) => (value === null ? "—" : decimal.format(value));

/** 0.4 → «menos de 1 min»; 12 → «12 min»; 95 → «1,6 h». */
export function formatMinutes(minutes: number | null) {
  if (minutes === null) return "—";
  if (minutes < 1) return "menos de 1 min";
  if (minutes < 60) return `${Math.round(minutes)} min`;
  return `${decimal.format(minutes / 60)} h`;
}
