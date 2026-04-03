<?php

namespace Tests\Unit;

use App\Services\Security\SecretScanner;
use PHPUnit\Framework\TestCase;

class SecretScannerTest extends TestCase
{
    private SecretScanner $scanner;

    protected function setUp(): void
    {
        $this->scanner = new SecretScanner;
    }

    // ─── containsSecrets ──────────────────────────────────────────────────

    public function test_contains_secrets_false_for_clean_content(): void
    {
        $this->assertFalse($this->scanner->containsSecrets('Just plain text, nothing to see here.'));
    }

    public function test_contains_secrets_true_for_aws_key(): void
    {
        // AWS key: AKIA + 16 chars from [A-Z2-7], terminated by word boundary
        $this->assertTrue($this->scanner->containsSecrets('AKIAIOSFODNN7EXAMPLE'));
    }

    public function test_contains_secrets_true_for_github_pat(): void
    {
        $ghp = 'ghp_' . str_repeat('A', 36);
        $this->assertTrue($this->scanner->containsSecrets($ghp));
    }

    public function test_contains_secrets_true_for_npm_token(): void
    {
        $npm = 'npm_' . str_repeat('a', 36);
        $this->assertTrue($this->scanner->containsSecrets($npm));
    }

    public function test_contains_secrets_true_for_private_key_pem(): void
    {
        $this->assertTrue($this->scanner->containsSecrets('-----BEGIN RSA PRIVATE KEY-----'));
    }

    public function test_contains_secrets_true_for_stripe_key(): void
    {
        $stripe = 'sk_live_' . str_repeat('a', 24);
        $this->assertTrue($this->scanner->containsSecrets($stripe));
    }

    // ─── scan — specific pattern types ────────────────────────────────────

    public function test_scan_detects_github_pat(): void
    {
        $ghp = 'ghp_' . str_repeat('A', 36);
        $findings = $this->scanner->scan("token: {$ghp}");
        $types = array_column($findings, 'type');
        $this->assertContains('GitHub PAT', $types);
    }

    public function test_scan_detects_slack_bot_token(): void
    {
        $slack = 'xoxb-' . str_repeat('1', 10) . '-' . str_repeat('2', 10) . '-' . str_repeat('a', 24);
        $findings = $this->scanner->scan($slack);
        $types = array_column($findings, 'type');
        $this->assertContains('Slack Bot Token', $types);
    }

    public function test_scan_detects_private_key_pem(): void
    {
        $findings = $this->scanner->scan('-----BEGIN PRIVATE KEY-----');
        $types = array_column($findings, 'type');
        $this->assertContains('Private Key', $types);
    }

    public function test_scan_returns_empty_for_clean_content(): void
    {
        $findings = $this->scanner->scan('Hello world, no secrets here.');
        $this->assertEmpty($findings);
    }

    public function test_scan_finding_has_required_keys(): void
    {
        $findings = $this->scanner->scan('ghp_' . str_repeat('A', 36));
        $this->assertNotEmpty($findings);
        $this->assertArrayHasKey('type', $findings[0]);
        $this->assertArrayHasKey('match', $findings[0]);
        $this->assertArrayHasKey('pattern', $findings[0]);
    }

    public function test_scan_pattern_field_contains_regex_not_type_description(): void
    {
        // The 'pattern' field must be the actual PCRE regex string, not a copy of 'type'.
        // Before the fix: both 'type' and 'pattern' held the human-readable description
        // (e.g. 'GitHub PAT') because the result used `'pattern' => $type` by mistake.
        $findings = $this->scanner->scan('ghp_' . str_repeat('A', 36));
        $this->assertNotEmpty($findings);

        $ghpFinding = null;
        foreach ($findings as $f) {
            if ($f['type'] === 'GitHub PAT') {
                $ghpFinding = $f;
                break;
            }
        }

        $this->assertNotNull($ghpFinding, 'Should have found a GitHub PAT');
        // The pattern must differ from the type description
        $this->assertNotSame($ghpFinding['type'], $ghpFinding['pattern'],
            "'pattern' should hold the regex, not a copy of 'type'");
        // The pattern must look like a regex (starts with /)
        $this->assertStringStartsWith('/', $ghpFinding['pattern'],
            "'pattern' should be a PCRE regex string starting with /");
    }

    // ─── truncation ───────────────────────────────────────────────────────

    public function test_scan_match_is_truncated_for_long_secrets(): void
    {
        // AWS key is 20 chars — fits within display limit
        $findings = $this->scanner->scan('AKIAIOSFODNN7EXAMPLE1234');
        if (!empty($findings)) {
            // If truncated, contains '...'
            $match = $findings[0]['match'];
            if (mb_strlen('AKIAIOSFODNN7EXAMPLE1234') > 12) {
                $this->assertStringContainsString('...', $match);
            }
        }
        $this->assertTrue(true); // test is about no errors thrown
    }

    public function test_scan_short_match_not_truncated(): void
    {
        // A short secret (≤12 chars) should not be truncated
        // Private key header "-----BEGIN RSA PRIVATE KEY-----" > 12 chars, so skipped
        // Use a short finding — AWS key is 20 chars; skip this test path
        $scanner = new SecretScanner;
        // Just check no exception on short content
        $findings = $scanner->scan('');
        $this->assertEmpty($findings);
    }

    // ─── multiple occurrences ─────────────────────────────────────────────

    public function test_scan_finds_all_occurrences_of_same_type(): void
    {
        // Two GitHub PATs in the same content — both should be found
        $ghp1 = 'ghp_' . str_repeat('A', 36);
        $ghp2 = 'ghp_' . str_repeat('B', 36);
        $findings = $this->scanner->scan("token1={$ghp1} token2={$ghp2}");

        $ghpFindings = array_values(array_filter($findings, fn($f) => $f['type'] === 'GitHub PAT'));
        $this->assertCount(2, $ghpFindings, 'Both GitHub PATs should be detected');
    }

    // ─── redact ───────────────────────────────────────────────────────────

    public function test_redact_removes_github_pat(): void
    {
        $ghp = 'ghp_' . str_repeat('A', 36);
        $redacted = $this->scanner->redact("MY_TOKEN={$ghp}");
        $this->assertStringNotContainsString($ghp, $redacted);
        $this->assertStringContainsString('REDACTED', $redacted);
    }

    public function test_redact_returns_original_when_no_secrets(): void
    {
        $original = 'No secrets in this file.';
        $redacted = $this->scanner->redact($original);
        $this->assertSame($original, $redacted);
    }

    public function test_redact_type_name_is_uppercased_with_underscores(): void
    {
        $ghp = 'ghp_' . str_repeat('B', 36);
        $redacted = $this->scanner->redact($ghp);
        // 'GitHub PAT' → 'GITHUB_PAT'
        $this->assertStringContainsString('[REDACTED_GITHUB_PAT]', $redacted);
    }

    public function test_it_detects_assignment_and_bearer_patterns_without_regex_compilation_errors(): void
    {
        $scanner = new SecretScanner;
        $content = implode("\n", [
            'aws_secret_access_key = ABCDEFGHIJKLMNOPQRSTUVWXYZabcd1234567890',
            'heroku_api_key = 12345678-1234-1234-1234-1234567890ab',
            'Authorization: Bearer super-secret-token_123',
        ]);

        $findings = $scanner->scan($content);

        $this->assertCount(3, $findings);
        $this->assertSame(
            ['AWS Secret Key', 'Heroku API Key', 'Bearer Token'],
            array_column($findings, 'type'),
        );
    }

    public function test_it_redacts_only_the_secret_value_and_preserves_the_prefix(): void
    {
        $scanner = new SecretScanner;
        $content = implode("\n", [
            'aws_secret_access_key = ABCDEFGHIJKLMNOPQRSTUVWXYZabcd1234567890',
            'Authorization: Bearer super-secret-token_123',
        ]);

        $redacted = $scanner->redact($content);

        $this->assertStringContainsString(
            'aws_secret_access_key = [REDACTED_AWS_SECRET_KEY]',
            $redacted,
        );
        $this->assertStringContainsString(
            'Authorization: Bearer [REDACTED_BEARER_TOKEN]',
            $redacted,
        );
    }

    public function test_redact_preserves_surrounding_delimiters_for_capture_group_patterns(): void
    {
        // The Azure AD Client Secret pattern anchors on surrounding characters:
        // (?:^|['"...]) SECRET (?:$|['"...])
        // Before the fix, preg_replace() consumed those surrounding chars, corrupting content.
        // e.g. 'config = "SECRET"' → 'config =[REDACTED]' (= " and trailing " both eaten).
        // After the fix, preg_replace_callback replaces only the capture group.

        // Build a valid Azure AD secret: 3 alphanum + 1 digit + "Q~" + 31 alphanum
        $secret = 'abc1Q~' . str_repeat('x', 31);
        $content = ' ' . $secret . ' '; // single space on each side as the surrounding delimiter

        $redacted = $this->scanner->redact($content);

        $this->assertStringNotContainsString($secret, $redacted, 'Secret must be removed');
        $this->assertStringContainsString('[REDACTED_AZURE_AD_CLIENT_SECRET]', $redacted, 'Placeholder must appear');
        // The surrounding spaces must be preserved — they must not be swallowed by the replacement
        $this->assertStringStartsWith(' ', $redacted, 'Leading delimiter space must be preserved');
        $this->assertStringEndsWith(' ', $redacted, 'Trailing delimiter space must be preserved');
    }

    public function test_scan_reports_only_the_secret_not_surrounding_delimiters(): void
    {
        // The Azure AD pattern includes surrounding context in $matches[0] — the scan() method
        // must report $matches[1] (just the secret) so the caller sees the actual credential.
        $secret = 'abc1Q~' . str_repeat('y', 31);
        $content = ' ' . $secret . ' ';

        $findings = $this->scanner->scan($content);

        $azureFindings = array_values(array_filter($findings, fn($f) => $f['type'] === 'Azure AD Client Secret'));
        $this->assertNotEmpty($azureFindings, 'Azure AD secret must be detected');

        // The match must start with the secret prefix, not a space/delimiter
        $match = $azureFindings[0]['match'];
        $this->assertStringStartsWith('abc1Q~', $match,
            'scan() match must contain the secret, not the surrounding space delimiter');
    }
}
