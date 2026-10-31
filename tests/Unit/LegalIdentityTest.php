<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\LegalIdentity;
use PHPUnit\Framework\TestCase;

/**
 * The one promise: a reader never sees a placeholder, braces, or a sentence
 * with a hole where a value should be.
 */
class LegalIdentityTest extends TestCase
{
    public function test_fills_tokens_with_escaped_values(): void
    {
        $html = LegalIdentity::render(
            '<p>Operated by {{legal_entity_name}}. Mail <a href="mailto:{{contact_email}}">{{contact_email}}</a>.</p>',
            ['legal_entity_name' => 'Ace & Co <Ltd>', 'contact_email' => 'hi@example.test'],
        );

        $this->assertSame(
            '<p>Operated by Ace &amp; Co &lt;Ltd&gt;. Mail <a href="mailto:hi@example.test">hi@example.test</a>.</p>',
            $html,
        );
    }

    public function test_an_empty_value_removes_only_its_sentence(): void
    {
        $html = LegalIdentity::render(
            '<p>These Terms govern the Site. The Site is operated by {{legal_entity_name}}. By using it you agree.</p>',
            ['legal_entity_name' => '  '],
        );

        $this->assertSame('<p>These Terms govern the Site. By using it you agree.</p>', $html);
    }

    public function test_a_block_left_without_text_is_removed(): void
    {
        $html = LegalIdentity::render(
            '<h2>Law</h2><p>Governed by {{governing_law}}.</p><p>Keep me.</p>',
            [],
        );

        $this->assertSame('<h2>Law</h2><p>Keep me.</p>', $html);
    }

    public function test_data_requires_removes_the_whole_element_and_is_stripped_when_satisfied(): void
    {
        $template = '<h2 data-requires="registered_address">Postal Address</h2>'
            . '<p data-requires="legal_entity_name registered_address">Run by {{legal_entity_name}}, {{registered_address}}.'
            . '<span data-requires="company_number"> No. {{company_number}}.</span></p>';

        $this->assertSame('', LegalIdentity::render($template, ['legal_entity_name' => 'Ace Ltd']));

        $this->assertSame(
            '<h2>Postal Address</h2><p>Run by Ace Ltd, 1 High St.</p>',
            LegalIdentity::render($template, ['legal_entity_name' => 'Ace Ltd', 'registered_address' => '1 High St']),
        );

        $this->assertSame(
            '<h2>Postal Address</h2><p>Run by Ace Ltd, 1 High St.<span> No. 123.</span></p>',
            LegalIdentity::render($template, ['legal_entity_name' => 'Ace Ltd', 'registered_address' => '1 High St', 'company_number' => '123']),
        );
    }

    public function test_unknown_tokens_never_reach_the_page(): void
    {
        $this->assertSame('<p>Fine.</p>', LegalIdentity::render('<p>Fine. Typo {{legal_entty}} here.</p>', []));
    }

    public function test_html_without_tokens_is_returned_untouched(): void
    {
        $html = "<p>Plain   copy.</p>\n<ul><li>x</li></ul>";

        $this->assertSame($html, LegalIdentity::render($html, []));
    }
}
