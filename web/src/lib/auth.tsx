"use client";

import { createContext, useCallback, useContext, useEffect, useMemo, useState } from "react";
import { api, ApiError } from "./api";
import type { User } from "./types";

type Status = "loading" | "authenticated" | "guest";

type AuthContextValue = {
  user: User | null;
  status: Status;
  refresh: () => Promise<User | null>;
  login: (email: string, password: string, remember: boolean) => Promise<User | null>;
  register: (input: {
    name: string;
    email: string;
    password: string;
    password_confirmation: string;
  }) => Promise<User | null>;
  logout: () => Promise<void>;
};

const AuthContext = createContext<AuthContextValue | null>(null);

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [status, setStatus] = useState<Status>("loading");

  const refresh = useCallback(async () => {
    try {
      const current = await api<User>("/user");
      setUser(current);
      setStatus("authenticated");
      return current;
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) {
        setUser(null);
        setStatus("guest");
        return null;
      }
      throw error;
    }
  }, []);

  useEffect(() => {
    // Carga inicial de la sesión (sincroniza con la cookie del servidor).
    // eslint-disable-next-line react-hooks/set-state-in-effect
    refresh().catch(() => setStatus("guest"));
  }, [refresh]);

  const value = useMemo<AuthContextValue>(
    () => ({
      user,
      status,
      refresh,
      login: async (email, password, remember) => {
        await api("/login", { method: "POST", body: { email, password, remember } });
        return refresh();
      },
      register: async (input) => {
        await api("/register", { method: "POST", body: input });
        return refresh();
      },
      logout: async () => {
        await api("/logout", { method: "POST" });
        setUser(null);
        setStatus("guest");
      },
    }),
    [user, status, refresh],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
  const context = useContext(AuthContext);
  if (!context) throw new Error("useAuth debe usarse dentro de <AuthProvider>");
  return context;
}

/** Siguiente paso del usuario: verificar correo → crear empresa → panel. */
export function homeFor(user: User): string {
  if (!user.email_verified) return "/verify-email";
  if (!user.organization) return "/onboarding";
  return "/dashboard";
}

/**
 * Ruta a la que volver después de iniciar sesión (`?next=/invitations/…`). Solo rutas internas: «//sitio.com» o
 * «/\sitio.com» los navegadores los tratan como otro origen (redirección abierta).
 */
export function safeNext(search: string): string | null {
  const next = new URLSearchParams(search).get("next");
  return next && /^\/(?![/\\])/.test(next) ? next : null;
}

export const ROLE_LABELS = { owner: "Propietario", admin: "Administrador", agent: "Agente" } as const;

/** Propietario y administradores pueden crear/editar/borrar; el resto de miembros solo consulta. */
export function useCanManage() {
  const { user } = useAuth();
  return user?.role === "owner" || user?.role === "admin";
}
