<?php

declare(strict_types=1);

namespace Jot\Web;

final class PrivacyPolicy
{
    /** @param array<string, string> $values @param list<string> $missing */
    private function __construct(
        private array $values,
        private array $missing,
    ) {
    }

    public static function fromEnvironment(): self
    {
        $keys = [
            'JOT_PRIVACY_CONTROLLER_NAME',
            'JOT_PRIVACY_CONTROLLER_ADDRESS',
            'JOT_PRIVACY_CONTACT_EMAIL',
            'JOT_PRIVACY_LEGAL_BASIS',
            'JOT_PRIVACY_PROCESSOR',
            'JOT_PRIVACY_THIRD_COUNTRY_TRANSFERS',
            'JOT_PRIVACY_URL_RETENTION',
            'JOT_PRIVACY_LOG_RETENTION',
            'JOT_PRIVACY_SUPERVISORY_AUTHORITY',
            'JOT_PRIVACY_UPDATED_AT',
        ];
        $values = [];
        $missing = [];

        foreach ($keys as $key) {
            $value = getenv($key);
            $value = is_string($value) ? trim($value) : '';
            if ($value === '') {
                $missing[] = $key;
            } else {
                $values[$key] = $value;
            }
        }

        $dataProtectionOfficer = getenv('JOT_PRIVACY_DPO_CONTACT');
        if (is_string($dataProtectionOfficer) && trim($dataProtectionOfficer) !== '') {
            $values['JOT_PRIVACY_DPO_CONTACT'] = trim($dataProtectionOfficer);
        }

        $email = $values['JOT_PRIVACY_CONTACT_EMAIL'] ?? '';
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            unset($values['JOT_PRIVACY_CONTACT_EMAIL']);
            $missing[] = 'JOT_PRIVACY_CONTACT_EMAIL';
        }

        return new self($values, array_values(array_unique($missing)));
    }

    public function isComplete(): bool
    {
        return $this->missing === [];
    }

    /** @return list<string> */
    public function missing(): array
    {
        return $this->missing;
    }

    public function value(string $key): string
    {
        return $this->values[$key] ?? '';
    }
}
