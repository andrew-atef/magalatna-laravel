<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$chrome = 'C:\Program Files\Google\Chrome\Application\chrome.exe';
$url = 'https://www.facebook.com/plugins/page.php?href=' . urlencode('https://www.facebook.com/fathallacairomarket') . '&tabs=timeline&width=500&height=800';

$html = \Spatie\Browsershot\Browsershot::url($url)
    ->setChromePath($chrome)
    ->addChromiumArguments(['no-sandbox', 'disable-setuid-sandbox', 'disable-dev-shm-usage', 'disable-gpu', 'blink-settings=imagesEnabled=false'])
    ->timeout(15)
    ->bodyHtml();

echo 'bytes: ' . strlen($html) . "\n";
foreach (['fbid=', 'story_fbid=', 'posts/', 'photo.php', 'fathalla', 'login', 'captcha'] as $needle) {
    echo str_pad($needle, 14) . substr_count($html, $needle) . "\n";
}
file_put_contents(__DIR__ . '/radar-probe.html', $html);
echo "saved radar-probe.html\n";
