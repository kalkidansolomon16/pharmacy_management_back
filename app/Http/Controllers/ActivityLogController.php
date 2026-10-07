<?php

namespace App\Http\Controllers;

use App\Http\Resources\ActivityLogResource;
use App\Models\ActivityLog;
use Illuminate\Http\Request;

/**
 * Audit trail. The tenant global scope limits tenant admins to their own organization's log.
 */
class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', ActivityLog::class);

        $logs = ActivityLog::query()
            ->with('user:id,name', 'tenant:id,name')
            ->when($request->query('action'), fn ($q, $action) => $q->where('action', $action))
            ->when($request->query('entity_type'), fn ($q, $type) => $q->where('entity_type', $type))
            ->when($request->query('user_id'), fn ($q, $id) => $q->where('user_id', $id))
            ->when($request->query('from'), fn ($q, $from) => $q->whereDate('created_at', '>=', $from))
            ->when($request->query('to'), fn ($q, $to) => $q->whereDate('created_at', '<=', $to))
            ->when($request->query('search'), fn ($q, $term) => $q->where('description', 'like', "%{$term}%"))
            ->latest('id')
            ->paginate($this->perPage(25));

        return ActivityLogResource::collection($logs);
    }
}
