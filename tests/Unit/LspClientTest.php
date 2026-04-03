<?php

namespace Tests\Unit;

use App\Services\Lsp\LspClient;
use PHPUnit\Framework\TestCase;

class LspClientTest extends TestCase
{
    // ─── detectLanguage ───────────────────────────────────────────────────

    public function test_ts_extension_detects_typescript(): void
    {
        $this->assertSame('typescript', LspClient::detectLanguage('/src/app.ts'));
    }

    public function test_tsx_extension_detects_typescript(): void
    {
        $this->assertSame('typescript', LspClient::detectLanguage('/src/Component.tsx'));
    }

    public function test_js_extension_detects_javascript(): void
    {
        $this->assertSame('javascript', LspClient::detectLanguage('/src/app.js'));
    }

    public function test_jsx_extension_detects_javascript(): void
    {
        $this->assertSame('javascript', LspClient::detectLanguage('/src/App.jsx'));
    }

    public function test_mjs_extension_detects_javascript(): void
    {
        $this->assertSame('javascript', LspClient::detectLanguage('/src/module.mjs'));
    }

    public function test_php_extension_detects_php(): void
    {
        $this->assertSame('php', LspClient::detectLanguage('/app/Controller.php'));
    }

    public function test_py_extension_detects_python(): void
    {
        $this->assertSame('python', LspClient::detectLanguage('/script.py'));
    }

    public function test_pyi_extension_detects_python(): void
    {
        $this->assertSame('python', LspClient::detectLanguage('/stubs.pyi'));
    }

    public function test_go_extension_detects_go(): void
    {
        $this->assertSame('go', LspClient::detectLanguage('/main.go'));
    }

    public function test_rs_extension_detects_rust(): void
    {
        $this->assertSame('rust', LspClient::detectLanguage('/src/lib.rs'));
    }

    public function test_java_extension_detects_java(): void
    {
        $this->assertSame('java', LspClient::detectLanguage('/Main.java'));
    }

    public function test_c_extension_detects_c(): void
    {
        $this->assertSame('c', LspClient::detectLanguage('/main.c'));
    }

    public function test_h_extension_detects_c(): void
    {
        $this->assertSame('c', LspClient::detectLanguage('/header.h'));
    }

    public function test_cpp_extension_detects_cpp(): void
    {
        $this->assertSame('cpp', LspClient::detectLanguage('/main.cpp'));
    }

    public function test_unknown_extension_returns_unknown(): void
    {
        $this->assertSame('unknown', LspClient::detectLanguage('/file.xyz'));
    }

    public function test_no_extension_returns_unknown(): void
    {
        $this->assertSame('unknown', LspClient::detectLanguage('/Makefile'));
    }
}
