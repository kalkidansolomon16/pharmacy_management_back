<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * API access for other systems (EMRs, insurers, DHIS2 jobs): read-only FHIR tokens.
 */
class IntegrationController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('integrations.manage');

        return $this->respond($request->user()->tokens()->get()
            ->filter(fn ($t) => ! in_array('*', (array) $t->abilities, true))
            ->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'abilities' => $t->abilities, 'last_used_at' => $t->last_used_at, 'created_at' => $t->created_at])
            ->values());
    }

    public function store(Request $request, ActivityLogger $logger)
    {
        Gate::authorize('integrations.manage');
        $data = $request->validate(['name' => ['required', 'string', 'max:60']]);
        $token = $request->user()->createToken('Integration: '.$data['name'], ['fhir:read']);
        $logger->log('integration_token_created', $request->user(), ['name' => $data['name']]);

        return $this->respond([
            'id' => $token->accessToken->id,
            'token' => $token->plainTextToken,
            'base_url' => url('/api/fhir'),
        ], 'Copy this token now - it will not be shown again.', 201);
    }

    public function destroy(Request $request, int $id)
    {
        Gate::authorize('integrations.manage');
        $request->user()->tokens()->whereKey($id)->delete();

        return $this->respond(null, 'Token revoked.');
    }
}
