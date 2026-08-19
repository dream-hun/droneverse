<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\BuildLegalIdentity;
use Illuminate\Support\Facades\Date;
use Inertia\Inertia;
use Inertia\Response;

final class PrivacyController extends Controller
{
    /**
     * Show what we collect and why.
     *
     * Public for the reason the terms are: GDPR Article 13 makes these
     * disclosures due "at the time when personal data are obtained", and the
     * first personal data this application obtains is the name and email typed
     * into a register form by someone who has no account yet.
     *
     * Mirrors {@see TermsController} deliberately — same props, same shape,
     * same server-side date — so the two documents cannot drift into being
     * built differently.
     */
    public function __invoke(BuildLegalIdentity $identity): Response
    {
        $effective = Date::parse((string) config('legal.effective.privacy'));

        return Inertia::render('legal/privacy', [
            'identity' => $identity->handle(),
            'updatedAt' => [
                'iso' => $effective->toDateString(),
                'label' => $effective->translatedFormat('j F Y'),
            ],
        ]);
    }
}
