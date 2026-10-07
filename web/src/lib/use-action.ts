"use client";

import { useState } from "react";
import { ApiError } from "./api";

/** Estado de una acción asíncrona (enviar un formulario): pendiente, error y éxito. */
export function useAction<A extends unknown[]>(fn: (...args: A) => Promise<unknown>) {
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);
  const [done, setDone] = useState(false);

  async function run(...args: A): Promise<boolean> {
    setPending(true);
    setError(null);
    setDone(false);
    try {
      await fn(...args);
      setDone(true);
      return true;
    } catch (e) {
      setError(e instanceof ApiError ? e : new ApiError(0, "No pudimos conectar con el servidor."));
      return false;
    } finally {
      setPending(false);
    }
  }

  return { run, pending, error, done };
}
