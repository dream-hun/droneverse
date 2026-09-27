<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Challenge;
use App\Models\Course;
use App\Models\Quiz;
use App\Models\User;

abstract class Controller
{
    /**
     * Turn away a viewer who cannot reach this mission or quiz.
     *
     * Content that is not live in this course is a 404; content the viewer's
     * plan does not reach is a 403, checked second so a locked page never
     * confirms that an unpublished one exists. Every endpoint under a mission
     * or quiz calls this, the writes included: nothing stops a client posting
     * straight at one, and locked content that still accepts writes is not
     * locked — it just has no link.
     */
    protected function ensureReachable(Challenge|Quiz $content, Course $course, ?User $user): void
    {
        abort_unless($content->isAvailableIn($course), 404);
        abort_unless($content->isUnlockedFor($user, $course), 403);
    }
}
