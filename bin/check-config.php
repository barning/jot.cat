<?php

declare(strict_types=1);

use Jot\Web\PrivacyPolicy;

require dirname(__DIR__) . '/src/Autoload.php';

$policy = PrivacyPolicy::fromEnvironment();
if (!$policy->isComplete()) {
    fwrite(STDERR, "Missing or invalid privacy configuration:\n");
    foreach ($policy->missing() as $key) {
        fwrite(STDERR, "- {$key}\n");
    }
    exit(1);
}

fwrite(STDOUT, "Privacy configuration is complete.\n");
