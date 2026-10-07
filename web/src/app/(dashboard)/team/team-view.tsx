"use client";

import Link from "next/link";
import { useState } from "react";
import { PlanLimitAlert } from "@/components/BillingProvider";
import { Alert, Badge, Button, Card, cn, Input, Select, Spinner } from "@/components/ui";
import { api } from "@/lib/api";
import { ROLE_LABELS, useAuth } from "@/lib/auth";
import { formatDay } from "@/lib/format";
import type { IssuedInvitation, Role, Team, TeamInvitation, TeamMember } from "@/lib/types";
import { useAction } from "@/lib/use-action";
import { useApi } from "@/lib/use-api";

export function TeamView() {
  const { user } = useAuth();
  const team = useApi<Team>("/team");
  // Resultado de la última invitación enviada o reenviada (si el correo salió o no).
  const [issued, setIssued] = useState<IssuedInvitation | null>(null);
  const reload = () => void team.reload();
  const data = team.data;

  return (
    <div className="space-y-8">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">Equipo</h1>
        <p className="mt-1 text-sm text-muted">
          Las personas que atienden conversaciones y leads, y las que administran la empresa.
        </p>
      </div>

      {team.loading && <Spinner className="size-5" />}
      {team.error && <Alert>{team.error.message}</Alert>}

      {data && (
        <>
          <SeatsCard seats={data.seats} canManage={data.can_manage} />

          <Card>
            <h2 className="font-semibold">Miembros</h2>
            <ul className="mt-3 divide-y divide-border">
              {data.members.map((member) => (
                <MemberRow key={member.id} member={member} myRole={user?.role ?? null} onChanged={reload} />
              ))}
            </ul>
            <p className="mt-4 text-xs text-muted">
              Los <strong>agentes</strong> ven el panel y atienden conversaciones y leads. Los <strong>administradores</strong> además
              gestionan agentes de IA, conocimiento y equipo. Solo el <strong>propietario</strong> cambia el plan y los roles.
            </p>
          </Card>

          {data.can_manage && (
            <>
              <InviteCard
                myRole={user?.role ?? null}
                full={data.seats.used >= data.seats.limit}
                onIssued={(result) => {
                  setIssued(result);
                  reload();
                }}
              />
              {issued && <IssuedNotice issued={issued} onClose={() => setIssued(null)} />}
              {data.invitations.length > 0 && (
                <Card>
                  <h2 className="font-semibold">Invitaciones pendientes</h2>
                  <ul className="mt-3 divide-y divide-border">
                    {data.invitations.map((invitation) => (
                      <InvitationRow
                        key={invitation.id}
                        invitation={invitation}
                        onResent={(result) => {
                          setIssued(result);
                          reload();
                        }}
                        onRevoked={reload}
                      />
                    ))}
                  </ul>
                </Card>
              )}
            </>
          )}
        </>
      )}
    </div>
  );
}

function SeatsCard({ seats, canManage }: { seats: Team["seats"]; canManage: boolean }) {
  const percent = seats.limit > 0 ? Math.min(100, Math.round((seats.used / seats.limit) * 100)) : 100;

  return (
    <Card>
      <div className="flex items-baseline justify-between gap-3">
        <h2 className="font-semibold">Usuarios de tu plan</h2>
        <span className="text-sm text-muted">
          {seats.used} de {seats.limit}
        </span>
      </div>
      {/* La pista es un tono más claro del mismo color: el estado se lee en toda la barra, no solo en el relleno. */}
      <div
        role="progressbar"
        aria-valuemin={0}
        aria-valuemax={seats.limit}
        aria-valuenow={seats.used}
        aria-label="Usuarios del plan"
        className="mt-3 h-2.5 overflow-hidden rounded-full bg-brand-soft"
      >
        <div className={cn("h-full rounded-full", percent >= 100 ? "bg-danger" : "bg-brand")} style={{ width: `${Math.max(percent, 2)}%` }} />
      </div>
      <p className="mt-2 text-sm text-muted">
        {seats.members} en el equipo
        {seats.pending > 0 && ` · ${seats.pending} invitación${seats.pending === 1 ? "" : "es"} pendiente${seats.pending === 1 ? "" : "s"} (ocupa${seats.pending === 1 ? "" : "n"} plaza hasta que venza${seats.pending === 1 ? "" : "n"} o se revoque${seats.pending === 1 ? "" : "n"})`}
        .
        {canManage && seats.used >= seats.limit && (
          <>
            {" "}
            <Link href="/billing" className="font-medium text-brand underline">
              Sube de plan
            </Link>{" "}
            para sumar a más personas.
          </>
        )}
      </p>
    </Card>
  );
}

function MemberRow({ member, myRole, onChanged }: { member: TeamMember; myRole: Role | null; onChanged: () => void }) {
  // Solo el propietario cambia roles; quitar a alguien lo decide el rol de quien consulta (el servidor lo vuelve a comprobar).
  const canChangeRole = member.can_edit && myRole === "owner";
  const changeRole = useAction(async (role: string) => {
    await api(`/team/members/${member.id}`, { method: "PATCH", body: { role } });
    onChanged();
  });
  const remove = useAction(async () => {
    await api(`/team/members/${member.id}`, { method: "DELETE" });
    onChanged();
  });
  const error = changeRole.error ?? remove.error;

  return (
    <li className="py-3">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="min-w-0">
          <p className="truncate font-medium">
            {member.name} {member.is_you && <Badge tone="info">Tú</Badge>}
          </p>
          <p className="truncate text-sm text-muted">{member.email}</p>
        </div>
        <div className="flex items-center gap-2">
          {canChangeRole ? (
            <Select
              label="Rol"
              value={member.role}
              disabled={changeRole.pending}
              onChange={(e) => void changeRole.run(e.target.value)}
              className="w-auto"
            >
              <option value="admin">{ROLE_LABELS.admin}</option>
              <option value="agent">{ROLE_LABELS.agent}</option>
            </Select>
          ) : (
            <Badge tone={member.role === "owner" ? "success" : "neutral"}>{ROLE_LABELS[member.role]}</Badge>
          )}
          {member.can_edit && (
            <Button
              variant="danger"
              loading={remove.pending}
              onClick={() => {
                if (window.confirm(`¿Quitar a ${member.name} del equipo? Perderá el acceso de inmediato y sus conversaciones en curso volverán a «esperando».`)) {
                  void remove.run();
                }
              }}
            >
              Quitar
            </Button>
          )}
        </div>
      </div>
      {error && (
        <div className="mt-2">
          <PlanLimitAlert error={error} />
        </div>
      )}
    </li>
  );
}

function InviteCard({ myRole, full, onIssued }: { myRole: Role | null; full: boolean; onIssued: (issued: IssuedInvitation) => void }) {
  const [email, setEmail] = useState("");
  const [role, setRole] = useState<"admin" | "agent">("agent");
  // Un administrador solo invita agentes; el propietario elige.
  const effectiveRole = myRole === "owner" ? role : "agent";

  const invite = useAction(async () => {
    const issued = await api<IssuedInvitation>("/team/invitations", { method: "POST", body: { email: email.trim(), role: effectiveRole } });
    setEmail("");
    onIssued(issued);
  });
  const field = (name: string) => invite.error?.field(name);

  return (
    <Card>
      <h2 className="font-semibold">Invitar a alguien</h2>
      <p className="mt-0.5 text-sm text-muted">
        Le llega un correo con un enlace para unirse, que vence en unos días (la lista de pendientes muestra la fecha). Si todavía no tiene
        cuenta, la crea desde ahí.
      </p>
      <form
        className="mt-4 grid gap-3 sm:grid-cols-[1fr_auto_auto] sm:items-start"
        onSubmit={(e) => {
          e.preventDefault();
          void invite.run();
        }}
      >
        <Input label="Correo electrónico" type="email" autoComplete="off" required value={email} onChange={(e) => setEmail(e.target.value)} error={field("email")} />
        {myRole === "owner" ? (
          <Select label="Rol" value={role} onChange={(e) => setRole(e.target.value as "admin" | "agent")} error={field("role")}>
            <option value="agent">{ROLE_LABELS.agent}</option>
            <option value="admin">{ROLE_LABELS.admin}</option>
          </Select>
        ) : (
          <div className="space-y-1.5">
            <p className="text-sm font-medium">Rol</p>
            <p className="py-2 text-sm">{ROLE_LABELS.agent}</p>
          </div>
        )}
        <div className="sm:pt-[1.625rem]">
          <Button type="submit" loading={invite.pending} disabled={full}>
            Invitar
          </Button>
        </div>
      </form>
      {full && <p className="mt-3 text-sm text-muted">No quedan plazas en tu plan. Revoca una invitación o sube de plan.</p>}
      {invite.error && !field("email") && !field("role") && (
        <div className="mt-3">
          <PlanLimitAlert error={invite.error} />
        </div>
      )}
    </Card>
  );
}

/** El enlace viaja solo por correo (a quien invita no se le muestra): aquí solo se informa si el envío salió. */
function IssuedNotice({ issued, onClose }: { issued: IssuedInvitation; onClose: () => void }) {
  const email = issued.invitation.email;

  return (
    <div className="flex items-start gap-3">
      <div className="min-w-0 flex-1">
        <Alert tone={issued.emailed ? "success" : "danger"}>
          {issued.emailed
            ? `Enviamos la invitación a ${email}. Si no la ve en unos minutos, que revise el correo no deseado; también puedes reenviarla desde la lista.`
            : `No pudimos enviar el correo a ${email}. La invitación quedó creada: revisa la configuración de correo de la plataforma y pulsa «Reenviar» en la lista de pendientes.`}
        </Alert>
      </div>
      <Button variant="ghost" onClick={onClose}>
        Cerrar
      </Button>
    </div>
  );
}

function InvitationRow({
  invitation,
  onResent,
  onRevoked,
}: {
  invitation: TeamInvitation;
  onResent: (issued: IssuedInvitation) => void;
  onRevoked: () => void;
}) {
  const resend = useAction(async () => {
    onResent(await api<IssuedInvitation>(`/team/invitations/${invitation.id}/resend`, { method: "POST" }));
  });
  const revoke = useAction(async () => {
    await api(`/team/invitations/${invitation.id}`, { method: "DELETE" });
    onRevoked();
  });
  const error = resend.error ?? revoke.error;

  return (
    <li className="py-3">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="min-w-0">
          <p className="truncate font-medium">{invitation.email}</p>
          <p className="text-sm text-muted">
            {ROLE_LABELS[invitation.role]}
            {invitation.invited_by && ` · invitó ${invitation.invited_by}`} ·{" "}
            {invitation.expired ? <Badge tone="danger">Vencida</Badge> : `vence el ${formatDay(invitation.expires_at)}`}
          </p>
        </div>
        {invitation.can_edit && (
          <div className="flex gap-2">
            <Button variant="secondary" loading={resend.pending} onClick={() => void resend.run()}>
              Reenviar
            </Button>
            <Button variant="danger" loading={revoke.pending} onClick={() => void revoke.run()}>
              Revocar
            </Button>
          </div>
        )}
      </div>
      {error && (
        <div className="mt-2">
          <PlanLimitAlert error={error} />
        </div>
      )}
    </li>
  );
}
