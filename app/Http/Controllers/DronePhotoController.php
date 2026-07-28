<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\StoreDronePhoto;
use App\Http\Requests\StoreDronePhotoRequest;
use App\Http\Resources\DronePhotoResource;
use App\Models\Challenge;
use App\Models\Course;
use App\Models\DronePhoto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

final class DronePhotoController extends Controller
{
    /** Photos shown per page of the log. */
    private const int PER_PAGE = 24;

    /**
     * Display the pilot's photo log.
     */
    public function index(Request $request): Response
    {
        $photos = $request->user()
            ->dronePhotos()
            ->with('challenge.course')
            ->latest()
            ->latest('id')
            ->paginate(self::PER_PAGE);

        return Inertia::render('photos/index', [
            'photos' => DronePhotoResource::collection($photos->getCollection()),
            'page' => $photos->currentPage(),
            'lastPage' => $photos->lastPage(),
            'total' => $photos->total(),
        ]);
    }

    /**
     * Save a photo captured by the simulator's drone camera.
     *
     * The second write path into a mission, so it carries the same plan check
     * as an attempt: a pilot who cannot fly a mission cannot fill their photo
     * log from it either.
     */
    public function store(
        StoreDronePhotoRequest $request,
        Course $course,
        Challenge $challenge,
        StoreDronePhoto $storePhoto,
    ): JsonResponse {
        abort_unless($challenge->isPlayableIn($course), 404);
        abort_unless($challenge->isUnlockedFor($request->user(), $course), 403);

        $photo = $storePhoto->handle($request->user(), $challenge, $request->photo());

        return response()->json([
            'id' => $photo->id,
            'url' => $photo->url(),
            'label' => $photo->label,
        ], 201);
    }

    /**
     * Delete a photo (and its file) from the pilot's log.
     */
    public function destroy(DronePhoto $photo): RedirectResponse
    {
        Gate::authorize('delete', $photo);

        $path = $photo->path;

        // The row is the record of truth, so it goes first: a storage failure
        // then leaves a stray file to sweep up rather than a log entry
        // pointing at an image that is already gone.
        $photo->delete();
        Storage::disk('public')->delete($path);

        return back();
    }
}
