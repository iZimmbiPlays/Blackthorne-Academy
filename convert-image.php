<?php
declare(strict_types=1);

/**
 * Blackthorne Academy
 * Temporary static image -> WebP conversion utility
 *
 * Scans assets/images recursively and creates a .webp beside every
 * supported PNG/JPG/JPEG source image when the WebP is missing or older.
 *
 * IMPORTANT: Delete this file from the server after conversion is complete.
 */

const WEBP_QUALITY = 85;

$imageRoot = __DIR__ . '/assets/images';
$results = [];
$summary = [
    'created' => 0,
    'updated' => 0,
    'skipped' => 0,
    'failed' => 0,
];

function relativeImagePath(string $absolutePath, string $root): string
{
    $relative = ltrim(str_replace('\\', '/', substr($absolutePath, strlen($root))), '/');
    return 'assets/images/' . $relative;
}

function loadSourceImage(string $path, string $extension)
{
    return match ($extension) {
        'png' => function_exists('imagecreatefrompng') ? @imagecreatefrompng($path) : false,
        'jpg', 'jpeg' => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($path) : false,
        default => false,
    };
}

function prepareImageForWebP($image, string $extension): void
{
    if ($extension === 'png') {
        imagealphablending($image, true);
        imagesavealpha($image, true);
    }
}

function formatBytes(int|false $bytes): string
{
    if ($bytes === false || $bytes < 0) {
        return 'Unknown';
    }

    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    }

    return number_format($bytes / 1024, 2) . ' KB';
}

$environmentError = null;

if (!is_dir($imageRoot)) {
    $environmentError = 'The assets/images directory was not found.';
} elseif (!is_readable($imageRoot)) {
    $environmentError = 'The assets/images directory is not readable.';
} elseif (!extension_loaded('gd')) {
    $environmentError = 'The GD extension is not available on this server.';
} elseif (!function_exists('imagewebp')) {
    $environmentError = 'WebP support is not available in GD on this server.';
}

$didRun = $_SERVER['REQUEST_METHOD'] === 'POST';

if ($didRun && $environmentError === null) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($imageRoot, FilesystemIterator::SKIP_DOTS)
    );

    $sourceFiles = [];

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile()) {
            continue;
        }

        $extension = strtolower($fileInfo->getExtension());

        if (!in_array($extension, ['png', 'jpg', 'jpeg'], true)) {
            continue;
        }

        $sourceFiles[] = $fileInfo->getPathname();
    }

    sort($sourceFiles, SORT_NATURAL | SORT_FLAG_CASE);

    foreach ($sourceFiles as $sourcePath) {
        $extension = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
        $outputPath = substr($sourcePath, 0, -(strlen($extension) + 1)) . '.webp';

        $sourceRelative = relativeImagePath($sourcePath, __DIR__);
        $outputRelative = relativeImagePath($outputPath, __DIR__);

        $webpExists = is_file($outputPath);
        $sourceMtime = @filemtime($sourcePath);
        $webpMtime = $webpExists ? @filemtime($outputPath) : false;

        if (
            $webpExists
            && $sourceMtime !== false
            && $webpMtime !== false
            && $webpMtime >= $sourceMtime
        ) {
            $summary['skipped']++;
            $results[] = [
                'status' => 'skipped',
                'source' => $sourceRelative,
                'output' => $outputRelative,
                'message' => 'Current WebP already exists.',
                'original_size' => formatBytes(@filesize($sourcePath)),
                'webp_size' => formatBytes(@filesize($outputPath)),
            ];
            continue;
        }

        if (!is_readable($sourcePath)) {
            $summary['failed']++;
            $results[] = [
                'status' => 'failed',
                'source' => $sourceRelative,
                'output' => $outputRelative,
                'message' => 'Source file is not readable.',
                'original_size' => formatBytes(@filesize($sourcePath)),
                'webp_size' => '—',
            ];
            continue;
        }

        if (!is_writable(dirname($outputPath))) {
            $summary['failed']++;
            $results[] = [
                'status' => 'failed',
                'source' => $sourceRelative,
                'output' => $outputRelative,
                'message' => 'Destination directory is not writable.',
                'original_size' => formatBytes(@filesize($sourcePath)),
                'webp_size' => '—',
            ];
            continue;
        }

        $image = loadSourceImage($sourcePath, $extension);

        if ($image === false) {
            $summary['failed']++;
            $results[] = [
                'status' => 'failed',
                'source' => $sourceRelative,
                'output' => $outputRelative,
                'message' => 'The source image could not be opened by GD.',
                'original_size' => formatBytes(@filesize($sourcePath)),
                'webp_size' => '—',
            ];
            continue;
        }

        prepareImageForWebP($image, $extension);

        $wasExisting = $webpExists;
        $converted = @imagewebp($image, $outputPath, WEBP_QUALITY);
        imagedestroy($image);

        clearstatcache(true, $outputPath);

        if ($converted && is_file($outputPath) && @filesize($outputPath) > 0) {
            $status = $wasExisting ? 'updated' : 'created';
            $summary[$status]++;

            $results[] = [
                'status' => $status,
                'source' => $sourceRelative,
                'output' => $outputRelative,
                'message' => $wasExisting ? 'Outdated WebP was regenerated.' : 'WebP created successfully.',
                'original_size' => formatBytes(@filesize($sourcePath)),
                'webp_size' => formatBytes(@filesize($outputPath)),
            ];
        } else {
            if (!$wasExisting && is_file($outputPath)) {
                @unlink($outputPath);
            }

            $summary['failed']++;
            $results[] = [
                'status' => 'failed',
                'source' => $sourceRelative,
                'output' => $outputRelative,
                'message' => 'WebP conversion failed.',
                'original_size' => formatBytes(@filesize($sourcePath)),
                'webp_size' => '—',
            ];
        }
    }
}

$totalProcessed = array_sum($summary);
?>
<!DOCTYPE html>
<html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="robots" content="noindex, nofollow, noarchive">
        <title>Blackthorne Academy Image Converter</title>

        <style>
            html {
                color-scheme: dark;
            }

            * {
                box-sizing: border-box;
            }

            body {
                margin: 0;
                min-height: 100vh;
                padding: 2rem;
                background: #120d13;
                color: #f2e9dc;
                font-family: Georgia, "Times New Roman", serif;
            }

            .converter {
                width: min(1000px, 100%);
                margin: 0 auto;
                padding: 2rem;
                border: 1px solid #9b7a3e;
                border-radius: 10px;
                background: #211621;
                box-shadow: 0 20px 60px rgba(0, 0, 0, 0.45);
            }

            h1,
            h2 {
                color: #d4b06a;
            }

            h1 {
                margin-top: 0;
            }

            p {
                line-height: 1.65;
            }

            .status,
            .warning,
            .summary {
                margin: 1.25rem 0;
                padding: 1rem;
                border-radius: 6px;
            }

            .status-error {
                background: #3b191d;
                border: 1px solid #a84e58;
            }

            .warning {
                background: #2b2119;
                border: 1px solid #8f713b;
                color: #e2d0ae;
            }

            .summary {
                display: grid;
                grid-template-columns: repeat(4, minmax(0, 1fr));
                gap: 0.75rem;
                background: #171018;
                border: 1px solid #5d465d;
            }

            .summary-item {
                padding: 0.85rem;
                border: 1px solid #4b394b;
                border-radius: 5px;
                text-align: center;
            }

            .summary-item strong {
                display: block;
                margin-bottom: 0.25rem;
                color: #d4b06a;
                font-size: 1.45rem;
            }

            button {
                display: inline-block;
                padding: 0.85rem 1.25rem;
                border: 1px solid #b18b49;
                border-radius: 5px;
                background: #4a2f49;
                color: #fff7e8;
                font: inherit;
                font-weight: 700;
                cursor: pointer;
            }

            button:hover,
            button:focus-visible {
                background: #5a3958;
            }

            table {
                width: 100%;
                margin-top: 1rem;
                border-collapse: collapse;
                font-family: Arial, Helvetica, sans-serif;
                font-size: 0.92rem;
            }

            th,
            td {
                padding: 0.75rem;
                border: 1px solid #4b394b;
                text-align: left;
                vertical-align: top;
            }

            th {
                color: #d4b06a;
                background: #171018;
            }

            code {
                color: #e2c17e;
                overflow-wrap: anywhere;
            }

            .result-created,
            .result-updated {
                color: #8fd3a8;
            }

            .result-skipped {
                color: #c9b987;
            }

            .result-failed {
                color: #e58e98;
            }

            @media (max-width: 760px) {
                body {
                    padding: 1rem;
                }

                .converter {
                    padding: 1.25rem;
                }

                .summary {
                    grid-template-columns: repeat(2, minmax(0, 1fr));
                }

                .table-wrap {
                    overflow-x: auto;
                }
            }

        </style>
    </head>

    <body>
        <div class="converter">
            <h1>Blackthorne Academy</h1>
            <h2>Static Image WebP Converter</h2>

            <p>
                This temporary utility scans <code>assets/images/</code> and its subfolders.
                It creates a WebP copy beside each PNG, JPG, or JPEG image while preserving
                the original file as the fallback.
            </p>

            <?php if ($environmentError !== null): ?>
            <div class="status status-error">
                <?= htmlspecialchars($environmentError, ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <?php elseif (!$didRun): ?>
            <form method="post">
                <button type="submit">Generate / Update WebP Images</button>
            </form>
            <?php else: ?>
            <div class="summary">
                <div class="summary-item">
                    <strong><?= $summary['created']; ?></strong>
                    Created
                </div>
                <div class="summary-item">
                    <strong><?= $summary['updated']; ?></strong>
                    Updated
                </div>
                <div class="summary-item">
                    <strong><?= $summary['skipped']; ?></strong>
                    Current / Skipped
                </div>
                <div class="summary-item">
                    <strong><?= $summary['failed']; ?></strong>
                    Failed
                </div>
            </div>

            <?php if ($totalProcessed === 0): ?>
            <div class="status status-error">
                No supported PNG, JPG, or JPEG files were found under <code>assets/images/</code>.
            </div>
            <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Status</th>
                            <th>Source</th>
                            <th>WebP</th>
                            <th>Original</th>
                            <th>WebP Size</th>
                            <th>Result</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results as $result): ?>
                        <tr>
                            <td class="result-<?= htmlspecialchars($result['status'], ENT_QUOTES, 'UTF-8'); ?>">
                                <?= htmlspecialchars(ucfirst($result['status']), ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                            <td><code><?= htmlspecialchars($result['source'], ENT_QUOTES, 'UTF-8'); ?></code></td>
                            <td><code><?= htmlspecialchars($result['output'], ENT_QUOTES, 'UTF-8'); ?></code></td>
                            <td><?= htmlspecialchars($result['original_size'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?= htmlspecialchars($result['webp_size'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?= htmlspecialchars($result['message'], ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <p>
                You can run the converter again if needed. Images with current WebP copies will be skipped.
            </p>
            <?php endif; ?>

            <div class="warning">
                <strong>Important:</strong> Delete <code>convert-image.php</code> from the server after you confirm the
                conversions are complete.
            </div>
        </div>
    </body>

</html>
