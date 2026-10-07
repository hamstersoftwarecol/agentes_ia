<?php

namespace App\Http\Requests;

use App\Models\Organization;
use App\Support\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBusinessHoursRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = app(CurrentOrganization::class)->get();

        return $organization !== null && $this->user()->can('update', $organization);
    }

    public function rules(): array
    {
        $rules = ['business_hours' => ['required', 'array:'.implode(',', Organization::DAYS)]];

        foreach (Organization::DAYS as $day) {
            $rules["business_hours.$day"] = ['required', 'array:enabled,open,close'];
            $rules["business_hours.$day.enabled"] = ['required', 'boolean'];
            $rules["business_hours.$day.open"] = ['required', 'date_format:H:i'];
            $rules["business_hours.$day.close"] = ['required', 'date_format:H:i', "after:business_hours.$day.open"];
        }

        return $rules;
    }
}
