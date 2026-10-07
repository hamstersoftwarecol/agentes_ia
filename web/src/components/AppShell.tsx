"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useState } from "react";
import { useAuth } from "@/lib/auth";
import type { Alerts } from "@/lib/types";
import { useApi } from "@/lib/use-api";
import { BillingProvider, PlanBanner } from "./BillingProvider";
import { cn, Logo } from "./ui";

type NavItem = { href?: string; label: string; badge?: "waiting_conversations" | "new_leads" };

const NAV: { title?: string; items: NavItem[] }[] = [
  {
    items: [
      { href: "/dashboard", label: "Resumen" },
      { href: "/agents", label: "Agentes" },
      { href: "/knowledge", label: "Conocimiento" },
      { href: "/conversations", label: "Conversaciones", badge: "waiting_conversations" },
      { href: "/leads", label: "Leads", badge: "new_leads" },
      { href: "/analytics", label: "Analíticas" },
    ],
  },
  {
    title: "Configuración",
    items: [
      { href: "/team", label: "Equipo" },
      { href: "/billing", label: "Facturación" },
      { href: "/settings/organization", label: "Empresa" },
      { href: "/settings/profile", label: "Mi perfil" },
    ],
  },
];

// /agents/12 también marca "Agentes"; /dashboard solo se marca en su ruta exacta.
function isActive(pathname: string, href: string) {
  return pathname === href || pathname.startsWith(`${href}/`);
}

export function AppShell({ children }: { children: React.ReactNode }) {
  const { user, logout } = useAuth();
  const pathname = usePathname();
  const router = useRouter();
  const [open, setOpen] = useState(false);
  const organization = user?.organization;
  // Lo que pide acción del equipo: conversaciones esperando a una persona y leads nuevos.
  const alerts = useApi<Alerts>("/dashboard/alerts", () => 10000);

  const nav = (
    <nav className="flex flex-1 flex-col gap-6 overflow-y-auto p-3" aria-label="Principal">
      {NAV.map((group, i) => (
        <div key={i} className="space-y-1">
          {group.title && (
            <p className="px-3 pb-1 text-xs font-medium uppercase tracking-wide text-muted">{group.title}</p>
          )}
          {group.items.map((item) =>
            item.href ? (
              <Link
                key={item.label}
                href={item.href}
                onClick={() => setOpen(false)}
                aria-current={isActive(pathname, item.href) ? "page" : undefined}
                className={cn(
                  "flex items-center justify-between rounded-lg px-3 py-2 text-sm hover:bg-brand-soft",
                  isActive(pathname, item.href) && "bg-brand-soft font-medium",
                )}
              >
                {item.label}
                {item.badge && (alerts.data?.[item.badge] ?? 0) > 0 && (
                  <span
                    className="rounded-full bg-brand px-1.5 py-0.5 text-[10px] font-semibold leading-none text-white dark:text-stone-950"
                    aria-label={`${alerts.data?.[item.badge]} pendiente${alerts.data?.[item.badge] === 1 ? "" : "s"}`}
                  >
                    {alerts.data?.[item.badge]}
                  </span>
                )}
              </Link>
            ) : (
              <span
                key={item.label}
                aria-disabled="true"
                className="flex items-center justify-between rounded-lg px-3 py-2 text-sm text-muted"
              >
                {item.label}
                <span className="rounded-full border border-border px-2 py-0.5 text-[10px] uppercase">Pronto</span>
              </span>
            ),
          )}
        </div>
      ))}
    </nav>
  );

  return (
    <BillingProvider>
    <div className="flex min-h-screen flex-1">
      <aside
        className={cn(
          "fixed inset-y-0 left-0 z-20 flex w-64 flex-col border-r border-border bg-card transition-transform md:static md:translate-x-0",
          open ? "translate-x-0" : "-translate-x-full",
        )}
      >
        <div className="border-b border-border p-4">
          <Logo />
        </div>
        {nav}
        <div className="space-y-2 border-t border-border p-4 text-sm">
          <div className="flex items-center gap-3">
            {organization?.logo_url ? (
              // eslint-disable-next-line @next/next/no-img-element
              <img src={organization.logo_url} alt="" className="size-8 rounded-lg object-cover" />
            ) : (
              <span className="grid size-8 place-items-center rounded-lg bg-brand-soft font-semibold">
                {organization?.name.charAt(0).toUpperCase()}
              </span>
            )}
            <div className="min-w-0">
              <p className="truncate font-medium">{organization?.name}</p>
              <p className="truncate text-xs text-muted">{user?.email}</p>
            </div>
          </div>
          {user?.is_superadmin && (
            <Link href="/admin" className="block text-brand hover:underline">
              Panel de plataforma
            </Link>
          )}
          <button
            type="button"
            className="text-brand hover:underline"
            onClick={async () => {
              await logout();
              router.replace("/login");
            }}
          >
            Cerrar sesión
          </button>
        </div>
      </aside>

      {open && <div className="fixed inset-0 z-10 bg-black/40 md:hidden" onClick={() => setOpen(false)} aria-hidden />}

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="flex items-center gap-3 border-b border-border bg-card p-3 md:hidden">
          <button
            type="button"
            aria-label="Abrir menú"
            onClick={() => setOpen(true)}
            className="rounded-lg border border-border px-3 py-1.5 text-sm"
          >
            Menú
          </button>
          <Logo className="text-sm" />
        </header>
        <main className="mx-auto w-full max-w-4xl flex-1 space-y-6 p-6 md:p-10">
          <PlanBanner />
          {children}
        </main>
      </div>
    </div>
    </BillingProvider>
  );
}
