import { forwardRef, useId } from "react";

export function cn(...parts: (string | false | null | undefined)[]) {
  return parts.filter(Boolean).join(" ");
}

export function Spinner({ className }: { className?: string }) {
  return (
    <svg
      className={cn("animate-spin text-muted", className ?? "size-4")}
      viewBox="0 0 24 24"
      fill="none"
      role="status"
      aria-label="Cargando"
    >
      <circle cx="12" cy="12" r="10" stroke="currentColor" strokeOpacity="0.25" strokeWidth="4" />
      <path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" strokeWidth="4" strokeLinecap="round" />
    </svg>
  );
}

type ButtonProps = React.ButtonHTMLAttributes<HTMLButtonElement> & {
  variant?: "primary" | "secondary" | "danger" | "ghost";
  loading?: boolean;
};

const buttonStyles = {
  primary: "bg-brand text-white hover:bg-brand-hover dark:text-stone-950",
  secondary: "border border-border bg-card hover:bg-brand-soft",
  danger: "border border-danger/40 text-danger hover:bg-danger-soft",
  ghost: "hover:bg-brand-soft",
};

export function Button({ variant = "primary", loading, disabled, className, children, ...props }: ButtonProps) {
  return (
    <button
      {...props}
      disabled={disabled || loading}
      className={cn(
        "inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-medium transition-colors",
        "focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand",
        "disabled:cursor-not-allowed disabled:opacity-60",
        buttonStyles[variant],
        className,
      )}
    >
      {loading && <Spinner className="size-4 text-current" />}
      {children}
    </button>
  );
}

const controlStyles =
  "w-full rounded-lg border border-border bg-card px-3 py-2 text-sm placeholder:text-muted " +
  "focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-brand " +
  "disabled:cursor-not-allowed disabled:opacity-60 aria-[invalid=true]:border-danger";

type FieldShellProps = { label: string; error?: string; hint?: string; htmlFor: string; children: React.ReactNode };

function FieldShell({ label, error, hint, htmlFor, children }: FieldShellProps) {
  return (
    <div className="space-y-1.5">
      <label htmlFor={htmlFor} className="block text-sm font-medium">
        {label}
      </label>
      {children}
      {hint && !error && <p className="text-xs text-muted">{hint}</p>}
      {error && (
        <p className="text-xs text-danger" role="alert">
          {error}
        </p>
      )}
    </div>
  );
}

type InputProps = React.InputHTMLAttributes<HTMLInputElement> & { label: string; error?: string; hint?: string };

export const Input = forwardRef<HTMLInputElement, InputProps>(function Input(
  { label, error, hint, className, id, ...props },
  ref,
) {
  const autoId = useId();
  const inputId = id ?? autoId;
  return (
    <FieldShell label={label} error={error} hint={hint} htmlFor={inputId}>
      <input
        ref={ref}
        id={inputId}
        aria-invalid={error ? true : undefined}
        className={cn(controlStyles, className)}
        {...props}
      />
    </FieldShell>
  );
});

type SelectProps = React.SelectHTMLAttributes<HTMLSelectElement> & { label: string; error?: string; hint?: string };

export function Select({ label, error, hint, className, id, children, ...props }: SelectProps) {
  const autoId = useId();
  const selectId = id ?? autoId;
  return (
    <FieldShell label={label} error={error} hint={hint} htmlFor={selectId}>
      <select
        id={selectId}
        aria-invalid={error ? true : undefined}
        className={cn(controlStyles, className)}
        {...props}
      >
        {children}
      </select>
    </FieldShell>
  );
}

type TextareaProps = React.TextareaHTMLAttributes<HTMLTextAreaElement> & { label: string; error?: string; hint?: string };

export function Textarea({ label, error, hint, className, id, ...props }: TextareaProps) {
  const autoId = useId();
  const textareaId = id ?? autoId;
  return (
    <FieldShell label={label} error={error} hint={hint} htmlFor={textareaId}>
      <textarea
        id={textareaId}
        aria-invalid={error ? true : undefined}
        className={cn(controlStyles, "min-h-24 resize-y", className)}
        {...props}
      />
    </FieldShell>
  );
}

export function Badge({
  tone = "neutral",
  children,
}: {
  tone?: "neutral" | "success" | "danger" | "info" | "blue";
  children: React.ReactNode;
}) {
  const styles = {
    neutral: "bg-border/60 text-muted",
    success: "bg-success-soft text-success",
    danger: "bg-danger-soft text-danger",
    info: "bg-brand-soft text-foreground",
    blue: "bg-info-soft text-info",
  };
  return (
    <span className={cn("inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium", styles[tone])}>
      {children}
    </span>
  );
}

export function Card({ className, children }: { className?: string; children: React.ReactNode }) {
  return <section className={cn("rounded-xl border border-border bg-card p-6", className)}>{children}</section>;
}

export function Alert({
  tone = "danger",
  children,
}: {
  tone?: "danger" | "success" | "info";
  children: React.ReactNode;
}) {
  const styles = {
    danger: "bg-danger-soft text-danger",
    success: "bg-success-soft text-success",
    info: "bg-brand-soft text-foreground",
  };
  return (
    <div
      role={tone === "danger" ? "alert" : "status"}
      className={cn("rounded-lg px-3 py-2 text-sm", styles[tone])}
    >
      {children}
    </div>
  );
}

export function Logo({ className }: { className?: string }) {
  return (
    <span className={cn("inline-flex items-center gap-2 font-semibold tracking-tight", className)}>
      <span aria-hidden className="grid size-7 place-items-center rounded-lg bg-brand-soft text-base">
        🐹
      </span>
      Hamster AI Agents
    </span>
  );
}
