<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\BuildSystemReport;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

final class SystemController extends Controller
{
    /**
     * Live health checks, the queue, and how big the data has grown.
     *
     * The log viewer is linked rather than embedded, and only for people its
     * own gate admits. `view_system` does not imply it: logs carry payloads
     * and addresses, and config/logging.php keeps that list deliberately
     * separate and opt-in.
     */
    public function __invoke(BuildSystemReport $report): Response
    {
        return Inertia::render('admin/system', [
            'report' => $report->handle(),
            'logViewerUrl' => Route::has('log-viewer.index') && Gate::allows('viewLogViewer')
                ? route('log-viewer.index')
                : null,
        ]);
    }
}
