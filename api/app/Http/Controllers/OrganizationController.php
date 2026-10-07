<?php

namespace App\Http\Controllers;

use App\Enums\OrganizationRole;
use App\Http\Requests\StoreOrganizationRequest;
use App\Http\Requests\UpdateBusinessHoursRequest;
use App\Http\Requests\UpdateOrganizationRequest;
use App\Http\Resources\OrganizationResource;
use App\Models\Organization;
use App\Services\Billing\SubscriptionService;
use App\Support\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class OrganizationController extends Controller
{
    public function __construct(private readonly CurrentOrganization $current, private readonly SubscriptionService $subscriptions) {}

    public function store(StoreOrganizationRequest $request): JsonResponse
    {
        $user = $request->user();

        // Una empresa por cuenta en el MVP (múltiples empresas: US-014, post-MVP).
        if ($user->organizations()->exists()) {
            return response()->json(['message' => 'Tu cuenta ya tiene una empresa.'], 409);
        }

        $organization = DB::transaction(function () use ($request, $user) {
            $organization = new Organization([
                'name' => $request->validated('name'),
                'timezone' => $request->validated('timezone') ?? 'America/Bogota',
                'business_hours' => Organization::defaultBusinessHours(),
            ]);
            // El slug no es asignable en masa: lo genera el servidor.
            $organization->slug = Organization::uniqueSlug($organization->name);
            $organization->save();

            $organization->users()->attach($user, ['role' => OrganizationRole::Owner->value]);
            $user->forceFill(['current_organization_id' => $organization->id])->save();
            $this->subscriptions->startTrial($organization);

            return $organization;
        });

        return (new OrganizationResource($organization))->response()->setStatusCode(201);
    }

    public function show(): OrganizationResource
    {
        return new OrganizationResource($this->current->get());
    }

    public function update(UpdateOrganizationRequest $request): OrganizationResource
    {
        $organization = $this->current->get();
        $organization->update($request->validated());

        return new OrganizationResource($organization);
    }

    public function updateBusinessHours(UpdateBusinessHoursRequest $request): OrganizationResource
    {
        $organization = $this->current->get();
        $organization->update(['business_hours' => $request->validated('business_hours')]);

        return new OrganizationResource($organization);
    }
}
