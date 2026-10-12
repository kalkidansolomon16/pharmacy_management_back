<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Services\Platform\BackupService;
use Illuminate\Support\Facades\Gate;

class BackupController extends Controller
{
    public function __construct(private BackupService $backups) {}

    public function index()
    {
        Gate::authorize('platform.backups');

        return $this->respond($this->backups->list());
    }

    public function store()
    {
        Gate::authorize('platform.backups');

        return $this->respond($this->backups->run(), 'Encrypted backup created.', 201);
    }

    public function download(string $file)
    {
        Gate::authorize('platform.backups');

        return response()->download($this->backups->path($file));
    }
}
