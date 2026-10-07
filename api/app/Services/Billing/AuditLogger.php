<?php

namespace App\Services\Billing;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Quién hizo qué sobre cuentas y facturación: acciones del superadmin y eventos de cobro. */
class AuditLogger
{
    public function log(string $action, ?Organization $organization = null, ?User $actor = null, ?Model $subject = null, array $metadata = []): AuditLog
    {
        return AuditLog::create([
            'organization_id' => $organization?->id,
            'user_id' => $actor?->id,
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'metadata' => $metadata ?: null,
            'ip' => app()->runningInConsole() ? null : request()->ip(),
        ]);
    }
}
