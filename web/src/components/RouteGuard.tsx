"use client";

import { useRouter } from "next/navigation";
import { useEffect } from "react";
import { homeFor, safeNext, useAuth } from "@/lib/auth";
import { Spinner } from "./ui";

/**
 * guest      → solo visitantes (login, registro)
 * unverified → pantalla de verificar correo
 * no-org     → pantalla de crear empresa
 * ready      → panel: sesión + correo verificado + empresa creada
 */
export type Rule = "guest" | "unverified" | "no-org" | "ready";

export function RouteGuard({ rule, children }: { rule: Rule; children: React.ReactNode }) {
  const { user, status } = useAuth();
  const router = useRouter();

  let target: string | null = null;
  if (status !== "loading") {
    if (rule === "guest") {
      // Quien llegó a iniciar sesión desde un enlace (p. ej. una invitación) vuelve a él, no al panel.
      if (user) target = safeNext(window.location.search) ?? homeFor(user);
    } else if (!user) {
      target = "/login";
    } else {
      const home = homeFor(user);
      const here = { unverified: "/verify-email", "no-org": "/onboarding", ready: "/dashboard" }[rule];
      if (home !== here) target = home;
    }
  }

  useEffect(() => {
    if (target) router.replace(target);
  }, [target, router]);

  if (status === "loading" || target) {
    return (
      <div className="flex min-h-screen items-center justify-center">
        <Spinner className="size-6" />
      </div>
    );
  }

  return <>{children}</>;
}
