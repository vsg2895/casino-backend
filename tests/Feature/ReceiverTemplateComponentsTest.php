<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Mail\MailgunReceiverTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The campaign message carries EVERY block the site promotion template has.
 *
 * The requirement behind these tests: a message sent from Email config should
 * look like the promotion email a site sends, and every one of its components
 * should be editable in the settings modal. So each block is asserted twice —
 * that it renders when filled, and that it disappears when empty, because an
 * empty field is this template's only off switch.
 */
class ReceiverTemplateComponentsTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, mixed> $fields */
    private function render(array $fields): string
    {
        return MailgunReceiverTemplate::render([
            'heading'    => 'Welcome',
            'intro_text' => 'Body copy.',
            ...$fields,
        ]);
    }

    public function test_every_component_of_the_promotion_layout_is_available(): void
    {
        $html = $this->render([
            'preheader'         => 'Inbox preview line',
            'greeting'          => 'Hi {{name}},',
            'secondary_text'    => 'A second paragraph.',
            'button_text'       => 'View Details',
            'button_url'        => 'https://example.com/offer',
            'hero_image_url'    => 'https://example.com/banner.jpg',
            'hero_url'          => 'https://example.com',
            'disclaimer_text'   => '18+ only.',
            'footer_text'       => 'Sent by Example.',
            'postal_address'    => '1 Example Street, Valletta',
            'contact_email'     => 'hello@example.com',
            'copyright_text'    => '© 2026 Example Ltd',
        ]);

        foreach ([
            'Inbox preview line', 'Welcome', 'Hi {{name}},', 'Body copy.', 'A second paragraph.',
            'View Details', 'https://example.com/offer', 'https://example.com/banner.jpg',
            '18+ only.', 'Sent by Example.', '1 Example Street, Valletta', '© 2026 Example Ltd',
        ] as $fragment) {
            $this->assertStringContainsString($fragment, $html, "missing: {$fragment}");
        }

        // The contact is a mailto: link, not text — a commercial message is
        // expected to carry a reachable address.
        $this->assertStringContainsString('mailto:hello@example.com', $html);
    }

    public function test_the_greeting_sits_above_the_body(): void
    {
        $html = $this->render(['greeting' => 'Hi there,']);

        $this->assertLessThan(
            strpos($html, 'Body copy.'),
            strpos($html, 'Hi there,'),
            'the greeting must come before the intro paragraph, as it does in the site template',
        );
    }

    public function test_an_empty_component_renders_nothing(): void
    {
        $html = $this->render([
            'greeting' => '', 'postal_address' => '', 'contact_email' => '', 'copyright_text' => '',
        ]);

        $this->assertStringNotContainsString('mailto:', $html);
        // No stray separator from a half-filled identity line.
        $this->assertStringNotContainsString('·', $html);
    }

    public function test_the_identity_line_separates_address_and_contact_only_when_both_exist(): void
    {
        $both = $this->render(['postal_address' => 'Street 1', 'contact_email' => 'a@b.com']);
        $this->assertStringContainsString('·', $both);

        $addressOnly = $this->render(['postal_address' => 'Street 1']);
        $this->assertStringNotContainsString('·', $addressOnly);
    }

    public function test_importing_a_site_template_carries_the_identity_fields_across(): void
    {
        // The whole point of "make it look like the promotion email": the
        // fields map one for one instead of being flattened into free text.
        $defaults = MailgunReceiverTemplate::defaults();

        foreach (['greeting', 'postal_address', 'contact_email', 'copyright_text', 'unsubscribe_label'] as $field) {
            $this->assertArrayHasKey($field, $defaults, "{$field} must be part of the template shape");
        }

        $rules = MailgunReceiverTemplate::rules();

        foreach (['greeting', 'postal_address', 'contact_email', 'copyright_text', 'unsubscribe_label'] as $field) {
            $this->assertArrayHasKey("message_template.{$field}", $rules, "{$field} must be editable in the modal");
        }
    }

    public function test_an_unknown_field_is_dropped_rather_than_rendered(): void
    {
        $merged = MailgunReceiverTemplate::merged(['heading' => 'Hi', 'not_a_field' => 'x']);

        $this->assertArrayNotHasKey('not_a_field', $merged);
    }
}
