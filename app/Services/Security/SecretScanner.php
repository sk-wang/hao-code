<?php

namespace App\Services\Security;

/**
 * Regex-based secret scanner using high-confidence patterns (inspired by gitleaks).
 * Detects API keys, tokens, and credentials in content to prevent accidental exposure.
 */
class SecretScanner
{
    /**
     * High-confidence secret patterns ported from claude-code's secretScanner.ts
     * (itself adapted from gitleaks). Each pattern targets a specific credential
     * type with low false-positive rates.
     *
     * Patterns are grouped by category: Cloud, AI APIs, Version Control,
     * Communication, Dev Tools, Observability, Payment, Crypto.
     */
    private const PATTERNS = [
        // ── Cloud ────────────────────────────────────────────────────────────

        // AWS Access Key ID (AKIA / ASIA / ABIA / ACCA prefix)
        '/\b((?:A3T[A-Z0-9]|AKIA|ASIA|ABIA|ACCA)[A-Z2-7]{16})\b/' => 'AWS Access Key',

        // AWS Secret Access Key (40-char base64)
        '/(?<=aws_secret_access_key\s*=\s*)([A-Za-z0-9\/+=]{40})/' => 'AWS Secret Key',

        // GCP API Key
        '/\b(AIza[\w-]{35})/' => 'Google API Key',

        // Google OAuth Token
        '/(ya29\.[0-9A-Za-z\-_]+)/' => 'Google OAuth Token',

        // Azure AD Client Secret (e.g. ~Q.8Q~xxxx)
        '/(?:^|[\'"`\s>=:(,)])([a-zA-Z0-9_~.]{3}\dQ~[a-zA-Z0-9_~.\-]{31,34})(?:$|[\'"`\s<),])/' => 'Azure AD Client Secret',

        // DigitalOcean Personal Access Token
        '/\b(dop_v1_[a-f0-9]{64})/' => 'DigitalOcean PAT',

        // DigitalOcean Access Token (OAuth)
        '/\b(doo_v1_[a-f0-9]{64})/' => 'DigitalOcean Access Token',

        // ── AI APIs ──────────────────────────────────────────────────────────

        // Anthropic API Key (sk-ant-api03-...)
        '/\b(sk-ant-api03-[a-zA-Z0-9_\-]{93}AA)/' => 'Anthropic API Key',

        // Anthropic Admin API Key
        '/\b(sk-ant-admin01-[a-zA-Z0-9_\-]{93}AA)/' => 'Anthropic Admin API Key',

        // Generic Anthropic key (fallback)
        '/(sk-ant-[A-Za-z0-9\-_]{20,})/' => 'Anthropic Key',

        // OpenAI API Key
        '/\b(sk-(?:proj|svcacct|admin)-[A-Za-z0-9_\-]{74}T3BlbkFJ[A-Za-z0-9_\-]{74}|sk-[a-zA-Z0-9]{20}T3BlbkFJ[a-zA-Z0-9]{20})/' => 'OpenAI API Key',

        // HuggingFace Access Token
        '/\b(hf_[a-zA-Z]{34})/' => 'HuggingFace Token',

        // ── Version Control ───────────────────────────────────────────────────

        // GitHub Personal Access Token (classic ghp_)
        '/(ghp_[0-9a-zA-Z]{36})/' => 'GitHub PAT',

        // GitHub Fine-Grained PAT
        '/(github_pat_\w{82})/' => 'GitHub Fine-Grained PAT',

        // GitHub App Token (ghu_ / ghs_)
        '/((?:ghu|ghs)_[0-9a-zA-Z]{36})/' => 'GitHub App Token',

        // GitHub OAuth Token
        '/(gho_[0-9a-zA-Z]{36})/' => 'GitHub OAuth Token',

        // GitHub Refresh Token
        '/(ghr_[0-9a-zA-Z]{36})/' => 'GitHub Refresh Token',

        // GitLab Personal Access Token
        '/(glpat-[\w\-]{20})/' => 'GitLab PAT',

        // GitLab Deploy Token
        '/(gldt-[0-9a-zA-Z_\-]{20})/' => 'GitLab Deploy Token',

        // ── Communication ────────────────────────────────────────────────────

        // Slack Bot Token
        '/(xoxb-[0-9]{10,13}-[0-9]{10,13}[a-zA-Z0-9-]*)/' => 'Slack Bot Token',

        // Slack User Token
        '/(xox[pe](?:-[0-9]{10,13}){3}-[a-zA-Z0-9\-]{28,34})/' => 'Slack User Token',

        // Slack App-Level Token
        '/(xapp-\d-[A-Z0-9]+-\d+-[a-z0-9]+)/i' => 'Slack App Token',

        // Twilio API Key
        '/(SK[0-9a-fA-F]{32})/' => 'Twilio API Key',

        // SendGrid API Token
        '/\b(SG\.[a-zA-Z0-9=_\-.]{66})/' => 'SendGrid API Token',

        // Telegram Bot Token
        '/([0-9]{8,10}:[A-Za-z0-9_-]{35})/' => 'Telegram Bot Token',

        // Discord Bot Token
        '/([MN][A-Za-z\d]{23,}\.[\w-]{6}\.[\w-]{27})/' => 'Discord Bot Token',

        // ── Dev Tools ────────────────────────────────────────────────────────

        // NPM Access Token
        '/\b(npm_[a-zA-Z0-9]{36})/' => 'NPM Access Token',

        // PyPI Upload Token
        '/(pypi-AgEIcHlwaS5vcmc[\w\-]{50,1000})/' => 'PyPI Upload Token',

        // Databricks API Token
        '/\b(dapi[a-f0-9]{32}(?:-\d)?)/' => 'Databricks API Token',

        // HashiCorp Terraform API Token
        '/([a-zA-Z0-9]{14}\.atlasv1\.[a-zA-Z0-9\-_=]{60,70})/' => 'HashiCorp TF Token',

        // Pulumi API Token
        '/\b(pul-[a-f0-9]{40})/' => 'Pulumi API Token',

        // Postman API Token
        '/\b(PMAK-[a-f0-9]{24}-[a-f0-9]{34})/' => 'Postman API Token',

        // Heroku API Key
        '/(?<=heroku_api_key\s*=\s*)([0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})/' => 'Heroku API Key',

        // ── Observability ────────────────────────────────────────────────────

        // Grafana API Key (eyJr... base64-encoded)
        '/\b(eyJrIjoi[A-Za-z0-9+\/]{50,500}={0,3})/' => 'Grafana API Key',

        // Grafana Cloud API Token
        '/\b(glc_[A-Za-z0-9+\/]{32,400}={0,3})/' => 'Grafana Cloud Token',

        // Grafana Service Account Token
        '/\b(glsa_[A-Za-z0-9]{32}_[A-Fa-f0-9]{8})/' => 'Grafana Service Account Token',

        // Sentry User Auth Token
        '/\b(sntryu_[a-f0-9]{64})/' => 'Sentry User Token',

        // Sentry Org Auth Token
        '/\b(sntrys_[a-f0-9]{64})/' => 'Sentry Org Token',

        // ── Payment ──────────────────────────────────────────────────────────

        // Stripe Secret Key
        '/(sk_live_[0-9a-zA-Z]{24})/' => 'Stripe Secret Key',

        // Stripe Publishable Key
        '/(pk_live_[0-9a-zA-Z]{24})/' => 'Stripe Publishable Key',

        // Shopify Access Token
        '/(shpat_[a-fA-F0-9]{32})/' => 'Shopify Access Token',

        // Shopify Shared Secret
        '/(shpss_[a-fA-F0-9]{32})/' => 'Shopify Shared Secret',

        // ── Crypto & Generic ─────────────────────────────────────────────────

        // Private Key (PEM format — RSA, EC, DSA, OPENSSH, generic)
        '/-----BEGIN (?:RSA |EC |DSA |OPENSSH )?PRIVATE KEY-----/' => 'Private Key',

        // Generic Bearer Token in Authorization header
        '/(?<=Authorization:\s*Bearer\s)([A-Za-z0-9\-_.~+\/]+=*)/' => 'Bearer Token',
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
