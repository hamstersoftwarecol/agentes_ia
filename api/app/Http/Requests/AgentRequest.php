<?php

namespace App\Http\Requests;

use App\Enums\AgentLanguage;
use App\Enums\AgentTone;
use App\Models\Agent;
use App\Services\Billing\BillingGate;
use App\Support\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Crear (POST) y editar (PATCH, parcial: permite activar/desactivar sin reenviar todo). */
class AgentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->isMethod('POST')
            ? $this->user()->can('create', Agent::class)
            : $this->user()->can('update', $this->route('agent'));
    }

    /** Hostname, IPv4 o localhost: sin esquema, ruta ni puerto (eso lo limpia prepareForValidation). */
    private const HOST = '/^(localhost|(\d{1,3}\.){3}\d{1,3}|([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63})$/';

    protected function prepareForValidation(): void
    {
        $domains = $this->input('allowed_domains');
        if (! is_array($domains)) {
            return;
        }

        $this->merge(['allowed_domains' => collect($domains)
            // Un texto que no queda en nada al limpiarlo («http://») se conserva para que falle la validación en vez de desaparecer.
            ->map(fn ($d) => is_string($d) ? ($this->normalizeDomain($d) ?: trim($d)) : $d)
            // Laravel ya convirtió los textos en blanco en null: también se descartan.
            ->filter(fn ($d) => $d !== '' && $d !== null)
            ->unique()->values()->all()]);
    }

    /** «https://www.MiTienda.com/ruta?x» → «mitienda.com» (el dominio sin www permite también www. y demás subdominios). */
    private function normalizeDomain(string $value): string
    {
        $host = strtolower(trim($value));
        $host = preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $host);
        $host = preg_split('#[/?\#]#', $host)[0];
        $host = preg_replace('/:\d+$/', '', $host);

        return preg_replace('/^(\*\.|www\.)/', '', $host);
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $domains = $this->input('allowed_domains');
            $organization = app(CurrentOrganization::class)->get();
            if (! is_array($domains) || $validator->errors()->has('allowed_domains.*') || ! $organization) {
                return;
            }

            $limit = app(BillingGate::class)->for($organization)->maxSites();
            if ($limit === null) {
                return;
            }

            // Sitios distintos en toda la empresa (los de los demás agentes + los de este).
            $others = Agent::query()
                ->when($this->route('agent'), fn ($q, $agent) => $q->where('id', '!=', $agent->id))
                ->get()->pluck('allowed_domains')->flatten();

            if ($others->merge($domains)->unique()->count() > $limit) {
                $validator->errors()->add('allowed_domains', "Tu plan permite {$limit} sitio".($limit === 1 ? '' : 's').' web distintos en total. Quita alguno o sube de plan.');
            }
        }];
    }

    public function rules(): array
    {
        $canCustomize = $this->canCustomizeWidget();

        $required = $this->isMethod('POST') ? ['required'] : ['sometimes', 'required'];

        return [
            'name' => [...$required, 'string', 'max:80'],
            'role' => ['nullable', 'string', 'max:120'],
            'goal' => ['nullable', 'string', 'max:1000'],
            'tone' => [...$required, Rule::enum(AgentTone::class)],
            'language' => [...$required, Rule::enum(AgentLanguage::class)],
            'instructions' => ['nullable', 'string', 'max:5000'],
            'welcome_message' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
            // La personalización del widget es una función de plan (Business en adelante).
            'widget_position' => ['sometimes', 'in:left,right', Rule::prohibitedIf(! $canCustomize)],
            'widget_color' => ['sometimes', 'regex:/^#[0-9a-fA-F]{6}$/', Rule::prohibitedIf(! $canCustomize)],
            'allowed_domains' => ['sometimes', 'array', 'max:10'],
            'allowed_domains.*' => ['string', 'regex:'.self::HOST],
            // exists() no pasa por el global scope: la empresa se filtra explícitamente.
            'knowledge_base_id' => [
                'nullable',
                Rule::exists('knowledge_bases', 'id')->where('organization_id', app(CurrentOrganization::class)->id()),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'widget_position.prohibited' => 'La personalización del widget está disponible desde el plan Business.',
            'widget_color.prohibited' => 'La personalización del widget está disponible desde el plan Business.',
            'allowed_domains.*.regex' => 'Escribe solo el dominio, por ejemplo: mitienda.com',
        ];
    }

    private function canCustomizeWidget(): bool
    {
        $organization = app(CurrentOrganization::class)->get();

        return $organization !== null && app(BillingGate::class)->for($organization)->hasFeature('widget_customization');
    }
}
