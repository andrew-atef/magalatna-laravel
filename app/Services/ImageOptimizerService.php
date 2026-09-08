<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use RuntimeException;
use Spatie\PdfToImage\Pdf;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Throwable;

final class ImageOptimizerService
{
    private const MAX_WIDTH = 1200;

    private const WEBP_QUALITY = 80;

    private const DOWNLOAD_TIMEOUT = 15;

    /**
     * Resolve the best available image manager (Imagick preferred, GD fallback).
     */
    private function imageManager(): ImageManager
    {
        try {
            if (extension_loaded('imagick')) {
                return new ImageManager(new ImagickDriver);
            }
        } catch (Throwable $e) {
            Log::warning('Imagick driver unavailable, falling back to GD.', [
                'error' => $e->getMessage(),
            ]);
        }

        return new ImageManager(new GdDriver);
    }

    /**
     * Check if URL points to a PDF (case-insensitive, ignores query string).
     */
    private function isPdfUrl(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path)) {
            return false;
        }

        return str_ends_with(strtolower($path), '.pdf');
    }

    /**
     * Download raw binary with defensive timeout.
     *
     * @throws RuntimeException
     */
    private function downloadBinary(string $url): string
    {
        try {
            $response = Http::timeout(self::DOWNLOAD_TIMEOUT)
                ->withHeaders([
                    'User-Agent' => 'AmanPrice-ImageOptimizer/1.0 (+https://amanprice.tech)',
                    'Accept' => '*/*',
                ])
                ->withOptions([
                    'allow_redirects' => true,
                    'http_errors' => false,
                ])
                ->get($url);

            if (! $response->successful()) {
                Log::error('Image download failed: non-successful status.', [
                    'url' => $url,
                    'status' => $response->status(),
                    'body_snippet' => substr((string) $response->body(), 0, 500),
                ]);

                throw new RuntimeException('Failed to download resource: HTTP '.$response->status().' for URL '.$url);
            }

            $body = $response->body();

            if ($body === '' || $body === '0') {
                // Allow '0' as valid but usually empty is failure
                if ($body === '') {
                    throw new RuntimeException('Downloaded resource is empty for URL '.$url);
                }
            }

            return $body;
        } catch (RequestException $e) {
            Log::error('HTTP request exception during download.', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException('Failed to download resource (request exception): '.$e->getMessage(), 0, $e);
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error('Unexpected error during download.', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException('Failed to download resource: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Normalize directory to avoid double slashes or traversal.
     */
    private function normalizeDirectory(string $directory): string
    {
        $dir = trim($directory);
        $dir = trim($dir, '/');
        $dir = str_replace(['..', '\\'], '', $dir);
        $dir = (string) preg_replace('#/+#', '/', $dir);

        return trim($dir, '/');
    }

    /**
     * Public wrapper for optimizing binary (used by observers and Filament uploads).
     *
     * @throws RuntimeException
     */
    public function convertToWebp(string $binary): string
    {
        return $this->optimizeImageBinary($binary);
    }

    /**
     * Store WebP binary to any disk with immutable cache headers (if R2).
     *
     * @throws RuntimeException
     */
    public function storeWebp(string $webpBinary, string $directory, string $disk = 'r2'): string
    {
        try {
            $normalizedDir = $this->normalizeDirectory($directory);
            $filename = Str::ulid()->toString().'.webp';
            $key = $normalizedDir !== '' ? $normalizedDir.'/'.$filename : $filename;

            $options = [
                'visibility' => 'public',
                'ContentType' => 'image/webp',
            ];

            // Only R2 needs CacheControl immutable; public disk ignores it
            if ($disk === 'r2') {
                $options['CacheControl'] = 'public, max-age=31536000, immutable';
            }

            Storage::disk($disk)->put($key, $webpBinary, $options);

            Log::info('WebP stored.', ['disk' => $disk, 'key' => $key, 'bytes' => strlen($webpBinary)]);

            return $key;
        } catch (Throwable $e) {
            Log::error('WebP store failed.', ['disk' => $disk, 'directory' => $directory, 'error' => $e->getMessage()]);

            throw new RuntimeException('Failed to store WebP: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Optimize an UploadedFile (from Filament) and store as WebP.
     *
     * @throws RuntimeException
     */
    public function processUploadedFile(UploadedFile $file, string $directory, string $disk = 'r2'): string
    {
        $binary = (string) file_get_contents($file->getRealPath());

        if ($binary === '') {
            throw new RuntimeException('Uploaded file is empty: '.$file->getClientOriginalName());
        }

        $webpBinary = $this->convertToWebp($binary);

        return $this->storeWebp($webpBinary, $directory, $disk);
    }

    /**
     * Process a raw image binary: downscale if width > 1200, encode WebP q80.
     *
     * @throws RuntimeException
     */
    private function optimizeImageBinary(string $binary): string
    {
        try {
            $manager = $this->imageManager();
            $image = $manager->read($binary);

            // Detect dimensions and downscale proportionally if needed
            try {
                $width = $image->width();
                if ($width > self::MAX_WIDTH) {
                    // scaleDown ensures we only shrink, never upscale, preserving aspect ratio
                    $image->scaleDown(width: self::MAX_WIDTH);
                }
            } catch (Throwable $e) {
                Log::warning('Could not determine image width for scaling.', [
                    'error' => $e->getMessage(),
                ]);
            }

            // Convert to WebP with quality 80 - preserves fine print legibility
            try {
                $encoded = $image->encode(new WebpEncoder(quality: self::WEBP_QUALITY));
            } catch (Throwable $e) {
                Log::error('WebP encoding failed.', [
                    'error' => $e->getMessage(),
                ]);

                throw new RuntimeException('Failed to encode image to WebP: '.$e->getMessage(), 0, $e);
            }

            $webpBinary = (string) $encoded;

            if ($webpBinary === '') {
                throw new RuntimeException('WebP encoding produced empty binary.');
            }

            // Optional size guard: log if over 150KB (spec target), but do not fail
            if (strlen($webpBinary) > 150 * 1024) {
                Log::warning('Optimized WebP exceeds 150KB target.', [
                    'size_bytes' => strlen($webpBinary),
                ]);
            }

            return $webpBinary;
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error('Image optimization failed.', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new RuntimeException('Image optimization failed: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Upload WebP binary to R2 with immutable cache headers.
     *
     * @throws RuntimeException
     */
    private function uploadToR2(string $binary, string $directory): string
    {
        return $this->storeWebp($binary, $directory, 'r2');
    }

    /**
     * Process and upload a remote image to R2 as optimized WebP.
     *
     * - Downloads with 15s timeout, throws RuntimeException if unreachable
     * - Downscales width >1200 proportionally
     * - Converts to WebP quality 80
     * - ULID filename, uploads to `r2` with immutable cache
     *
     * @throws RuntimeException
     */
    public function processAndUpload(string $rawSourceUrl, string $directory): string
    {
        try {
            if (trim($rawSourceUrl) === '') {
                throw new RuntimeException('Source URL is empty.');
            }

            // Fallback: PDF detection at entry point
            if ($this->isPdfUrl($rawSourceUrl)) {
                Log::info('Source URL is PDF, delegating to PDF handler.', [
                    'url' => $rawSourceUrl,
                ]);

                $keys = $this->processPdfAndUpload($rawSourceUrl, $directory);

                if ($keys === []) {
                    throw new RuntimeException('PDF processing produced no images for URL '.$rawSourceUrl);
                }

                // For backward compatibility, return first page key
                // Caller that needs all pages should use processPdfAndUpload directly
                if (count($keys) > 1) {
                    Log::warning('PDF produced multiple pages, returning first key via processAndUpload.', [
                        'url' => $rawSourceUrl,
                        'count' => count($keys),
                    ]);
                }

                return $keys[0];
            }

            $binary = $this->downloadBinary($rawSourceUrl);
            $webpBinary = $this->optimizeImageBinary($binary);

            return $this->uploadToR2($webpBinary, $directory);
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error('processAndUpload failed.', [
                'url' => $rawSourceUrl,
                'directory' => $directory,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException('processAndUpload failed: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Fallback for PDF sources: downloads PDF and converts each page to WebP.
     *
     * Tries `spatie/pdf-to-image` (requires imagick + ghostscript) first,
     * then falls back to native `pdftoppm` (poppler-utils) shell execution.
     *
     * @return array<int, string> List of R2 keys, one per PDF page
     *
     * @throws RuntimeException
     */
    public function processPdfAndUpload(string $rawSourceUrl, string $directory): array
    {
        $tmpPdf = null;
        $tempImages = [];

        try {
            $binary = $this->downloadBinary($rawSourceUrl);

            // Validate PDF magic bytes
            if (! str_starts_with(ltrim($binary), '%PDF')) {
                Log::warning('Downloaded PDF does not start with %PDF header, proceeding anyway.', [
                    'url' => $rawSourceUrl,
                ]);
            }

            // Write PDF to temp file
            $tmpPdf = tempnam(sys_get_temp_dir(), 'aman_pdf_');
            if ($tmpPdf === false) {
                throw new RuntimeException('Failed to create temp file for PDF.');
            }
            $tmpPdf .= '.pdf';
            $written = file_put_contents($tmpPdf, $binary);
            if ($written === false) {
                throw new RuntimeException('Failed to write PDF to temp file: '.$tmpPdf);
            }

            // Strategy 1: spatie/pdf-to-image if available
            if (class_exists(Pdf::class)) {
                try {
                    return $this->processPdfViaSpatie($tmpPdf, $directory, $tempImages);
                } catch (Throwable $e) {
                    Log::warning('Spatie PDF conversion failed, trying pdftoppm fallback.', [
                        'url' => $rawSourceUrl,
                        'error' => $e->getMessage(),
                    ]);
                    // Fall through to pdftoppm
                }
            }

            // Strategy 2: native pdftoppm
            if ($this->isPdftoppmAvailable()) {
                try {
                    return $this->processPdfViaPdftoppm($tmpPdf, $directory, $tempImages);
                } catch (Throwable $e) {
                    Log::error('pdftoppm conversion failed.', [
                        'url' => $rawSourceUrl,
                        'error' => $e->getMessage(),
                    ]);

                    throw new RuntimeException('PDF conversion via pdftoppm failed: '.$e->getMessage(), 0, $e);
                }
            }

            throw new RuntimeException('No PDF conversion backend available (spatie/pdf-to-image missing and pdftoppm not found). Install ext-imagick+ghostscript or poppler-utils.');
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error('processPdfAndUpload failed.', [
                'url' => $rawSourceUrl,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException('PDF processing failed: '.$e->getMessage(), 0, $e);
        } finally {
            // Cleanup temp PDF
            if (is_string($tmpPdf) && file_exists($tmpPdf)) {
                @unlink($tmpPdf);
            }
            // Cleanup any leftover temp images
            foreach ($tempImages as $path) {
                if (file_exists($path)) {
                    @unlink($path);
                }
            }
        }
    }

    /**
     * Convert PDF via spatie/pdf-to-image (each page -> WebP).
     *
     * @param  array<int, string>  $tempImagesCollector  Collects temp paths for cleanup
     * @return array<int, string>
     *
     * @throws Throwable
     */
    private function processPdfViaSpatie(string $pdfPath, string $directory, array &$tempImagesCollector): array
    {
        $pdf = new Pdf($pdfPath);
        $pdf->setResolution(150);

        $numPages = $pdf->getNumberOfPages();
        if ($numPages < 1) {
            throw new RuntimeException('PDF has no pages: '.$pdfPath);
        }

        Log::info('Converting PDF via spatie/pdf-to-image.', [
            'path' => $pdfPath,
            'pages' => $numPages,
        ]);

        $keys = [];

        for ($page = 1; $page <= $numPages; $page++) {
            $tmpImage = sys_get_temp_dir().'/'.Str::ulid()->toString().'-page-'.$page.'.jpg';
            $tempImagesCollector[] = $tmpImage;

            try {
                // Spatie saves page as image; we request JPG for broad compat
                $pdf->setPage($page)->saveImage($tmpImage);

                if (! file_exists($tmpImage)) {
                    throw new RuntimeException('Spatie failed to generate image for page '.$page);
                }

                $binary = (string) file_get_contents($tmpImage);
                if ($binary === '') {
                    throw new RuntimeException('Generated image is empty for page '.$page);
                }

                $webpBinary = $this->optimizeImageBinary($binary);
                $keys[] = $this->uploadToR2($webpBinary, $directory);
            } catch (Throwable $e) {
                Log::error('Failed to process PDF page via Spatie.', [
                    'page' => $page,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            } finally {
                if (file_exists($tmpImage)) {
                    @unlink($tmpImage);
                    // remove from collector to avoid double unlink
                    $tempImagesCollector = array_filter($tempImagesCollector, fn (string $p): bool => $p !== $tmpImage);
                }
            }
        }

        return $keys;
    }

    /**
     * Check if pdftoppm is available on the system.
     */
    private function isPdftoppmAvailable(): bool
    {
        try {
            $process = new Process(['which', 'pdftoppm']);
            // Windows compatibility: `where` on win32, `which` on unix
            if (PHP_OS_FAMILY === 'Windows') {
                $process = new Process(['where', 'pdftoppm']);
            }
            $process->run();
            if ($process->isSuccessful() && trim((string) $process->getOutput()) !== '') {
                return true;
            }

            // Fallback: try `pdftoppm -v` directly
            $probe = new Process(['pdftoppm', '-v']);
            $probe->run();
            if (! $probe->isSuccessful()) {
                return false;
            }
            $output = $probe->getErrorOutput().$probe->getOutput();

            return str_contains(strtolower($output), 'version') || str_contains($output, 'pdftoppm');
        } catch (Throwable $e) {
            Log::warning('pdftoppm availability check failed.', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Convert PDF via native pdftoppm (poppler-utils).
     *
     * @param  array<int, string>  $tempImagesCollector
     * @return array<int, string>
     *
     * @throws Throwable
     */
    private function processPdfViaPdftoppm(string $pdfPath, string $directory, array &$tempImagesCollector): array
    {
        Log::info('Converting PDF via pdftoppm.', [
            'path' => $pdfPath,
        ]);

        $outputPrefix = sys_get_temp_dir().'/'.Str::ulid()->toString().'-pdfpage';

        // Render all pages at 150 DPI to PNG (preserves clarity for price fine print)
        $process = new Process([
            'pdftoppm',
            '-png',
            '-r', '150',
            $pdfPath,
            $outputPrefix,
        ]);
        $process->setTimeout(60);

        try {
            $process->mustRun();
        } catch (ProcessFailedException $e) {
            Log::error('pdftoppm process failed.', [
                'command' => $process->getCommandLine(),
                'error_output' => $process->getErrorOutput(),
            ]);

            throw new RuntimeException('pdftoppm failed: '.$process->getErrorOutput(), 0, $e);
        }

        $pattern = $outputPrefix.'-*.png';
        $generated = glob($pattern);
        if ($generated === false || $generated === []) {
            // Some versions produce without dash: prefix-1.png vs prefix-01.png
            $generated = glob($outputPrefix.'*.png');
        }

        if ($generated === false || $generated === []) {
            throw new RuntimeException('pdftoppm produced no images for PDF: '.$pdfPath);
        }

        sort($generated, SORT_NATURAL);
        $tempImagesCollector = array_merge($tempImagesCollector, $generated);

        $keys = [];

        foreach ($generated as $pngPath) {
            try {
                $binary = (string) file_get_contents($pngPath);
                if ($binary === '') {
                    Log::warning('Skipping empty pdftoppm page.', ['path' => $pngPath]);

                    continue;
                }

                $webpBinary = $this->optimizeImageBinary($binary);
                $keys[] = $this->uploadToR2($webpBinary, $directory);
            } catch (Throwable $e) {
                Log::error('Failed to process pdftoppm page.', [
                    'path' => $pngPath,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            } finally {
                if (file_exists($pngPath)) {
                    @unlink($pngPath);
                }
            }
        }

        // Remove cleaned paths from collector
        $tempImagesCollector = array_filter(
            $tempImagesCollector,
            fn (string $p): bool => ! in_array($p, $generated, true) || file_exists($p)
        );

        if ($keys === []) {
            throw new RuntimeException('No pages were successfully converted from PDF: '.$pdfPath);
        }

        return $keys;
    }
}
