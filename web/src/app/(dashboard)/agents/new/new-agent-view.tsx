"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect } from "react";
import { AgentForm } from "@/components/agents/AgentForm";
import { Card } from "@/components/ui";
import { useAuth, useCanManage } from "@/lib/auth";

export function NewAgentView() {
  const { status } = useAuth();
  const canManage = useCanManage();
  const router = useRouter();

  // Los miembros sin permiso de gestión no pueden crear agentes.
  useEffect(() => {
    if (status === "authenticated" && !canManage) router.replace("/agents");
  }, [status, canManage, router]);

  return (
    <div className="space-y-6">
      <div>
        <Link href="/agents" className="text-sm text-muted hover:underline">
          ← Agentes
        </Link>
        <h1 className="mt-2 text-2xl font-semibold tracking-tight">Nuevo agente</h1>
      </div>
      <Card>
        <AgentForm />
      </Card>
    </div>
  );
}
