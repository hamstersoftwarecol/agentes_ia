"use client";

import { useCallback, useEffect, useState } from "react";
import { api, ApiError } from "./api";

type State<T> = { data: T | undefined; error: ApiError | null; loading: boolean };

/**
 * GET a la API con estado de carga/error. `path` null = no cargar todavía.
 * `pollWhile(data)` devuelve los ms a esperar antes de volver a consultar, o false para parar
 * (se usa para ver cómo avanza el procesamiento de documentos).
 */
export function useApi<T>(path: string | null, pollWhile?: (data: T) => number | false) {
  const [state, setState] = useState<State<T>>({ data: undefined, error: null, loading: path !== null });

  const reload = useCallback(async () => {
    if (path === null) return;
    try {
      const data = await api<T>(path);
      setState({ data, error: null, loading: false });
    } catch (e) {
      setState((s) => ({
        data: s.data,
        error: e instanceof ApiError ? e : new ApiError(0, "No pudimos conectar con el servidor."),
        loading: false,
      }));
    }
  }, [path]);

  useEffect(() => {
    // Carga inicial desde la API (sincroniza el estado con el servidor).
    void reload();
  }, [reload]);

  const delay = state.data !== undefined && pollWhile ? pollWhile(state.data) : false;
  useEffect(() => {
    if (delay === false) return;
    const timer = setTimeout(() => void reload(), delay);
    return () => clearTimeout(timer);
  }, [delay, state, reload]);

  return { ...state, reload };
}
