<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\StoreDronePhoto;
use App\Http\Requests\StoreDronePhotoRequest;
use App\Models\Challenge;
use App\Models\Course;
use App\Models\DronePhoto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

final class DronePhotoController extends Controller
{
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
            ->paginate(24);

        return Inertia::render('photos/index', [
            'photos' => $photos->getCollection()->map(fn (DronePhoto $photo): array => [
                'id' => $photo->id,
                'url' => $photo->url(),
                'label' => $photo->label,
                'challengeTitle' => $photo->challenge?->title,
                'courseSlug' => $photo->challenge?->course?->slug,
                'challengeSlug' => $photo->challenge?->slug,
                'position' => $photo->position,
                'takenAt' => $photo->created_at?->toIso8601String() ?? '',
            ])->values(),
            'page' => $photos->currentPage(),
            'lastPage' => $photos->lastPage(),
            'total' => $photos->total(),
        ]);
    }

    /**
     * Save a photo captured by the simulator's drone camera.
     */
    public function store(
        StoreDronePhotoRequest $request,
        Course $course,
        Challenge $challenge,
        StoreDronePhoto $storePhoto,
    ): JsonResponse {
        abort_unless($course->is_published && $challenge->is_published, 404);
        abort_unless($challenge->course_id === $course->id, 404);

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
    public function destroy(Request $request, DronePhoto $photo): RedirectResponse
    {
        abort_unless($photo->user_id === $request->user()->id, 403);

        Storage::disk('public')->delete($photo->path);
        $photo->delete();

        return back();
    }
}
