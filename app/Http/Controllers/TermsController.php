<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\BuildLegalIdentity;
use Illuminate\Support\Facades\Date;
use Inertia\Inertia;
use Inertia\Response;

final class TermsController extends Controller
{
    /**
     * Show the terms a pilot is agreeing to.
     *
     * Public and unauthenticated, which is the whole point: the Consumer
     * Rights Directive wants these terms readable before a contract is
     * concluded, not after signing in, and the register form links here from a
     * page nobody has an account on yet.
     *
     * The revision date is formatted here rather than in the browser. It is
     * the date a pilot would cite in a dispute about which version they
     * accepted, and `toLocaleDateString` would render it differently for a
     * pilot in Dublin, a pilot in Warsaw and the server that pre-rendered the
     * page for either of them.
     */
    public function __invoke(BuildLegalIdentity $identity): Response
    {
        $effective = Date::parse(config()->string('legal.effective.terms'));

        return Inertia::render('legal/terms', [
            'identity' => $identity->handle(),
            'updatedAt' => [
                'iso' => $effective->toDateString(),
                'label' => $effective->translatedFormat('j F Y'),
            ],
        ]);
    }
}
