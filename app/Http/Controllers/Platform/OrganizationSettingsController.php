<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The organization's own modules, tax registration and operating settings.
 */
class OrganizationSettingsController extends Controller
{
    public function show(Request $request)
    {
        $tenant = $request->user()->tenant;
        abort_unless($tenant, 404);
        $this->authorize('view', $tenant);
        $plan = $tenant->billingSubscription()?->plan;

        return $this->respond([
            'modules' => $tenant->modules ?: Tenant::DEFAULT_MODULES[$tenant->type],
            'enabled_modules' => $tenant->enabledModules(),
            'available_modules' => $plan?->modules ?? Tenant::MODULES,
            'tax_regime' => $tenant->tax_regime,
            'vat_number' => $tenant->vat_number,
            'tin_number' => $tenant->tin_number,
            'settings' => array_merge(Tenant::DEFAULT_SETTINGS, $tenant->settings ?? []),
        ]);
    }

    public function update(Request $request)
    {
        $tenant = $request->user()->tenant;
        abort_unless($tenant, 404);
        $this->authorize('update', $tenant);

        $data = $request->validate([
            'modules' => ['sometimes', 'array'],
            'modules.*' => [Rule::in(Tenant::MODULES)],
            'tax_regime' => ['sometimes', Rule::in(['exempt', 'vat', 'tot'])],
            'vat_number' => ['nullable', 'string', 'max:20'],
            'settings' => ['sometimes', 'array'],
            'settings.payment_before_service' => ['boolean'],
            'settings.registration_fee_on_check_in' => ['boolean'],
            'settings.card_fee_before_consultation' => ['boolean'],
            'settings.pay_at_cashier' => ['boolean'],
            'settings.einvoice_enabled' => ['boolean'],
            'settings.four_eyes_lab_verification' => ['boolean'],
            'settings.dhis2_org_unit' => ['nullable', 'string', 'max:40'],
            'settings.dhis2_dataset' => ['nullable', 'string', 'max:40'],
            // Online appointments
            'settings.appointments_enabled' => ['boolean'],
            'settings.appointment_open' => ['date_format:H:i'],
            'settings.appointment_close' => ['date_format:H:i'],
            'settings.appointment_days' => ['array', 'min:1'],
            'settings.appointment_days.*' => ['integer', 'between:1,7'],
            'settings.appointment_slot_minutes' => ['integer', Rule::in([15, 20, 30, 45, 60])],
            'settings.appointments_per_slot' => ['integer', 'between:1,50'],
        ]);

        $planModules = app(\App\Services\Platform\PlanLimits::class)->modules($tenant);
        $outside = $planModules !== null ? array_diff($data['modules'] ?? [], $planModules) : [];
        if ($outside) {
            throw \App\Exceptions\BusinessRuleException::withErrors('modules', 'Your plan does not include: '.implode(', ', $outside).'. Upgrade your plan under Subscription to switch it on.');
        }

        if (isset($data['modules']) && $tenant->isPharmacy() && ! in_array('pharmacy', $data['modules'], true)) {
            $data['modules'][] = 'pharmacy'; // a pharmacy always keeps its pharmacy module
        }
        if (isset($data['settings'])) {
            $data['settings'] = array_merge($tenant->settings ?? [], $data['settings']);
            // Appointment hours are checked against the saved ones, since either may change alone
            $open = $data['settings']['appointment_open'] ?? Tenant::DEFAULT_SETTINGS['appointment_open'];
            $close = $data['settings']['appointment_close'] ?? Tenant::DEFAULT_SETTINGS['appointment_close'];
            if ($close <= $open) {
                throw \App\Exceptions\BusinessRuleException::withErrors('settings.appointment_close', 'Appointments must end after the first appointment time.');
            }
        }
        $tenant->update($data);

        return $this->show($request);
    }
}
