<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PredictionViewerEnvParsingTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (! function_exists('loadLocalEnv')) {
            ob_start();
            require_once dirname(__DIR__, 2).'/public/view-prediction-response.php';
            ob_end_clean();
        }
    }

    public function test_viewer_env_parser_reads_unquoted_app_key(): void
    {
        $path = $this->tempEnv("APP_KEY=base64:abc123\n");

        $this->assertSame('base64:abc123', loadLocalEnv($path)['APP_KEY'] ?? null);
        $this->assertTrue(envHasAppKey($path));
    }

    public function test_viewer_env_parser_reads_double_quoted_app_key(): void
    {
        $path = $this->tempEnv("APP_KEY=\"base64:abc123\"\n");

        $this->assertSame('base64:abc123', loadLocalEnv($path)['APP_KEY'] ?? null);
        $this->assertTrue(envHasAppKey($path));
    }

    public function test_viewer_env_parser_reads_single_quoted_app_key(): void
    {
        $path = $this->tempEnv("APP_KEY='base64:abc123'\n");

        $this->assertSame('base64:abc123', loadLocalEnv($path)['APP_KEY'] ?? null);
        $this->assertTrue(envHasAppKey($path));
    }

    public function test_viewer_env_parser_reads_app_key_with_spaces_around_separator(): void
    {
        $path = $this->tempEnv("APP_KEY = \"base64:abc123\"\n");

        $this->assertSame('base64:abc123', loadLocalEnv($path)['APP_KEY'] ?? null);
        $this->assertTrue(envHasAppKey($path));
    }

    public function test_viewer_env_parser_reads_unquoted_app_key_with_spaces_around_separator(): void
    {
        $path = $this->tempEnv("APP_KEY = base64:abc123\n");

        $this->assertSame('base64:abc123', loadLocalEnv($path)['APP_KEY'] ?? null);
        $this->assertTrue(envHasAppKey($path));
    }

    public function test_viewer_env_parser_ignores_blank_lines_and_comments(): void
    {
        $path = $this->tempEnv("\n# APP_KEY=base64:ignored\nAPP_KEY='base64:abc123'\n");

        $this->assertSame('base64:abc123', loadLocalEnv($path)['APP_KEY'] ?? null);
        $this->assertTrue(envHasAppKey($path));
    }

    public function test_viewer_env_parser_reads_app_key_after_utf8_bom(): void
    {
        $path = $this->tempEnv("\xEF\xBB\xBFAPP_KEY=base64:abc123\r\n");

        $this->assertSame('base64:abc123', loadLocalEnv($path)['APP_KEY'] ?? null);
        $this->assertTrue(envHasAppKey($path));
    }

    public function test_viewer_env_parser_reads_exported_app_key(): void
    {
        $path = $this->tempEnv("export APP_KEY=\"base64:abc123\"\n");

        $this->assertSame('base64:abc123', loadLocalEnv($path)['APP_KEY'] ?? null);
        $this->assertTrue(envHasAppKey($path));
    }

    public function test_viewer_env_diagnostics_do_not_include_full_paths(): void
    {
        $path = $this->tempEnv("APP_KEY = \"base64:abc123\"\n");
        $diagnostics = viewerEnvDiagnostics($path);

        $this->assertSame(basename(dirname($path)).'/'.basename($path), $diagnostics['expected_env_path_basename']);
        $this->assertTrue($diagnostics['expected_env_exists']);
        $this->assertTrue($diagnostics['expected_env_is_file']);
        $this->assertTrue($diagnostics['expected_env_readable']);
        $this->assertTrue($diagnostics['env_has_app_key']);
        $this->assertTrue($diagnostics['env_parse_succeeded']);
        $this->assertTrue($diagnostics['app_key_line_found']);
        $this->assertSame(1, $diagnostics['app_key_line_number']);
        $this->assertFalse($diagnostics['app_key_name_exact_match']);
        $this->assertTrue($diagnostics['app_key_has_spaces_before_equals']);
        $this->assertTrue($diagnostics['app_key_value_quoted']);
        $this->assertSame(strlen('base64:abc123'), $diagnostics['app_key_value_length']);
        $this->assertTrue($diagnostics['app_key_has_base64_prefix']);
    }

    private function tempEnv(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'viewer-env-');
        file_put_contents($path, $contents);

        return $path;
    }
}
