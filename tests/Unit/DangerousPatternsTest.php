<?php

namespace Tests\Unit;

use App\Services\Permissions\DangerousPatterns;
use PHPUnit\Framework\TestCase;

class DangerousPatternsTest extends TestCase
{
    // ─── isCodeExecCommand ────────────────────────────────────────────────

    public function test_python3_is_code_exec(): void
    {
        $this->assertTrue(DangerousPatterns::isCodeExecCommand('python3 script.py'));
    }

    public function test_node_is_code_exec(): void
    {
        $this->assertTrue(DangerousPatterns::isCodeExecCommand('node index.js'));
    }

    public function test_bash_is_code_exec(): void
    {
        $this->assertTrue(DangerousPatterns::isCodeExecCommand('bash install.sh'));
    }

    public function test_sudo_is_code_exec(): void
    {
        $this->assertTrue(DangerousPatterns::isCodeExecCommand('sudo apt-get install curl'));
    }

    public function test_php_is_code_exec(): void
    {
        $this->assertTrue(DangerousPatterns::isCodeExecCommand('php artisan migrate'));
    }

    public function test_npm_run_is_code_exec(): void
    {
        $this->assertTrue(DangerousPatterns::isCodeExecCommand('npm run build'));
    }

    public function test_ls_is_not_code_exec(): void
    {
        $this->assertFalse(DangerousPatterns::isCodeExecCommand('ls -la'));
    }

    public function test_git_status_is_not_code_exec(): void
    {
        $this->assertFalse(DangerousPatterns::isCodeExecCommand('git status'));
    }

    public function test_exact_command_name_without_args_is_code_exec(): void
    {
        $this->assertTrue(DangerousPatterns::isCodeExecCommand('bash'));
    }

    public function test_leading_whitespace_is_handled(): void
    {
        $this->assertTrue(DangerousPatterns::isCodeExecCommand('  python3 foo.py'));
    }

    // ─── checkObfuscation ─────────────────────────────────────────────────

    public function test_command_substitution_dollar_paren_is_flagged(): void
    {
        $result = DangerousPatterns::checkObfuscation('echo $(cat /etc/passwd)');
        $this->assertNotNull($result);
        $this->assertStringContainsString('substitution', $result);
    }

    public function test_backtick_substitution_is_flagged(): void
    {
        $result = DangerousPatterns::checkObfuscation('echo `whoami`');
        $this->assertNotNull($result);
        $this->assertStringContainsString('Backtick', $result);
    }

    public function test_parameter_expansion_is_flagged(): void
    {
        $result = DangerousPatterns::checkObfuscation('echo ${PATH}');
        $this->assertNotNull($result);
    }

    public function test_clean_command_returns_null(): void
    {
        $this->assertNull(DangerousPatterns::checkObfuscation('ls -la /var/www'));
        $this->assertNull(DangerousPatterns::checkObfuscation('git status'));
        $this->assertNull(DangerousPatterns::checkObfuscation('php artisan migrate'));
    }

    // ─── getBashDangerPatterns ────────────────────────────────────────────

    public function test_rm_rf_matches_danger_pattern(): void
    {
        $patterns = DangerousPatterns::getBashDangerPatterns();
        $matched = false;
        foreach ($patterns as $pattern => $msg) {
            if (preg_match($pattern, 'rm -rf /tmp/dir')) {
                $matched = true;
                break;
            }
        }
        $this->assertTrue($matched, 'rm -rf should match at least one danger pattern');
    }

    public function test_drop_table_matches_danger_pattern(): void
    {
        $patterns = DangerousPatterns::getBashDangerPatterns();
        $matched = false;
        foreach ($patterns as $pattern => $msg) {
            if (preg_match($pattern, 'mysql -e "DROP TABLE users"')) {
                $matched = true;
                break;
            }
        }
        $this->assertTrue($matched);
    }

    public function test_all_danger_patterns_are_valid_regex(): void
    {
        foreach (DangerousPatterns::getBashDangerPatterns() as $pattern => $msg) {
            $this->assertNotFalse(
                @preg_match($pattern, ''),
                "Invalid regex pattern: {$pattern}",
            );
        }
    }

    public function test_eval_is_flagged_as_dangerous(): void
    {
        $patterns = DangerousPatterns::getBashDangerPatterns();
        $matched = false;
        foreach ($patterns as $pattern => $msg) {
            if (preg_match($pattern, 'eval "$payload"')) {
                $matched = true;
                break;
            }
        }
        $this->assertTrue($matched);
    }
}
