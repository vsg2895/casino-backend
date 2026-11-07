<?php

declare(strict_types=1);

namespace App\Services\News;

use App\Models\Casino;
use App\Models\CasinoDetail;
use App\Models\Site;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * This site's own casino data that bears on one news story.
 *
 * The rewrite would otherwise know only what the source said. A story that
 * names a regulator, an operator, a company or a game provider is matched to
 * the site's casinos with that licence, name or provider, and the rewrite is
 * handed those casinos' stored facts to state and link. That is information the
 * source does not have, which is what makes the post worth indexing.
 *
 * Only data the site already PUBLISHES is used: licences, company, providers
 * and withdrawal facts come from the operator profile, so they are read only
 * when the site shows that profile (`operator_profile_enabled`). Otherwise the
 * one usable match is a casino named in the story. Placeholder values
 * ("SAMPLE …" from the demo seeder) are never passed on.
 *
 * Matching is whole-word, on lowercase ASCII, so "Curaçao" matches "curacao"
 * and "MGA" does not match inside "Omega".
 */
final class NewsSiteFacts
{
    /** Casinos handed to the model per story; more reads as a listing, not news. */
    public const int MAX_CASINOS = 4;

    /** List values (payment methods) quoted per casino. */
    private const int MAX_LIST_ITEMS = 6;

    /** Shorter names and providers match too much by accident ("Bet", "Play"). */
    private const int MIN_TERM_LENGTH = 4;

    /** @var array<int, Collection<int, Casino>> casinos per site id, for one batch */
    private array $casinos = [];

    /**
     * @return list<array{name: string, path: string, facts: list<string>}> best match first
     */
    public function matching(Site $site, string $story): array
    {
        $text = self::fold($story);

        if ($text === '') {
            return [];
        }

        $profile = (bool) $site->operator_profile_enabled;
        $regulators = $profile ? $this->regulatorsNamedIn($text) : [];
        $matches = [];

        foreach ($this->casinosOf($site) as $casino) {
            $detail = $profile ? $casino->detail : null;
            [$score, $reasons] = $this->why($casino, $detail, $text, $regulators);

            if ($score === 0) {
                continue;
            }

            $matches[] = [
                'score' => $score,
                'name'  => (string) $casino->name,
                'path'  => '/casinos/' . $casino->slug,
                'facts' => [...$reasons, ...$this->profileFacts($detail)],
            ];
        }

        usort($matches, static fn (array $a, array $b): int => $b['score'] <=> $a['score'] ?: strcmp($a['name'], $b['name']));

        return array_map(
            static fn (array $m): array => ['name' => $m['name'], 'path' => $m['path'], 'facts' => $m['facts']],
            array_slice($matches, 0, self::MAX_CASINOS),
        );
    }

    /**
     * How strongly a casino relates to the story, and the facts that say so.
     * Being named outweighs a shared regulator, which outweighs a provider.
     *
     * @param  list<array{name: string, aliases: list<string>}>  $regulators
     * @return array{0: int, 1: list<string>}
     */
    private function why(Casino $casino, ?CasinoDetail $detail, string $text, array $regulators): array
    {
        $score = 0;
        $reasons = [];

        if (self::mentions($text, (string) $casino->name)) {
            $score += 3;
            $reasons[] = 'It is named in the story.';
        }

        if ($detail === null) {
            return [$score, $reasons];
        }

        $company = self::usable($detail->company);

        if ($company !== null && self::mentions($text, $company)) {
            $score += 3;
            $reasons[] = "It is run by {$company}, which the story names.";
        }

        $licences = self::folded((array) $detail->licences);

        foreach ($regulators as $regulator) {
            foreach ($regulator['aliases'] as $alias) {
                if (self::any($licences, static fn (string $licence): bool => self::wholeWord($licence, $alias))) {
                    $score += 2;
                    $reasons[] = "It holds a licence from {$regulator['name']}, the regulator in the story.";

                    continue 2;
                }
            }
        }

        foreach ((array) $detail->game_providers as $provider) {
            $provider = self::usable($provider);

            if ($provider !== null && self::mentions($text, $provider)) {
                $score += 1;
                $reasons[] = "It offers games from {$provider}, which the story names.";
            }
        }

        return [$score, $reasons];
    }

    /** @return list<string> */
    private function profileFacts(?CasinoDetail $detail): array
    {
        if ($detail === null) {
            return [];
        }

        $facts = [];
        $licences = array_values(array_filter(array_map(self::usable(...), (array) $detail->licences)));

        if ($licences !== []) {
            $facts[] = 'Licences: ' . implode(', ', $licences) . '.';
        }

        foreach (['withdrawal_time' => 'Withdrawal time', 'min_withdrawal' => 'Minimum withdrawal', 'withdrawal_limit' => 'Withdrawal limit'] as $field => $label) {
            if (($value = self::usable($detail->{$field})) !== null) {
                $facts[] = "{$label}: {$value}.";
            }
        }

        $methods = array_values(array_filter(array_map(self::usable(...), (array) $detail->payment_methods)));

        if ($methods !== []) {
            $facts[] = 'Payment methods include ' . implode(', ', array_slice($methods, 0, self::MAX_LIST_ITEMS)) . '.';
        }

        return $facts;
    }

    /**
     * @return list<array{name: string, aliases: list<string>}>
     */
    private function regulatorsNamedIn(string $text): array
    {
        return array_values(array_filter(
            (array) config('news.regulators', []),
            static fn (array $r): bool => self::any((array) ($r['aliases'] ?? []), static fn (string $a): bool => self::wholeWord($text, $a)),
        ));
    }

    /** @return Collection<int, Casino> */
    private function casinosOf(Site $site): Collection
    {
        return $this->casinos[$site->id] ??= Casino::query()
            ->where('casinos.active', true)
            ->attachedToSite($site->id)
            ->with('detail')
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);
    }

    private static function mentions(string $foldedText, string $term): bool
    {
        $term = self::fold($term);

        return mb_strlen($term) >= self::MIN_TERM_LENGTH && self::wholeWord($foldedText, $term);
    }

    private static function wholeWord(string $foldedText, string $foldedTerm): bool
    {
        return $foldedTerm !== ''
            && preg_match('/(?<![\p{L}\p{N}])' . preg_quote($foldedTerm, '/') . '(?![\p{L}\p{N}])/u', $foldedText) === 1;
    }

    /** Lowercase ASCII with collapsed whitespace and no markup. */
    private static function fold(string $text): string
    {
        $plain = html_entity_decode(strip_tags(str_replace('<', ' <', $text)), ENT_QUOTES | ENT_HTML5);

        return trim(preg_replace('/\s+/u', ' ', mb_strtolower(Str::ascii($plain))) ?? '');
    }

    /**
     * @param  array<mixed>  $values
     * @return list<string>
     */
    private static function folded(array $values): array
    {
        return array_values(array_map(self::fold(...), array_filter(array_map(self::usable(...), $values))));
    }

    /**
     * array_any() without needing PHP 8.4.
     *
     * @param  array<mixed>  $values
     */
    private static function any(array $values, callable $test): bool
    {
        foreach ($values as $value) {
            if ($test($value)) {
                return true;
            }
        }

        return false;
    }

    /** A stored value worth quoting, or null for empty and placeholder ones. */
    private static function usable(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' || stripos($value, 'sample') !== false ? null : $value;
    }
}
