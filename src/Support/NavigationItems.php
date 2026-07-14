<?php

namespace Blemli\FilamentMouseless\Support;

use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Illuminate\Support\Str;

/**
 * Turns the current panel's navigation tree into jump-ready targets for the
 * "go to" palette (the leader-key overlay).
 *
 * We read {@see Panel::getNavigation()} rather than enumerating
 * resources: Filament has already authorization-filtered, grouped, labelled,
 * icon'd and ordered every item there, so the palette inherits exactly what the
 * sidebar shows — resources, custom pages, the dashboard, cluster items — with
 * zero extra visibility rules to maintain.
 *
 * Each item carries a JetBrains-style **chord**: the first letter of every word
 * in its label ("Product Categories" → `pc`), grown one letter at a time,
 * left-to-right, only when an earlier item already claimed that chord (so a
 * later "Post Categories" becomes `poc`). Typing the chord — or any prefix of
 * the label — filters the palette; the chord letters are highlighted per row
 * (see each entry's `segments`) to teach the shortcut.
 */
class NavigationItems
{
    /** Splits a label into alternating word / separator tokens, keeping both. */
    protected const WORD_SPLIT = '/([\s\-_\/]+)/u';

    /**
     * @return array<int, array{label: ?string, items: array<int, array{
     *   label: string, url: string, icon: mixed, active: bool,
     *   chord: string, segments: array<int, array{text: string, hit: bool}>,
     * }>}>
     */
    public function forCurrentPanel(): array
    {
        $panel = Filament::getCurrentPanel();

        if (! $panel) {
            return [];
        }

        try {
            $navigation = $panel->getNavigation();
        } catch (\Throwable) {
            // Building navigation can touch app services (tenancy, gates) that
            // aren't available in every context — fail soft to an empty palette.
            return [];
        }

        $groups = [];

        foreach ($navigation as $group) {
            $items = [];

            foreach ($group->getItems() as $item) {
                $entry = $this->normalize($item);

                if ($entry === null) {
                    continue;
                }

                $items[] = $entry;
            }

            if ($items === []) {
                continue;
            }

            $groups[] = [
                'label' => $group->getLabel(),
                'items' => $items,
            ];
        }

        // Chords are unique across the *whole* visible set, so they can only be
        // assigned once every group's items are known.
        $this->assignChords($groups);

        return $groups;
    }

    /**
     * @return array{label: string, url: string, icon: mixed, active: bool}|null
     */
    protected function normalize(NavigationItem $item): ?array
    {
        if ($item->isHidden()) {
            return null;
        }

        $url = $item->getUrl();

        // Group headers and JS-only items carry no real destination.
        if (! is_string($url) || $url === '' || $url === '#') {
            return null;
        }

        $label = $item->getLabel();

        if (! is_string($label) || trim($label) === '') {
            return null;
        }

        return [
            'label' => $label,
            'url' => $url,
            'icon' => $item->getIcon(),
            'active' => $item->isActive(),
        ];
    }

    /**
     * Assign each item a unique camelCase chord, walking the navigation in
     * order. The first item to want a chord keeps it; a later clasher grows its
     * chord one letter at a time (left-to-right across words) until it's free —
     * or until its label is exhausted, in which case identical labels share a
     * chord and are reachable only by typing the full label + Enter.
     *
     * @param  array<int, array{label: ?string, items: array<int, array<string, mixed>>}>  $groups
     */
    protected function assignChords(array &$groups): void
    {
        $used = [];

        foreach ($groups as &$group) {
            foreach ($group['items'] as &$entry) {
                [$chord, $segments] = $this->chordFor($entry['label'], $used);

                $entry['chord'] = $chord;
                $entry['segments'] = $segments;
                $used[$chord] = true;
            }
            unset($entry);
        }
        unset($group);
    }

    /**
     * @param  array<string, bool>  $used  chords already claimed by earlier items
     * @return array{0: string, 1: array<int, array{text: string, hit: bool}>}
     */
    protected function chordFor(string $label, array $used): array
    {
        $tokens = preg_split(self::WORD_SPLIT, $label, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$label];

        // Even indices are words, odd indices the separators between them.
        $wordTokens = [];
        foreach ($tokens as $i => $token) {
            if ($i % 2 === 0 && $token !== '') {
                $wordTokens[] = $i;
            }
        }

        // A label with no real words (all separators) can't form a chord.
        if ($wordTokens === []) {
            return [Str::lower($label), [['text' => $label, 'hit' => false]]];
        }

        $humps = array_fill(0, count($wordTokens), 1);
        $chord = $this->buildChord($tokens, $wordTokens, $humps);

        while (isset($used[$chord]) && $this->grow($tokens, $wordTokens, $humps)) {
            $chord = $this->buildChord($tokens, $wordTokens, $humps);
        }

        return [$chord, $this->buildSegments($tokens, $wordTokens, $humps)];
    }

    /**
     * @param  array<int, string>  $tokens
     * @param  array<int, int>  $wordTokens  token index of each word, in order
     * @param  array<int, int>  $humps  highlighted-letter count per word
     */
    protected function buildChord(array $tokens, array $wordTokens, array $humps): string
    {
        $chord = '';

        foreach ($wordTokens as $k => $tokenIndex) {
            $chord .= mb_substr($tokens[$tokenIndex], 0, $humps[$k]);
        }

        return Str::lower($chord);
    }

    /**
     * Extend the leftmost hump that still has letters left. Returns false when
     * every word is fully consumed — the chord can grow no further.
     *
     * @param  array<int, string>  $tokens
     * @param  array<int, int>  $wordTokens
     * @param  array<int, int>  $humps
     */
    protected function grow(array $tokens, array $wordTokens, array &$humps): bool
    {
        foreach ($wordTokens as $k => $tokenIndex) {
            if ($humps[$k] < mb_strlen($tokens[$tokenIndex])) {
                $humps[$k]++;

                return true;
            }
        }

        return false;
    }

    /**
     * Split every word into its highlighted hump + the rest, keeping separators,
     * so the blade view can emphasise exactly the chord letters.
     *
     * @param  array<int, string>  $tokens
     * @param  array<int, int>  $wordTokens
     * @param  array<int, int>  $humps
     * @return array<int, array{text: string, hit: bool}>
     */
    protected function buildSegments(array $tokens, array $wordTokens, array $humps): array
    {
        $humpByToken = array_combine($wordTokens, $humps);
        $segments = [];

        foreach ($tokens as $i => $token) {
            if ($token === '') {
                continue;
            }

            if (! isset($humpByToken[$i])) {
                $segments[] = ['text' => $token, 'hit' => false];

                continue;
            }

            $hump = $humpByToken[$i];
            $segments[] = ['text' => mb_substr($token, 0, $hump), 'hit' => true];

            $rest = mb_substr($token, $hump);
            if ($rest !== '') {
                $segments[] = ['text' => $rest, 'hit' => false];
            }
        }

        return $segments;
    }
}
