<?php

declare(strict_types=1);

use Jot\Web\PrivacyPolicy;
use Jot\Web\PublicUrl;
use Jot\Web\UmamiConfig;

require dirname(__DIR__) . '/src/Autoload.php';

try {
    $publicUrl = PublicUrl::fromEnvironment();
} catch (InvalidArgumentException $error) {
    fwrite(STDERR, "Invalid JOT_BASE_URL: {$error->getMessage()}\n");
    exit(1);
}

$policy = PrivacyPolicy::fromEnvironment();
if (!$policy->isComplete()) {
    fwrite(STDERR, "Missing or invalid privacy configuration:\n");
    foreach ($policy->missing() as $key) {
        fwrite(STDERR, "- {$key}\n");
    }
    exit(1);
}

$umami = UmamiConfig::fromEnvironment();
if (!$umami->isEnabled()) {
    $message = $umami->isMissing()
        ? 'JOT_UMAMI_WEBSITE_ID is required.'
        : (string) $umami->error();
    fwrite(STDERR, "Invalid analytics configuration: {$message}\n");
    exit(1);
}

fwrite(STDOUT, "Configuration, privacy, and Umami analytics are complete for {$publicUrl->origin()}.\n");
