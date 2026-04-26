<?php

/*
 | One-off (re-runnable) WebP generator. Walks public/img/ and:
 |   1. Downscales any source PNG/JPG whose longest side exceeds
 |      MAX_DIM (currently 1200px). Print-res masters belong in
 |      docs/, not deployed.
 |   2. Produces a .webp sibling for every .png / .jpg, skipping any
 |      that are already up to date.
 |
 | Run from the repo root:
 |   C:/Users/r/.config/herd/bin/php84/php.exe -d memory_limit=1024M tools/build-webp.php
 |
 | Quality 85 keeps icons visually identical at typical render sizes
 | (16-48px) while halving bytes vs the source PNGs.
 */

declare(strict_types=1);

$root = realpath(__DIR__ . '/../public/img');
if ($root === false) {
    fwrite(STDERR, "public/img not found\n");
    exit(1);
}

if (! function_exists('imagewebp')) {
    fwrite(STDERR, "GD WebP support missing in this PHP build\n");
    exit(1);
}

const MAX_DIM = 1200;

$quality = 85;
$created = 0;
$skipped = 0;
$errors = 0;
$resized = 0;

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

foreach ($it as $file) {
    if (! $file->isFile()) {
        continue;
    }
    $ext = strtolower($file->getExtension());
    if (! in_array($ext, ['png', 'jpg', 'jpeg'], true)) {
        continue;
    }

    $src = $file->getPathname();
    $out = preg_replace('/\.(png|jpe?g)$/i', '.webp', $src);

    if (is_file($out) && filemtime($out) >= filemtime($src)) {
        $skipped++;
        continue;
    }

    [$srcW, $srcH] = getimagesize($src);
    $maxSide = max($srcW, $srcH);

    if ($maxSide > MAX_DIM) {
        $scale = MAX_DIM / $maxSide;
        $dstW = (int) round($srcW * $scale);
        $dstH = (int) round($srcH * $scale);

        $orig = match ($ext) {
            'png' => @imagecreatefrompng($src),
            'jpg', 'jpeg' => @imagecreatefromjpeg($src),
        };
        if ($orig === false) {
            fwrite(STDERR, "  ! failed to read $src\n");
            $errors++;
            continue;
        }

        $im = imagecreatetruecolor($dstW, $dstH);
        if ($ext === 'png') {
            imagealphablending($im, false);
            imagesavealpha($im, true);
            $transparent = imagecolorallocatealpha($im, 0, 0, 0, 127);
            imagefill($im, 0, 0, $transparent);
        }
        imagecopyresampled($im, $orig, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);
        imagedestroy($orig);

        if ($ext === 'png') {
            imagepng($im, $src, 9);
        } else {
            imagejpeg($im, $src, 88);
        }
        $resized++;
        echo "  resized $src ($srcW x $srcH -> $dstW x $dstH)\n";
    } else {
        $im = match ($ext) {
            'png' => @imagecreatefrompng($src),
            'jpg', 'jpeg' => @imagecreatefromjpeg($src),
        };
        if ($im === false) {
            fwrite(STDERR, "  ! failed to read $src\n");
            $errors++;
            continue;
        }
        if ($ext === 'png') {
            imagepalettetotruecolor($im);
            imagealphablending($im, false);
            imagesavealpha($im, true);
        }
    }

    if (! @imagewebp($im, $out, $quality)) {
        fwrite(STDERR, "  ! failed to write $out\n");
        $errors++;
    } else {
        $created++;
    }

    imagedestroy($im);
}

echo "WebP build complete. created=$created skipped=$skipped resized=$resized errors=$errors\n";
exit($errors > 0 ? 1 : 0);
