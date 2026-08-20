<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\BuildDroneManual;
use Inertia\Inertia;
use Inertia\Response;

final class DocsController extends Controller
{
    /**
     * The public reference manual.
     *
     * Open to everyone and complete for everyone: every command the simulator
     * answers, every worked example written for any course, and the rules a
     * run is scored by. A course's guide narrows all of that to what one
     * course needs — see {@see CourseDocsController} — and this is what it
     * narrows down from.
     *
     * Public for the same reason the guides are, only more so. This is the
     * page that answers "what is this thing actually like to write" before
     * anyone has typed an email address, and a reference behind a login
     * cannot answer it. Nothing on it is gated content: the examples are
     * authored for the documentation rather than lifted from the missions,
     * and CourseDocsTest holds them to that.
     *
     * Nothing here defers. The page is one document assembled from config and
     * a two-column pluck over the catalog, so there is no query worth a second
     * round trip — and a reference that arrives in pieces is a reference
     * somebody scrolls past the top of.
     */
    public function __invoke(BuildDroneManual $manual): Response
    {
        return Inertia::render('docs', [
            'manual' => $manual->handle(),
        ]);
    }
}
