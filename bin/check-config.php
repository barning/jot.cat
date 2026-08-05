<?php

declare(strict_types=1);

use Jot\Web\PrivacyPolicy;
use Jot\Web\PublicUrl;

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

fwrite(STDOUT, "Configuration and privacy are complete for {$publicUrl->origin()}.\n");
