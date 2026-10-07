export class ApiError extends Error {
  constructor(
    public status: number,
    message: string,
    public errors: Record<string, string[]> = {},
    /** Código estable que envía la API (p. ej. «plan_limit», «billing_unavailable»). */
    public code?: string,
  ) {
    super(message);
  }

  /** Primer mensaje de validación de un campo, si existe. */
  field(name: string): string | undefined {
    return this.errors[name]?.[0];
  }
}

function getCookie(name: string): string | null {
  const match = document.cookie
    .split("; ")
    .find((row) => row.startsWith(`${name}=`));
  return match ? decodeURIComponent(match.split("=").slice(1).join("=")) : null;
}

async function ensureCsrf(force = false) {
  if (!force && getCookie("XSRF-TOKEN")) return;
  await fetch("/api/sanctum/csrf-cookie", { credentials: "same-origin" });
}

type Options = { method?: "GET" | "POST" | "PUT" | "PATCH" | "DELETE"; body?: unknown };

/**
 * Cliente de la API Laravel (sesión por cookie + CSRF de Sanctum).
 * `path` va sin el prefijo /api, p. ej. api("/user").
 */
export async function api<T = void>(path: string, { method = "GET", body }: Options = {}): Promise<T> {
  const send = async () => {
    const headers: Record<string, string> = {
      Accept: "application/json",
      "X-Requested-With": "XMLHttpRequest",
    };
    if (method !== "GET") {
      await ensureCsrf();
      const token = getCookie("XSRF-TOKEN");
      if (token) headers["X-XSRF-TOKEN"] = token;
    }

    let payload: BodyInit | undefined;
    if (body instanceof FormData) {
      payload = body;
    } else if (body !== undefined) {
      headers["Content-Type"] = "application/json";
      payload = JSON.stringify(body);
    }

    return fetch(`/api${path}`, { method, headers, body: payload, credentials: "same-origin" });
  };

  let res = await send();
  if (res.status === 419) {
    // Token CSRF vencido (p. ej. tras cerrar sesión): se renueva y se reintenta una vez.
    await ensureCsrf(true);
    res = await send();
  }

  if (res.status === 204) return undefined as T;

  const data = await res.json().catch(() => null);
  if (!res.ok) {
    throw new ApiError(
      res.status,
      data?.message ?? "Algo salió mal. Inténtalo de nuevo.",
      data?.errors ?? {},
      data?.code,
    );
  }
  return data as T;
}
