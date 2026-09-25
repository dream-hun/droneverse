<?php

declare(strict_types=1);

use App\Actions\BuildWithdrawalForm;

test('guests can download the model withdrawal form', function (): void {
    $response = $this->get(route('withdrawal-form'));

    $response->assertOk();
    $response->assertHeader('content-type', 'application/pdf');
    $response->assertHeader(
        'content-disposition',
        'attachment; filename="droneverse-model-withdrawal-form.pdf"',
    );
});

test('the download is a pdf', function (): void {
    $pdf = $this->get(route('withdrawal-form'))->getContent();

    expect($pdf)->toStartWith('%PDF-1.4');
    expect($pdf)->toEndWith("%%EOF\n");
});

/**
 * The cross-reference table is the part of a hand-built PDF that fails
 * quietly: a reader that cannot trust the offsets shows a blank page or an
 * error rather than saying which byte was wrong. So every offset is
 * followed back into the file and checked to land on the object it claims.
 */
test('every cross reference offset lands on its object', function (): void {
    $pdf = pdf();

    expect(preg_match('/startxref\n(\d+)\n%%EOF/', $pdf, $start))->toBe(1);

    $table = mb_substr($pdf, (int) ($start[1] ?? 0), null, '8bit');

    expect(preg_match('/^xref\n0 (\d+)\n/', $table, $header))->toBe(1);

    $size = (int) ($header[1] ?? 0);
    $entries = preg_match_all('/^(\d{10}) \d{5} n $/m', $table, $offsets);

    // Every object but the mandatory free entry at index zero.
    expect($entries)->toBe($size - 1);

    foreach ($offsets[1] as $index => $offset) {
        expect(mb_substr($pdf, (int) $offset, 32, '8bit'))
            ->toStartWith(sprintf('%d 0 obj', $index + 1), sprintf('Object %d is not where the xref says it is.', $index + 1));
    }
});

test('the form names the configured trader', function (): void {
    config([
        'legal.entity.name' => 'DroneVerse Ltd',
        'legal.entity.address' => '1 Runway Road, Kigali',
        'legal.entity.country' => 'Rwanda',
        'legal.contact.support' => 'support@droneverse.test',
    ]);

    $pdf = pdf();

    expect($pdf)->toContain('DroneVerse Ltd');
    expect($pdf)->toContain('1 Runway Road, Kigali');
    expect($pdf)->toContain('Rwanda');
    expect($pdf)->toContain('support@droneverse.test');
});

test('the form carries the statutory wording', function (): void {
    $pdf = pdf();

    expect($pdf)->toContain('Model withdrawal form');
    expect($pdf)->toContain('hereby give notice');
    expect($pdf)->toContain('Delete as appropriate');
});

/**
 * The page tells people they do not have to use this, so the form itself
 * has to say the same thing — a document that reads as the official route
 * out would undo the point of offering it.
 */
test('the form says it is optional', function (): void {
    expect(pdf())->toContain('You do not have to use this form');
});

/**
 * An unconfigured deployment must not invent a trader here any more than
 * it does on the pages themselves.
 */
test('an unconfigured contact address is marked not invented', function (): void {
    config([
        'legal.contact.support' => null,
        'legal.contact.privacy' => null,
        'plans.sales_email' => null,
    ]);

    expect(pdf())->toContain('contact address not configured');
});

/**
 * Parentheses and backslashes end a PDF string literal, so a trader whose
 * registered name contains one would otherwise produce a file that no
 * reader can open.
 */
test('a trader name with pdf syntax in it is escaped', function (): void {
    config(['legal.entity.name' => 'DroneVerse (EU) \\ Partners']);

    $pdf = pdf();

    expect($pdf)->toContain('DroneVerse \\(EU\\) \\\\ Partners');
    expect($pdf)->not->toContain('(DroneVerse (EU)');
});

function pdf(): string
{
    return resolve(BuildWithdrawalForm::class)->handle();
}
