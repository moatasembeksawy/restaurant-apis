<?php

declare(strict_types=1);

namespace App\Modules\Intelligence\Reports\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class LLMNarrativeService
{
    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    public function enhance(array $summary): array
    {
        $openAiNarrative = $this->generateWithOpenAI($summary);

        if ($openAiNarrative !== null) {
            $summary['narrative'] = $openAiNarrative;
            $summary['narrative_source'] = 'openai';
        } else {
            $summary['narrative'] = $this->rulesNarrative($summary);
            $summary['narrative_source'] = 'rules';
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array{summary_ar: string, summary_en: string, recommendations: list<string>}|null
     */
    private function generateWithOpenAI(array $summary): ?array
    {
        if (! config('intelligence.llm.enabled')) {
            return null;
        }

        $apiKey = (string) config('intelligence.llm.api_key');

        if ($apiKey === '') {
            return null;
        }

        $model = (string) config('intelligence.llm.model', 'gpt-4o-mini');
        $prompt = $this->buildPrompt($summary);

        try {
            $response = Http::withToken($apiKey)
                ->timeout((int) config('intelligence.llm.timeout', 30))
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $model,
                    'temperature' => 0.4,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'You are a restaurant operations analyst for Egypt/MENA. Respond ONLY with valid JSON containing keys: summary_ar, summary_en, recommendations (array of 2-4 short strings in English).',
                        ],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                ]);

            if (! $response->successful()) {
                return null;
            }

            $content = $response->json('choices.0.message.content');

            if (! is_string($content)) {
                return null;
            }

            /** @var array{summary_ar?: string, summary_en?: string, recommendations?: list<string>}|null $parsed */
            $parsed = json_decode($content, true);

            if (! is_array($parsed) || empty($parsed['summary_ar']) || empty($parsed['summary_en'])) {
                return null;
            }

            return [
                'summary_ar' => (string) $parsed['summary_ar'],
                'summary_en' => (string) $parsed['summary_en'],
                'recommendations' => array_values($parsed['recommendations'] ?? []),
            ];
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array{summary_ar: string, summary_en: string, recommendations: list<string>}
     */
    private function rulesNarrative(array $summary): array
    {
        $revenue = (float) ($summary['summary']['total_revenue'] ?? 0);
        $orders = (int) ($summary['summary']['paid_orders'] ?? 0);
        $change = $summary['comparison']['revenue_change_pct'] ?? null;

        $changeAr = $change === null ? 'مستقر' : "{$change}%";
        $changeEn = $change === null ? 'flat' : "{$change}%";

        $insights = collect($summary['insights'] ?? [])
            ->pluck('message_en')
            ->take(3)
            ->values()
            ->all();

        return [
            'summary_ar' => "إيرادات الأسبوع {$revenue} جنيه من {$orders} طلب مدفوع. التغير عن الأسبوع الماضي: {$changeAr}.",
            'summary_en' => "Weekly revenue EGP {$revenue} from {$orders} paid orders. Change vs last week: {$changeEn}.",
            'recommendations' => $insights !== [] ? $insights : [
                'Review peak-hour staffing against paid order volume.',
                'Promote direct channels to reduce aggregator commission.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function buildPrompt(array $summary): string
    {
        return 'Analyze this weekly restaurant KPI JSON and produce executive narrative JSON. Data: '
            .json_encode([
                'summary' => $summary['summary'] ?? [],
                'comparison' => $summary['comparison'] ?? [],
                'channels' => $summary['channels'] ?? [],
                'top_items' => $summary['top_items'] ?? [],
                'insights' => $summary['insights'] ?? [],
            ], JSON_UNESCAPED_UNICODE);
    }
}
