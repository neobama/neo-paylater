<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ReceiptParserService
{
    public function parseFromStoredReceipt(string $path, string $disk = 'public'): array
    {
        $apiKey = (string) config('services.gemini.api_key');

        if ($apiKey === '') {
            throw new RuntimeException('GEMINI_API_KEY belum diatur.');
        }

        $fullPath = Storage::disk($disk)->path($path);

        if (! is_file($fullPath)) {
            throw new RuntimeException('File struk tidak ditemukan.');
        }

        $bytes = file_get_contents($fullPath);

        if ($bytes === false) {
            throw new RuntimeException('Gagal membaca file struk.');
        }

        $mimeType = mime_content_type($fullPath) ?: 'image/jpeg';
        $model = (string) config('services.gemini.model', 'gemini-2.5-flash');

        $response = Http::timeout(60)
            ->acceptJson()
            ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}", [
                'contents' => [
                    [
                        'parts' => [
                            [
                                'text' => <<<'PROMPT'
Extract this receipt into JSON only.

Return this exact shape:
{
  "title": "string",
  "merchant": "string|null",
  "transaction_date": "YYYY-MM-DD|null",
  "items": [
    {
      "name": "string",
      "quantity": 1,
      "unit_price": 10000,
      "total_amount": 10000
    }
  ]
}

Rules:
- Use Indonesian rupiah integers without punctuation.
- If quantity is unclear, use 1.
- If the merchant is unclear, use null.
- If the date is unclear, use null.
- Return valid JSON only, no markdown fence.
PROMPT,
                            ],
                            [
                                'inlineData' => [
                                    'mimeType' => $mimeType,
                                    'data' => base64_encode($bytes),
                                ],
                            ],
                        ],
                    ],
                ],
            ])
            ->throw();

        $text = data_get($response->json(), 'candidates.0.content.parts.0.text');

        if (! is_string($text) || trim($text) === '') {
            throw new RuntimeException('Gemini tidak mengembalikan hasil parsing.');
        }

        $payload = json_decode($this->extractJson($text), true, flags: JSON_THROW_ON_ERROR);

        $items = collect($payload['items'] ?? [])
            ->filter(fn (array $item): bool => filled($item['name'] ?? null))
            ->values()
            ->map(fn (array $item, int $index): array => [
                'name' => (string) $item['name'],
                'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                'unit_price' => max(0, (int) ($item['unit_price'] ?? 0)),
                'total_amount' => max(0, (int) ($item['total_amount'] ?? 0)),
                'source' => 'ai',
                'sort_order' => $index,
            ])
            ->all();

        return [
            'title' => $payload['title'] ?: 'Bill dari AI',
            'merchant' => $payload['merchant'] ?: null,
            'transaction_date' => $this->normalizeDate($payload['transaction_date'] ?? null),
            'items' => $items,
            'raw' => $payload,
        ];
    }

    private function extractJson(string $text): string
    {
        $trimmed = trim($text);

        if (str_starts_with($trimmed, '{')) {
            return $trimmed;
        }

        preg_match('/\{.*\}/s', $trimmed, $matches);

        if (! isset($matches[0])) {
            throw new RuntimeException('Response Gemini tidak berformat JSON.');
        }

        return $matches[0];
    }

    private function normalizeDate(?string $date): string
    {
        if (! filled($date)) {
            return now()->toDateString();
        }

        try {
            return Carbon::parse($date)->toDateString();
        } catch (\Throwable) {
            return now()->toDateString();
        }
    }
}
