<?php

declare(strict_types=1);

namespace CountryQuiz;

/**
 * The quiz rules: picks questions, builds answer options and keeps the score.
 * State lives in the PHP session ($_SESSION['quiz']), so no database is needed.
 */
final class Quiz
{
    /** Number of answer options in "pick" mode (the correct one + nearby countries). */
    private const OPTION_COUNT = 5;

    /** How many recently asked countries to avoid repeating. */
    private const RECENT_HISTORY_SIZE = 15;

    /** Smallest map view in degrees, so tiny countries (Vatican, Monaco) still show surroundings. */
    private const MIN_VIEW_SIZE_DEGREES = 8.0;

    /** Extra space around the country, as a share of its size. */
    private const VIEW_PADDING_SHARE = 0.35;

    /** @var array<string, mixed> Reference to the session state. */
    private array $state;

    public function __construct(private readonly CountryRepository $repository, array &$sessionStorage)
    {
        $sessionStorage['quiz'] ??= self::emptyState();
        $this->state = &$sessionStorage['quiz'];
    }

    private static function emptyState(): array
    {
        return [
            'currentCode' => null,
            'answered' => true,
            'recentCodes' => [],
            'score' => ['answered' => 0, 'correct' => 0, 'streak' => 0, 'bestStreak' => 0],
        ];
    }

    public function resetScore(): void
    {
        $this->state['score'] = self::emptyState()['score'];
    }

    /** @return array{answered: int, correct: int, streak: int, bestStreak: int} */
    public function score(): array
    {
        return $this->state['score'];
    }

    /**
     * Picks a new random country in the area and returns everything the page needs to draw it.
     *
     * @return array<string, mixed>
     */
    public function newQuestion(string $area, string $language): array
    {
        $candidates = $this->repository->quizCountries($area);
        $notRecentlyAsked = array_values(array_filter(
            $candidates,
            fn (array $country): bool => !in_array($country['code'], $this->state['recentCodes'], true)
        ));
        $pool = $notRecentlyAsked !== [] ? $notRecentlyAsked : $candidates;
        $country = $pool[random_int(0, count($pool) - 1)];

        $this->state['currentCode'] = $country['code'];
        $this->state['answered'] = false;
        $this->state['recentCodes'][] = $country['code'];
        $this->state['recentCodes'] = array_slice($this->state['recentCodes'], -self::RECENT_HISTORY_SIZE);

        $view = self::viewAround($country['focus']);

        return [
            'view' => $view,
            'target' => ['rings' => $this->repository->outlineForView($country, $view)],
            'surroundings' => $this->repository->countriesInView($view, $country['code']),
            'options' => $this->buildOptions($country, $language),
            'score' => $this->score(),
        ];
    }

    /**
     * Checks an answer. In pick mode the page sends a country code, in type mode the typed text.
     *
     * @return array<string, mixed>
     */
    public function answer(?string $chosenCode, ?string $typedName, string $language): array
    {
        $currentCode = $this->state['currentCode'];
        $country = $currentCode !== null ? $this->repository->find($currentCode) : null;
        if ($country === null) {
            throw new \RuntimeException('There is no active question.');
        }

        // Which country did the player actually name? (Used to show their guess on the map.)
        $guessedCode = $chosenCode;
        if ($typedName !== null) {
            $guessedCode = NameMatcher::matches($typedName, $country)
                ? $country['code']
                : $this->findCodeByName($typedName);
        }
        $isCorrect = $guessedCode === $country['code'];

        // Only the first answer to a question counts towards the score.
        if (!$this->state['answered']) {
            $this->state['answered'] = true;
            $score = &$this->state['score'];
            $score['answered']++;
            if ($isCorrect) {
                $score['correct']++;
                $score['streak']++;
                $score['bestStreak'] = max($score['bestStreak'], $score['streak']);
            } else {
                $score['streak'] = 0;
            }
        }

        return [
            'correct' => $isCorrect,
            'correctCode' => $country['code'],
            'correctName' => self::localName($country, $language),
            'otherName' => self::localName($country, $language === 'sv' ? 'en' : 'sv'),
            'guessedCode' => $guessedCode,
            'score' => $this->score(),
        ];
    }

    /**
     * Names of every quiz country, for the autocomplete list in type mode.
     *
     * @return list<string>
     */
    public function allNames(string $language): array
    {
        $names = array_map(
            static fn (array $country): string => self::localName($country, $language),
            $this->repository->quizCountries()
        );
        sort($names, SORT_LOCALE_STRING);
        return $names;
    }

    /**
     * The correct country plus the closest neighbours, in random order.
     *
     * @return list<array{code: string, name: string}>
     */
    private function buildOptions(array $country, string $language): array
    {
        // Take the wrong answers from the closest neighbours, with a little randomness so the
        // same four countries are not always shown for a country.
        $closestCodes = array_slice($country['nearby'], 0, self::OPTION_COUNT + 2);
        shuffle($closestCodes);
        $optionCodes = array_slice($closestCodes, 0, self::OPTION_COUNT - 1);
        $optionCodes[] = $country['code'];
        shuffle($optionCodes);

        return array_map(function (string $code) use ($language): array {
            return ['code' => $code, 'name' => self::localName($this->repository->find($code), $language)];
        }, $optionCodes);
    }

    private function findCodeByName(string $typedName): ?string
    {
        foreach ($this->repository->quizCountries() as $country) {
            if (NameMatcher::matches($typedName, $country)) {
                return $country['code'];
            }
        }
        return null;
    }

    private static function localName(array $country, string $language): string
    {
        return $language === 'sv' ? $country['nameSv'] : $country['nameEn'];
    }

    /**
     * Pads the country's extent so its surroundings are visible, with a minimum size.
     *
     * @param array{float, float, float, float} $focus
     * @return array{float, float, float, float}
     */
    private static function viewAround(array $focus): array
    {
        [$minLongitude, $minLatitude, $maxLongitude, $maxLatitude] = $focus;
        $centerLongitude = ($minLongitude + $maxLongitude) / 2;
        $centerLatitude = ($minLatitude + $maxLatitude) / 2;

        $halfWidth = max(($maxLongitude - $minLongitude) * (0.5 + self::VIEW_PADDING_SHARE), self::MIN_VIEW_SIZE_DEGREES / 2);
        $halfHeight = max(($maxLatitude - $minLatitude) * (0.5 + self::VIEW_PADDING_SHARE), self::MIN_VIEW_SIZE_DEGREES / 2);

        return [
            round($centerLongitude - $halfWidth, 3),
            round(max(-90, $centerLatitude - $halfHeight), 3),
            round($centerLongitude + $halfWidth, 3),
            round(min(90, $centerLatitude + $halfHeight), 3),
        ];
    }
}
