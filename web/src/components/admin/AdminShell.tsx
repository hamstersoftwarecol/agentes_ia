"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useEffect } from "react";
import { cn, Logo, Spinner } from "@/components/ui";
import { useAuth } from "@/lib/auth";

const NAV = [
  { href: "/admin", label: "Resumen", exact: true },
  { href: "/admin/organizations", label: "Empresas" },
  { href: "/admin/plans", label: "Planes" },
  { href: "/admin/usage", label: "Uso de IA" },
  { href: "/admin/users", label: "Usuarios" },
  { href: "/admin/audit", label: "Auditoría" },
  { href: "/admin/settings", label: "Configuración" },
];

/** Área de la plataforma: solo superadmin. A cualquiera más se le devuelve a su panel (la API además responde 404). */
export function AdminShell({ children }: { children: React.ReactNode }) {
  const { user, status } = useAuth();
  const router = useRouter();

  const target = status === "loading" ? null : !user ? "/login" : !user.email_verified ? "/verify-email" : !user.is_superadmin ? "/dashboard" : null;
  useEffect(() => {
    if (target) router.replace(target);
  }, [target, router]);

  if (status === "loading" || target || !user) {
    return (
      <div className="flex min-h-screen items-center justify-center">
        <Spinner className="size-6" />
      </div>
    );
  }

  return <AdminFrame email={user.email} hasOrganization={!!user.organization}>{children}</AdminFrame>;
}

/** Marco con la navegación. Va aparte del guard: usePathname no puede ejecutarse durante el prerender de rutas dinámicas. */
function AdminFrame({ email, hasOrganization, children }: { email: string; hasOrganization: boolean; children: React.ReactNode }) {
  const pathname = usePathname();

  return (
    <div className="flex min-h-screen flex-1 flex-col md:flex-row">
      <aside className="border-b border-border bg-card md:w-60 md:border-r md:border-b-0">
        <div className="flex items-center justify-between gap-3 p-4">
          <div>
            <Logo />
            <p className="mt-1 text-xs font-medium uppercase tracking-wide text-muted">Plataforma</p>
          </div>
        </div>
        <nav className="flex gap-1 overflow-x-auto px-3 pb-3 md:flex-col md:pb-0" aria-label="Superadmin">
          {NAV.map((item) => {
            const active = item.exact ? pathname === item.href : pathname.startsWith(item.href);
            return (
              <Link
                key={item.href}
                href={item.href}
                aria-current={active ? "page" : undefined}
                className={cn("shrink-0 rounded-lg px-3 py-2 text-sm hover:bg-brand-soft", active && "bg-brand-soft font-medium")}
              >
                {item.label}
              </Link>
            );
          })}
        </nav>
        <div className="hidden space-y-1 border-t border-border p-4 text-sm md:mt-6 md:block">
          <p className="truncate text-muted">{email}</p>
          {hasOrganization && (
            <Link href="/dashboard" className="text-brand hover:underline">
              ← Volver a mi panel
            </Link>
          )}
        </div>
      </aside>
      <main className="mx-auto w-full max-w-6xl flex-1 space-y-6 p-6 md:p-10">{children}</main>
    </div>
  );
}
