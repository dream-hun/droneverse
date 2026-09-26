<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\CreateCourse;
use App\Actions\DeleteCourse;
use App\Actions\UpdateCourse;
use App\Enums\Plan;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveCourseRequest;
use App\Http\Resources\Admin\AdminChallengeResource;
use App\Http\Resources\Admin\AdminCourseResource;
use App\Models\Course;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

final class CourseController extends Controller
{
    /**
     * The whole catalogue in author order, drafts included.
     */
    public function index(): Response
    {
        $courses = $this->withCounts(Course::query())
            ->orderBy('order')
            ->orderBy('id')
            ->get();

        return Inertia::render('admin/courses/index', [
            'courses' => AdminCourseResource::collection($courses),
            'plans' => $this->plans(),
        ]);
    }

    public function store(SaveCourseRequest $request, CreateCourse $create): RedirectResponse
    {
        $course = $create->handle($request->course());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Course :title created.', ['title' => $course->title])]);

        return to_route('admin.courses.show', $course);
    }

    /**
     * A course and every mission in it, complete, for editing.
     *
     * Every mission is sent whole — code, world, grading rules — because the
     * edit form opens in place over this page. A course holds a handful of
     * missions, so that is a few kilobytes rather than a round trip per edit.
     */
    public function show(Course $course): Response
    {
        $course->loadCount($this->counts());

        $challenges = $course->challenges()
            ->withCount('progress')
            ->orderBy('id')
            ->get();

        return Inertia::render('admin/courses/show', [
            'course' => AdminCourseResource::one($course),
            'challenges' => AdminChallengeResource::collection($challenges),
            'plans' => $this->plans(),
        ]);
    }

    /**
     * A renamed course has moved: the page it was edited from is at its old
     * slug, so going back there would be a 404. The course's own page is
     * where the author is sent instead when that is where they were.
     */
    public function update(SaveCourseRequest $request, Course $course, UpdateCourse $update): RedirectResponse
    {
        $previous = url()->previous();
        $wasOnCoursePage = $previous === route('admin.courses.show', $course);

        $update->handle($course, $request->course());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Course updated.')]);

        return $wasOnCoursePage ? to_route('admin.courses.show', $course) : back();
    }

    /**
     * @throws Throwable
     */
    public function destroy(Course $course, DeleteCourse $delete): RedirectResponse
    {
        $delete->handle($course);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Course :title deleted.', ['title' => $course->title])]);

        return to_route('admin.courses.index');
    }

    /**
     * @param  Builder<Course>  $query
     * @return Builder<Course>
     */
    private function withCounts(Builder $query): Builder
    {
        return $query->withCount($this->counts());
    }

    /**
     * @return array<int|string, mixed>
     */
    private function counts(): array
    {
        return [
            'challenges',
            'challenges as published_challenges_count' => fn (Builder $challenges): Builder => $challenges->where('is_published', true),
            'quizzes',
        ];
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function plans(): array
    {
        return array_map(
            static fn (Plan $plan): array => ['value' => $plan->value, 'label' => $plan->label()],
            Plan::cases(),
        );
    }
}
