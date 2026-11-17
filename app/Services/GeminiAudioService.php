<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Gemini Audio Synthesis — turns the reel script into a dynamic radio-ad
 * voiceover (prebuilt voice, default "Tavi").
 *
 * Endpoint: POST https://generativelanguage.googleapis.com/v1beta/models/{model}:streamGenerateContent
 * speechMetadata is ONLY supported by TTS models (e.g. gemini-3.8-flash-tts)
 * — on non-TTS models the API answers 400 "Speech metadata is not supported
 * for this model".
 * Auth travels in the `x-goog-api-key` header (never `?key=` in the query
 * string — same contract as GeminiVisionService).
 */
final class GeminiAudioService
{
    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/models';

    private const TIMEOUT_SECONDS = 180;

    private const STYLE_PROMPT = 'Style: Peppy, bright, and playful commercial voiceover hooking the listener with curious suspense.';

    /**
     * Synthesize the script and write the audio bytes to a temporary file.
     *
     * @param  string  $scriptText  Full Egyptian radio-ad script.
     * @param  string|null  $voiceName  Prebuilt voice name (null = config default).
     * @return array{path: string, bytes: int} Absolute path of the temporary audio file.
     *
     * @throws RuntimeException
     */
    public function synthesize(string $scriptText, ?string $voiceName = null): array
    {
        $scriptText = trim($scriptText);

        if ($scriptText === '') {
            throw new RuntimeException('Cannot synthesize an empty reel script.');
        }

        $apiKey = trim((string) config('services.gemini.key', ''));

        if ($apiKey === '') {
            throw new RuntimeException('GEMINI_API_KEY is not configured.');
        }

        $voiceName = $voiceName !== null && trim($voiceName) !== ''
            ? trim($voiceName)
            : trim((string) config('services.reels.voice_name', 'Tavi'));

        $model = trim((string) config('services.gemini.tts_model', 'gemini-3.8-flash-tts'));

        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        [
                            'text' => "## Transcript:\n".$scriptText,
                            'speechMetadata' => [
                                'style' => self::STYLE_PROMPT,
                            ],
                        ],
                    ],
                ],
            ],
            'generationConfig' => [
                'responseModalities' => ['audio'],
                'temperature' => 1,
                'speech_config' => [
                    'voice_config' => [
                        'prebuilt_voice_config' => [
                            'voice_name' => $voiceName,
                        ],
                    ],
                ],
            ],
        ];

        // streamGenerateContent — the endpoint the TTS models are driven
        // through (matches the working cURL contract). The response body is
        // a JSON ARRAY of GenerateContentResponse chunks whose inlineData
        // parts are concatenated in order.
        $url = self::BASE_URL.'/'.$model.':streamGenerateContent';

        $tempDir = rtrim((string) config('services.reels.temp_dir', sys_get_temp_dir()), '/\\');
        if (! is_dir($tempDir)) {
            @mkdir($tempDir, 0775, true);
        }

        $ulid = (string) Str::ulid();
        $audioPath = $tempDir.'/audio_'.$ulid.'.wav';

        try {
            /** @var \Illuminate\Http\Client\Response $response */
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'x-goog-api-key' => $apiKey,
                ])
                ->withOptions(['http_errors' => false])
                ->post($url, $payload);

            if ($response->status() === 429) {
                throw new RuntimeException('Gemini TTS rate limited (429).');
            }

            if (! $response->successful()) {
                throw new RuntimeException('Gemini TTS error: HTTP '.$response->status().' - '.substr((string) $response->body(), 0, 500));
            }

            $base64Audio = $this->extractAudioBase64($response->json() ?? []);

            if ($base64Audio === null) {
                throw new RuntimeException('Gemini TTS response carries no inline audio data.');
            }

            $binary = base64_decode($base64Audio, true);

            if ($binary === false || $binary === '') {
                throw new RuntimeException('Gemini TTS audio base64 payload failed to decode.');
            }

            if (file_put_contents($audioPath, $binary) === false) {
                throw new RuntimeException('Failed to write temporary audio file: '.$audioPath);
            }

            Log::info('GeminiAudioService: voiceover synthesized.', [
                'voice' => $voiceName,
                'bytes' => strlen($binary),
                'path' => $audioPath,
            ]);

            return ['path' => $audioPath, 'bytes' => strlen($binary)];
        } catch (RuntimeException $e) {
            @unlink($audioPath);

            throw $e;
        } catch (Throwable $e) {
            @unlink($audioPath);

            throw new RuntimeException('Gemini TTS synthesis failed: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Parse inline audio across a streamed response. streamGenerateContent
     * returns a JSON ARRAY of response chunks:
     *   [ {candidates:[{content:{parts:[{inlineData:{data:...}}]}}]}, {...} ]
     * while plain generateContent returns a single object — both shapes are
     * accepted and every inline audio chunk is merged in order.
     *
     * @param  array<string, mixed>  $decoded
     */
    private function extractAudioBase64(array $decoded): ?string
    {
        $chunks = $decoded;

        if (! isset($decoded[0]) || ! is_array($decoded[0])) {
            // Single-object response → treat as a one-element stream.
            $chunks = [$decoded];
        }

        $audioParts = [];

        foreach ($chunks as $chunk) {
            if (! is_array($chunk)) {
                continue;
            }

            $parts = $chunk['candidates'][0]['content']['parts'] ?? null;

            if (! is_array($parts)) {
                continue;
            }

            foreach ($parts as $part) {
                $data = is_array($part) ? ($part['inlineData']['data'] ?? null) : null;

                if (is_string($data) && $data !== '') {
                    $audioParts[] = $data;
                }
            }
        }

        return $audioParts === [] ? null : implode('', $audioParts);
    }
}
