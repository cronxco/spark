<?php

namespace Tests\Fixtures;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Fakes the Jev API, answering whatever questions a request asks.
 *
 * Answers are chosen by matching question ids against fnmatch patterns:
 * a float for a Noul, an option name for a Choice (given 0.9 probability).
 * Unmatched Nouls get $defaultNoul; unmatched Choices pick their last option.
 */
class FakeJev
{
    public const URL = 'https://api.typesafe.test/v1/systemone';

    /**
     * @param  array<string, float|string>  $answers
     */
    public static function fake(array $answers = [], float $defaultNoul = 0.1): void
    {
        self::configure();

        Http::fake([self::URL => fn (Request $request) => Http::response(self::answer($request, $answers, $defaultNoul))]);
    }

    public static function unavailable(): void
    {
        self::configure();

        Http::fake([self::URL => Http::response('', 503)]);
    }

    public static function configure(): void
    {
        config([
            'services.jev.enabled' => true,
            'services.jev.api_key' => 'test-key',
            'services.jev.base_url' => 'https://api.typesafe.test',
            'services.jev.model' => 'jev-1.13.0',
        ]);
    }

    /**
     * @param  array<string, float|string>  $answers
     * @return array<string, mixed>
     */
    private static function answer(Request $request, array $answers, float $defaultNoul): array
    {
        $out = [];

        foreach ($request['questions'] as $id => $question) {
            $match = null;
            foreach ($answers as $pattern => $value) {
                if (fnmatch($pattern, $id)) {
                    $match = $value;
                    break;
                }
            }

            if ($question['type'] === 'noul') {
                $out[$id] = ['type' => 'noul', 'noul' => is_float($match) || is_int($match) ? (float) $match : $defaultNoul];

                continue;
            }

            $options = array_map('strval', array_keys($question['criteria']));
            $chosen = is_string($match) && in_array($match, $options, true) ? $match : end($options);
            $rest = count($options) > 1 ? 0.1 / (count($options) - 1) : 0.0;

            $out[$id] = [
                'type' => $question['type'],
                'choice' => $chosen,
                'probabilities' => array_combine($options, array_map(fn (string $option): float => $option === $chosen ? 0.9 : $rest, $options)),
                'confidence' => 0.8,
            ];

            if ($question['type'] === 'score') {
                $out[$id]['score'] = (float) array_search($chosen, $options, true);
            }
        }

        return ['model' => 'jev-1.13.0', 'answers' => $out, 'usage' => ['input_tokens' => 500, 'output_tokens' => 20]];
    }
}
