<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\BuildWithdrawalForm;
use Symfony\Component\HttpFoundation\Response;

final class WithdrawalFormController extends Controller
{
    /**
     * Hand over the model withdrawal form.
     *
     * Public and unauthenticated like the terms it hangs off: someone deciding
     * whether to subscribe is entitled to see how they would get out of it,
     * and a consumer who has already cancelled their account still has 14 days
     * in which they might want this.
     *
     * Built per request rather than written to disk once. It is a few hundred
     * bytes to assemble, it has to follow the trader identity in this
     * environment's configuration, and a cached copy is one deploy away from
     * naming the wrong company.
     */
    public function __invoke(BuildWithdrawalForm $form): Response
    {
        return response($form->handle(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $form->filename()),
        ]);
    }
}
