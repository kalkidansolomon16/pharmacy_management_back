<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Signed-in devices (each login is a token): see them and sign out a lost phone remotely.
 */
class SessionController extends Controller
{
    public function index(Request $request)
    {
        $current = $request->user()->currentAccessToken();

        return $this->respond($request->user()->tokens()->latest('last_used_at')->get()
            ->filter(fn ($t) => in_array('*', (array) $t->abilities, true))
            ->map(fn ($t) => ['id' => $t->id, 'device' => $t->name, 'last_used_at' => $t->last_used_at, 'created_at' => $t->created_at, 'current' => $current && $t->id === $current->id])
            ->values());
    }

    public function destroy(Request $request, int $id)
    {
        $request->user()->tokens()->whereKey($id)->delete();

        return $this->respond(null, 'Device signed out.');
    }
}
