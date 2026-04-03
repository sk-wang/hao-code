<?php

namespace App\Services\Hooks;

class HookExecutor
{
    /** @var array<string, HookDefinition[]> */
    private array $hooks = [];

    public function __construct()
    {
        $this->loadHooks();
    }

    /**
     * Run hooks for a given event.
     *
     * @return HookResult
     */
    public function execute(string $event, array $context = []): HookResult
    {
        $hooks = $this->hooks[$event] ?? [];
        $outputs = [];
        $modifiedInput = null;

        foreach ($hooks as $hook) {
            // Skip hook if matcher is set and does not match the tool name
            if ($hook->matcher !== null) {
                $toolName = $context['tool'] ?? '';
                if (!fnmatch($hook->matcher, $toolName)) {
                    continue;
                }
            }

            $hookResult = $this->runHook($hook, $context);

            if (!$hookResult->allowed) {
                return $hookResult;
            }

            // Accumulate outputs from all hooks
            if ($hookResult->output !== '' && $hookResult->output !== null) {
                $outputs[] = $hookResult->output;
            }

            // Merge any modifications - each hook sees previous modifications
            if ($hookResult->modifiedInput !== null) {
                $context['input'] = $hookResult->modifiedInput;
                $modifiedInput = $hookResult->modifiedInput;
            }
        }

        return new HookResult(
            allowed: true,
            modifiedInput: $modifiedInput,
            output: implode("\n", $outputs),
        );
    }

    private function runHook(HookDefinition $hook, array $context): HookResult
    {
        $command = $hook->command;

        // Inject context as environment variables
        $env = [];
        foreach ($context as $key => $value) {
            if (is_string($value) || is_numeric($value)) {
                $env['HOOK_' . strtoupper($key)] = (string) $value;
            }
        }

        // Also pass context as JSON on stdin
        $stdin = json_encode($context, JSON_UNESCAPED_UNICODE);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, null, $env);

        if (!is_resource($process)) {
            return new HookResult(allowed: true, output: 'Failed to execute hook');
        }

        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            return new HookResult(
                allowed: false,
                output: "Hook failed (exit code {$exitCode}): " . trim($stderr ?: $stdout),
            );
        }

        // Parse stdout for hook decisions
        $output = trim($stdout);

        if (in_array(strtolower($output), ['deny', 'block', 'no'])) {
            return new HookResult(allowed: false, output: 'Denied by hook');
        }

        // Check for JSON output with modifications
        $json = json_decode($output, true);
        if (is_array($json)) {
            return new HookResult(
                allowed: ($json['allow'] ?? true) !== false,
                modifiedInput: $json['input'] ?? null,
                output: $json['message'] ?? '',
            );
        }

        return new HookResult(allowed: true, output: $output);
    }

    private function loadHooks(): void
    {
        // Load hooks from settings files
        $paths = [];

        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: sys_get_temp_dir();
        $paths[] = "{$home}/.haocode/settings.json";
        $paths[] = getcwd() . '/.haocode/settings.json';

        foreach ($paths as $path) {
            if (file_exists($path)) {
                $settings = json_decode(file_get_contents($path), true) ?: [];
                $hooks = $settings['hooks'] ?? [];

                foreach ($hooks as $event => $eventHooks) {
                    foreach ($eventHooks as $hookConfig) {
                        if (isset($hookConfig['command'])) {
                            $this->hooks[$event][] = new HookDefinition(
                                event: $event,
                                command: $hookConfig['command'],
                                matcher: $hookConfig['matcher'] ?? null,
                            );
                        }
                    }
                }
            }
        }
    }
}
