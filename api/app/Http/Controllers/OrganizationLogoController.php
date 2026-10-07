<?php

namespace App\Http\Controllers;

use App\Http\Resources\OrganizationResource;
use App\Support\CurrentOrganization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class OrganizationLogoController extends Controller
{
    public function __construct(private readonly CurrentOrganization $current) {}

    public function store(Request $request): OrganizationResource
    {
        $organization = $this->current->get();
        $this->authorize('update', $organization);

        // SVG queda fuera a propósito: puede contener scripts.
        $request->validate([
            'logo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $path = $request->file('logo')->store("organizations/{$organization->id}", 'public');

        if ($organization->logo_path) {
            Storage::disk('public')->delete($organization->logo_path);
        }

        $organization->forceFill(['logo_path' => $path])->save();

        return new OrganizationResource($organization);
    }

    public function destroy(): OrganizationResource
    {
        $organization = $this->current->get();
        $this->authorize('update', $organization);

        if ($organization->logo_path) {
            Storage::disk('public')->delete($organization->logo_path);
            $organization->forceFill(['logo_path' => null])->save();
        }

        return new OrganizationResource($organization);
    }
}
