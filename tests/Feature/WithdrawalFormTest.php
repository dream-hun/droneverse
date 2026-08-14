<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\BuildWithdrawalForm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WithdrawalFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_can_download_the_model_withdrawal_form(): void
    {
        $response = $this->get(route('withdrawal-form'));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $response->assertHeader(
            'content-disposition',
            'attachment; filename="droneverse-model-withdrawal-form.pdf"',
        );
    }

    public function test_the_download_is_a_pdf(): void
    {
        $pdf = $this->get(route('withdrawal-form'))->getContent();

        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringEndsWith("%%EOF\n", $pdf);
    }

    /**
     * The cross-reference table is the part of a hand-built PDF that fails
     * quietly: a reader that cannot trust the offsets shows a blank page or an
     * error rather than saying which byte was wrong. So every offset is
     * followed back into the file and checked to land on the object it claims.
     */
    public function test_every_cross_reference_offset_lands_on_its_object(): void
    {
        $pdf = $this->pdf();

        $this->assertSame(1, preg_match('/startxref\n(\d+)\n%%EOF/', $pdf, $start));

        $table = mb_substr($pdf, (int) $start[1], null, '8bit');

        $this->assertSame(1, preg_match('/^xref\n0 (\d+)\n/', $table, $header));

        $size = (int) $header[1];
        $entries = preg_match_all('/^(\d{10}) \d{5} n $/m', $table, $offsets);

        // Every object but the mandatory free entry at index zero.
        $this->assertSame($size - 1, $entries);

        foreach ($offsets[1] as $index => $offset) {
            $this->assertStringStartsWith(
                sprintf('%d 0 obj', $index + 1),
                mb_substr($pdf, (int) $offset, 32, '8bit'),
                sprintf('Object %d is not where the xref says it is.', $index + 1),
            );
        }
    }

    public function test_the_form_names_the_configured_trader(): void
    {
        config([
            'legal.entity.name' => 'DroneVerse Ltd',
            'legal.entity.address' => '1 Runway Road, Kigali',
            'legal.entity.country' => 'Rwanda',
            'legal.contact.support' => 'support@droneverse.test',
        ]);

        $pdf = $this->pdf();

        $this->assertStringContainsString('DroneVerse Ltd', $pdf);
        $this->assertStringContainsString('1 Runway Road, Kigali', $pdf);
        $this->assertStringContainsString('Rwanda', $pdf);
        $this->assertStringContainsString('support@droneverse.test', $pdf);
    }

    public function test_the_form_carries_the_statutory_wording(): void
    {
        $pdf = $this->pdf();

        $this->assertStringContainsString('Model withdrawal form', $pdf);
        $this->assertStringContainsString('hereby give notice', $pdf);
        $this->assertStringContainsString('Delete as appropriate', $pdf);
    }

    /**
     * The page tells people they do not have to use this, so the form itself
     * has to say the same thing — a document that reads as the official route
     * out would undo the point of offering it.
     */
    public function test_the_form_says_it_is_optional(): void
    {
        $this->assertStringContainsString(
            'You do not have to use this form',
            $this->pdf(),
        );
    }

    /**
     * An unconfigured deployment must not invent a trader here any more than
     * it does on the pages themselves.
     */
    public function test_an_unconfigured_contact_address_is_marked_not_invented(): void
    {
        config([
            'legal.contact.support' => null,
            'legal.contact.privacy' => null,
            'plans.sales_email' => null,
        ]);

        $this->assertStringContainsString(
            'contact address not configured',
            $this->pdf(),
        );
    }

    /**
     * Parentheses and backslashes end a PDF string literal, so a trader whose
     * registered name contains one would otherwise produce a file that no
     * reader can open.
     */
    public function test_a_trader_name_with_pdf_syntax_in_it_is_escaped(): void
    {
        config(['legal.entity.name' => 'DroneVerse (EU) \\ Partners']);

        $pdf = $this->pdf();

        $this->assertStringContainsString('DroneVerse \\(EU\\) \\\\ Partners', $pdf);
        $this->assertStringNotContainsString('(DroneVerse (EU)', $pdf);
    }

    private function pdf(): string
    {
        return app(BuildWithdrawalForm::class)->handle();
    }
}
