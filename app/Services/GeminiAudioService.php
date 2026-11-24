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
     * Loops through all configured GEMINI_API_KEYS and handles failover on
     * 429/401/403/5xx by rotating to the next healthy key. Keys with
     * auth/billing failures (401/403 with specific messages) are quarantined
     * for 6h via gemini_key_cooldown:{$keyHash}.
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

        // Load ALL configured keys (GEMINI_API_KEYS + fallback to GEMINI_API_KEY)
        $keys = config('services.gemini.keys', []);
        $effectiveKeys = $keys !== [] ? $keys : [trim((string) config('services.gemini.key', ''))];
        $effectiveKeys = array_values(array_unique(array_filter($effectiveKeys)));

        if ($effectiveKeys === []) {
            throw new RuntimeException('No GEMINI_API_KEYS or GEMINI_API_KEY configured.');
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

        $url = self::BASE_URL.'/'.$model.':streamGenerateContent';
        $tempDir = rtrim((string) config('services.reels.temp_dir', sys_get_temp_dir()), '/\\');
        if (! is_dir($tempDir)) {
            @mkdir($tempDir, 0775, true);
        }

        $ulid = (string) Str::ulid();
        $audioPath = $tempDir.'/audio_'.$ulid;

        $lastError = null;

        // Loop through ALL configured keys, rotating on 429/401/403/5xx
        foreach ($effectiveKeys as $idx => $rawKey) {
            $key = trim((string) $rawKey);
            if ($key === '') {
                continue;
            }

            $keyHash = substr(hash('sha256', $key), 0, 12);
            $keyLabel = 'Key #' . ($idx + 1) . ' (…' . substr($key, -4) . ')';

            // Skip keys on auth/billing cooldown
            if (\Illuminate\Support\Facades\Cache::has("gemini_key_cooldown:{$keyHash}")) {
                $reason = (string) \Illuminate\Support\Facades\Cache::get("gemini_key_cooldown:{$keyHash}", '');
                Log::info('GeminiAudioService: skipping key on cooldown.', [
                    'key_label' => $keyLabel,
                    'reason' => $reason,
                ]);
                continue;
            }

            try {
                /** @var \Illuminate\Http\Client\Response $response */
                $response = \Illuminate\Support\Facades\Http::timeout(self::TIMEOUT_SECONDS)
                    ->withHeaders([
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                        'x-goog-api-key' => $key,
                    ])
                    ->withOptions(['http_errors' => false])
                    ->post(self::BASE_URL.'/'.$model.':streamGenerateContent', $payload);

                $status = $response->status();

                if ($status === 429) {
                    $body = (string) $response->body();
                    // RPD exhaustion: mark this key+model on cooldown for PT day
                    // For TTS we use a simpler per-key 6h cooldown
                    \Illuminate\Support\Facades\Cache::put("gemini_key_cooldown:{$keyHash}", '429_rpd', 21600);
                    Log::warning('GeminiAudioService: key rate limited (429), trying next key.', [
                        'key_label' => $keyLabel,
                    ]);
                    continue;
                }

                if ($status === 401 || $status === 403) {
                    $body = (string) $response->body();
                    $isAuthOrBilling = preg_match(
                        '/API_KEY_INVALID|API key not valid|billing|suspended|PERMISSION_DENIED|quota.*exceeded|insufficient.*permission/i',
                        $body
                    ) === 1;

                    if ($isAuthOrBilling) {
                        // Quarantine this key for 6 hours
                        \Illuminate\Support\Facades\Cache::put("gemini_key_cooldown:{$keyHash}", 'auth_or_billing', 21600);
                        \Illuminate\Support\Facades\Cache::put("gemini_key_cooldown_until:{$keyHash}", time() + 21600, 21600);
                        Log::warning('GeminiAudioService: key auth/billing failure — key quarantined.', [
                            'key_label' => $keyLabel,
                        ]);
                        continue;
                    }

                    // Other 401/403 (model permission): treat as unsupported model for this key
                    Log::warning('GeminiAudioService: key permission error, trying next key.', [
                        'key_label' => $keyLabel,
                    ]);
                    continue;
                }

                if (! $response->successful()) {
                    // 5xx: try next key
                    Log::warning('GeminiAudioService: key HTTP error, trying next key.', [
                        'key_label' => $keyLabel,
                        'status' => $status,
                        'body_snippet' => substr((string) $response->body(), 0, 300),
                    ]);
                    continue;
                }

                // Success! Extract audio
                $extracted = $this->extractAudio($response->json() ?? []);

                if ($extracted['base64'] === null) {
                    throw new RuntimeException('Gemini TTS response carries no inline audio data.');
                }

                $binary = base64_decode($extracted['base64'], true);

                if ($binary === false || $binary === '') {
                    throw new RuntimeException('Gemini TTS audio base64 payload failed to decode.');
                }

                [$container, $audioBytes] = $this->containerize($binary, $extracted['mimeType']);

                $audioPath = $tempDir.'/audio_'.$ulid.'.'.$container;

                if (file_put_contents($audioPath, $audioBytes) === false) {
                    throw new RuntimeException('Failed to write temporary audio file: '.$audioPath);
                }

                try {
                    Log::info('GeminiAudioService: voiceover synthesized.', [
                        'voice' => $voiceName,
                        'bytes' => strlen($binary),
                        'path' => $audioPath,
                        'key_label' => $keyLabel,
                    ]);
                } catch (Throwable) {
                    // A broken log file must never discard a synthesized voiceover.
                }

                return ['path' => $audioPath, 'bytes' => strlen($binary)];

            } catch (RuntimeException $e) {
                @unlink($audioPath);
                $lastError = $e;
                Log::warning('GeminiAudioService: key failed, trying next.', [
                    'key_label' => $keyLabel,
                    'error' => $e->getMessage(),
                ]);
                continue;
            } catch (Throwable $e) {
                @unlink($audioPath);
                $lastError = new RuntimeException('Gemini TTS synthesis failed: '.$e->getMessage(), 0, $e);
                Log::warning('GeminiAudioService: key exception, trying next.', [
                    'key_label' => $keyLabel,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
        }

        // All keys exhausted
        $msg = $lastError ? $lastError->getMessage() : 'All configured GEMINI_API_KEYS exhausted (rate limited, auth failed, or errors).';
        throw new RuntimeException('Gemini TTS: '.$msg);
    }

    /**
     * Parse inline audio across a streamed response. streamGenerateContent
     * returns a JSON ARRAY of response chunks:
     *   [ {candidates:[{content:{parts:[{inlineData:{data:...}}]}}]}, {...} ]
     * while plain generateContent returns a single object — both shapes are
     * accepted and every inline audio chunk is merged in order. The FIRST
     * part's mimeType is reported (Gemini TTS serves raw PCM as
     * "audio/L16;codec=pcm;rate=24000").
     *
     * @param  array<string, mixed>  $decoded
     * @return array{base64: ?string, mimeType: ?string}
     */
    private function extractAudio(array $decoded): array
    {
        $chunks = $decoded;

        if (! isset($decoded[0]) || ! is_array($decoded[0])) {
            // Single-object response → treat as a one-element stream.
            $chunks = [$decoded];
        }

        $audioParts = [];
        $mimeType = null;

        foreach ($chunks as $chunk) {
            if (! is_array($chunk)) {
                continue;
            }

            $parts = $chunk['candidates'][0]['content']['parts'] ?? null;

            if (! is_array($parts)) {
                continue;
            }

            foreach ($parts as $part) {
                if (! is_array($part)) {
                    continue;
                }

                if (isset($part['inlineData']['data'])) {
                    $audioParts[] = $part['inlineData']['data'];
                    $mimeType = $mimeType ?? ($part['inlineData']['mimeType'] ?? null);
                }
            }
        }

        if ($audioParts === []) {
            return ['base64' => null, 'mimeType' => null];
        }

        $fullBase64 = implode('', $audioParts);

        return ['base64' => $fullBase64, 'mimeType' => $mimeType];
    }

    /**
     * Wrap raw PCM bytes into a standard WAV container so ffprobe/players
     * can read duration and metadata. PCM params: 24 kHz, 16-bit, mono.
     *
     * @return array{0: string, 1: string} [extension, binary]
     */
    private function containerize(string $binary, ?string $mimeType): array
    {
        $rate = 24000;
        $bits = 16;
        $channels = 1;

        $dataSize = strlen($binary);
        $blockAlign = $channels * $bits / 8;
        $byteRate = $rate * $blockAlign;

        $header = pack('VvvVvvH*', 0x46464952, 36 + $dataSize, 0x4556, 0x20746D66, 16, 1, $channels, $rate, $byteRate, $blockAlign, $bits, '64617461', $dataSize);

        return ['wav', $header.$binary];
    }
}