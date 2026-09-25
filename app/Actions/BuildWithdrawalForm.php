<?php

declare(strict_types=1);

namespace App\Actions;

/**
 * The Annex I(B) model withdrawal form, as a one-page PDF.
 *
 * Article 6(1)(h) of the Consumer Rights Directive expects a trader to make
 * this form available even though no consumer is ever obliged to use one, and
 * the terms page deliberately asks people to just write to a human instead. So
 * it lives here, one download away, rather than as a set of blanks on the page
 * — available to whoever wants it, in the way of nobody who does not.
 *
 * It is drawn rather than rendered: a single page of fixed text in a base-14
 * font needs no HTML engine, no font embedding and no dependency. The whole
 * document is a few hundred bytes of PDF 1.4, written uncompressed so that the
 * text stays greppable — which is also what lets a test assert that the trader
 * really is named in it.
 *
 * The trader's identity comes from {@see BuildLegalIdentity}, so this form and
 * the two legal pages can never name different companies, and an unconfigured
 * deployment produces a form carrying the same visible gap the pages do rather
 * than a plausible invention.
 */
final readonly class BuildWithdrawalForm
{
    private const string FILENAME = 'droneverse-model-withdrawal-form.pdf';

    /** A4, in PostScript points. */
    private const float PAGE_WIDTH = 595.28;

    private const float PAGE_HEIGHT = 841.89;

    private const float MARGIN = 56.0;

    private const float BODY_SIZE = 11.0;

    private const float LEADING = 16.0;

    /**
     * Characters per line before wrapping.
     *
     * Helvetica at 11pt averages a shade over 5pt per character, and the text
     * column is 483pt wide. Counting characters rather than measuring them
     * costs a ragged right margin and saves carrying the base-14 metrics
     * tables around; for a form that is mostly short lines and blanks, that is
     * the right trade.
     */
    private const int WRAP = 84;

    public function __construct(private BuildLegalIdentity $identity)
    {
        //
    }

    public function filename(): string
    {
        return self::FILENAME;
    }

    /**
     * The complete PDF, ready to be written to a response.
     */
    public function handle(): string
    {
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %s %s] '.
                '/Resources << /Font << /F1 5 0 R /F2 6 0 R >> >> /Contents 4 0 R >>',
                self::PAGE_WIDTH,
                self::PAGE_HEIGHT,
            ),
            $this->stream($this->content()),
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
            sprintf(
                '<< /Title (%s) /Producer (%s) >>',
                $this->escape('Model withdrawal form'),
                $this->escape(config()->string('app.name')),
            ),
        ];

        return $this->assemble($objects);
    }

    /**
     * The page content stream: one text object per line.
     *
     * Positioned absolutely rather than with a text matrix carried between
     * lines, because blank lines and changes of font size both move the cursor
     * and tracking that through `TL`/`T*` is more state than a static document
     * earns.
     */
    private function content(): string
    {
        $y = self::PAGE_HEIGHT - self::MARGIN;
        $stream = '';

        foreach ($this->lines() as $line) {
            if ($line['text'] === '') {
                $y -= self::LEADING * 0.6;

                continue;
            }

            $size = $line['size'];

            $stream .= sprintf(
                "BT\n/%s %s Tf\n1 0 0 1 %s %s Tm\n(%s) Tj\nET\n",
                $line['bold'] ? 'F2' : 'F1',
                $size,
                self::MARGIN,
                round($y, 2),
                $this->escape($line['text']),
            );

            $y -= $size >= 14.0 ? self::LEADING * 1.5 : self::LEADING;
        }

        return $stream;
    }

    /**
     * The document, line by line.
     *
     * The wording follows Annex I(B) of Directive 2011/83/EU closely — this is
     * the one place in the application where matching the statutory text
     * matters more than sounding like us — with the service named and the
     * covering note at the top saying, in our own words, that nobody has to
     * use it.
     *
     * @return list<array{text: string, bold: bool, size: float}>
     */
    private function lines(): array
    {
        $identity = $this->identity->handle();
        $email = $identity['supportEmail'] ?? '[contact address not configured]';

        $lines = [
            ['text' => 'Model withdrawal form', 'bold' => true, 'size' => 16.0],
            [
                'text' => 'Complete and return this form only if you wish to withdraw from the contract.',
                'bold' => false,
                'size' => self::BODY_SIZE,
            ],
            ['text' => '', 'bold' => false, 'size' => self::BODY_SIZE],
            [
                'text' => 'You do not have to use this form. An email saying you are withdrawing is enough, '.
                    'in your own words, and a person will read it and deal with it.',
                'bold' => false,
                'size' => self::BODY_SIZE,
            ],
            ['text' => '', 'bold' => false, 'size' => self::BODY_SIZE],
            ['text' => 'To:', 'bold' => true, 'size' => self::BODY_SIZE],
            ['text' => $identity['name'], 'bold' => false, 'size' => self::BODY_SIZE],
        ];

        foreach ([$identity['address'], $identity['country']] as $part) {
            if ($part !== null) {
                $lines[] = ['text' => $part, 'bold' => false, 'size' => self::BODY_SIZE];
            }
        }

        $lines[] = ['text' => $email, 'bold' => false, 'size' => self::BODY_SIZE];
        $lines[] = ['text' => '', 'bold' => false, 'size' => self::BODY_SIZE];

        foreach ([
            'I/We (*) hereby give notice that I/We (*) withdraw from my/our (*) contract for the '.
                'provision of the following service: DroneVerse subscription.',
            '',
            'Ordered on (*)/received on (*): ______________________________',
            '',
            'Name of consumer(s): ______________________________',
            '',
            'Address of consumer(s): ______________________________',
            '',
            '______________________________',
            '',
            'Signature of consumer(s) (only if this form is notified on paper):',
            '',
            '______________________________',
            '',
            'Date: ______________________________',
            '',
            '(*) Delete as appropriate.',
        ] as $text) {
            $lines[] = ['text' => $text, 'bold' => false, 'size' => self::BODY_SIZE];
        }

        return $this->wrap($lines);
    }

    /**
     * Break lines that would run past the right margin.
     *
     * @param  list<array{text: string, bold: bool, size: float}>  $lines
     * @return list<array{text: string, bold: bool, size: float}>
     */
    private function wrap(array $lines): array
    {
        $wrapped = [];

        foreach ($lines as $line) {
            foreach (explode("\n", wordwrap($line['text'], self::WRAP, "\n", true)) as $part) {
                $wrapped[] = [...$line, 'text' => $part];
            }
        }

        return $wrapped;
    }

    /**
     * A string as PDF text: re-encoded to the font's character set, with the
     * three characters that would otherwise end the string escaped.
     *
     * WinAnsi because that is what the font resources above declare. A
     * character the encoding cannot represent becomes a `?` rather than
     * corrupting the stream — worth knowing if a trader's registered name is
     * ever written in a script Latin-1 does not cover, in which case this form
     * needs a real font embedded and not a wider escape function.
     */
    private function escape(string $text): string
    {
        $encoded = mb_convert_encoding($text, 'Windows-1252', 'UTF-8');

        return str_replace(
            ['\\', '(', ')', "\r", "\n"],
            ['\\\\', '\\(', '\\)', '', ''],
            $encoded,
        );
    }

    private function stream(string $content): string
    {
        return sprintf(
            "<< /Length %d >>\nstream\n%s\nendstream",
            mb_strlen($content, '8bit'),
            $content,
        );
    }

    /**
     * Wrap the objects in a file: header, bodies, cross-reference table,
     * trailer.
     *
     * The xref holds the byte offset of every object, so it is built while the
     * body is concatenated rather than computed afterwards — a table that
     * disagrees with the file by even one byte is how a PDF opens as a blank
     * page in one reader and an error in another.
     *
     * @param  list<string>  $objects
     */
    private function assemble(array $objects): string
    {
        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $index => $object) {
            $offsets[] = mb_strlen($pdf, '8bit');
            $pdf .= sprintf("%d 0 obj\n%s\nendobj\n", $index + 1, $object);
        }

        $startxref = mb_strlen($pdf, '8bit');
        $count = count($objects) + 1;

        $pdf .= sprintf("xref\n0 %d\n0000000000 65535 f \n", $count);

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.sprintf(
            "trailer\n<< /Size %d /Root 1 0 R /Info %d 0 R >>\nstartxref\n%d\n%%%%EOF\n",
            $count,
            count($objects),
            $startxref,
        );
    }
}
