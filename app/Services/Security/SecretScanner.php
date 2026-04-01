<?php

namespace App\Services\Security;

/**
 * Regex-based secret scanner using high-confidence patterns (inspired by gitleaks).
 * Detects API keys, tokens, and credentials in content to prevent accidental exposure.
 */
class SecretScanner
{
    /**
     * High-confidence secret patterns with capture groups for the secret value.
     * Each pattern targets a specific credential type with low false-positive rates.
     */
    private const PATTERNS = [
        // AWS Access Key ID (starts with AKIA)
        '/(AKIA[0-9A-Z]{16})/' => 'AWS Access Key',

        // AWS Secret Access Key (40-char base64)
        '/(?<=aws_secret_access_key\s*=\s*)([A-Za-z0-9\/+=]{40})/' => 'AWS Secret Key',

        // Anthropic API Key (sk-ant-...)
        '/(sk-ant-api03-[A-Za-z0-9\-_]{80,})/' => 'Anthropic API Key',

        // Generic Anthropic key
        '/(sk-ant-[A-Za-z0-9\-_]{20,})/' => 'Anthropic Key',

        // OpenAI API Key (sk-...)
        '/(sk-[A-Za-z0-9]{20}T3BlbkFJ[A-Za-z0-9]{20,})/' => 'OpenAI API Key',

        // GitHub Personal Access Token (classic)
        '/(ghp_[A-Za-z0-9]{36})/' => 'GitHub PAT',

        // GitHub OAuth Access Token
        '/(gho_[A-Za-z0-9]{36})/' => 'GitHub OAuth Token',

        // GitHub Fine-Grained PAT
        '/(github_pat_[A-Za-z0-9_]{82})/' => 'GitHub Fine-Grained PAT',

        // Google API Key
        '/(AIza[0-9A-Za-z\-_]{35})/' => 'Google API Key',

        // Google OAuth Access Token
        '/(ya29\.[0-9A-Za-z\-_]+)/' => 'Google OAuth Token',

        // Slack Token
        '/(xox[baprs]-[0-9]{10,13}-[0-9]{10,13}-[a-zA-Z0-9]{24,34})/' => 'Slack Token',

        // Stripe Secret Key
        '/(sk_live_[0-9a-zA-Z]{24})/' => 'Stripe Secret Key',

        // Stripe Publishable Key
        '/(pk_live_[0-9a-zA-Z]{24})/' => 'Stripe Publishable Key',

        // Private Key (PEM format)
        '/-----BEGIN (?:RSA |EC |DSA |OPENSSH )?PRIVATE KEY-----/' => 'Private Key',

        // Heroku API Key
        '/(?<=heroku_api_key\s*=\s*)([0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})/' => 'Heroku API Key',

        // Generic Bearer Token in Authorization header
        '/(?<=Authorization:\s*Bearer\s)([A-Za-z0-9\-_.~+/]+=*)/' => 'Bearer Token',

        // Telegram Bot Token
        '/([0-9]{8,10}:[A-Za-z0-9_-]{35})/' => 'Telegram Bot Token',

        // Discord Bot Token
        '/([MN][A-Za-z\d]{23,}\.[\w-]{6}\.[\w-]{27})/' => 'Discord Bot Token',
    ];

    /**
     * Scan content for secrets.
     *
     * @return array<array{type: string, match: string, pattern: string}>
     */
    public function scan(string $content): array
    {
        $findings = [];

        foreach (self::PATTERNS as $pattern => $type) {
            if (preg_match($pattern, $content, $matches)) {
                $findings[] = [
                    'type' => $type,
                    'match' => $this->truncateSecret($matches[0]),
                    'pattern' => $type,
                ];
            }
        }

        return $findings;
    }

    /**
     * Check if content contains any secrets.
     */
    public function containsSecrets(string $content): bool
    {
        foreach (self::PATTERNS as $pattern => $_) {
            if (preg_match($pattern, $content)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Redact secrets in content, replacing them with [REDACTED_<type>].
     */
    public function redact(string $content): string
    {
        foreach (self::PATTERNS as $pattern => $type) {
            $content = preg_replace(
                $pattern,
                '[REDACTED_' . str_replace(' ', '_', strtoupper($type)) . ']',
                $content,
            );
        }
        return $content;
    }

    /**
     * Truncate a secret match for safe display (show first 8 chars + ...).
     */
    private function truncateSecret(string $secret): string
    {
        if (mb_strlen($secret) <= 12) {
            return $secret;
        }
        return mb_substr($secret, 0, 8) . '...' . mb_substr($secret, -4);
    }
}
