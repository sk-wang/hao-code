<?php

namespace Tests\Unit;

use App\Tools\Skill\SkillDefinition;
use App\Tools\Skill\SkillLoader;
use PHPUnit\Framework\TestCase;

class SkillLoaderTest extends TestCase
{
    private string $tmpDir;
    private string $skillsDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/skill_loader_test_' . uniqid();
        $this->skillsDir = $this->tmpDir . '/skills';
        mkdir($this->skillsDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = "$dir/$item";
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * Build a loader that scans only our temp directory.
     */
    private function makeLoader(): SkillLoader
    {
        $loader = new SkillLoader;
        $ref = new \ReflectionClass($loader);
        $method = $ref->getMethod('getSkillDirectories');
        // We can't easily override getSkillDirectories (it's private), so inject
        // skills directly by calling loadFromDirectory via reflection.
        $skillsProp = $ref->getProperty('skills');
        $skillsProp->setAccessible(true);
        $skillsProp->setValue($loader, []);

        $loadDir = $ref->getMethod('loadFromDirectory');
        $loadDir->setAccessible(true);
        $loadDir->invoke($loader, $this->skillsDir);

        // Mark skills as cached so loadSkills() returns the injected value
        // (skills was already set to [] above, then populated by loadFromDirectory)
        return $loader;
    }

    private function writeSkillFile(string $name, string $content, bool $useSubdir = false): void
    {
        if ($useSubdir) {
            $dir = $this->skillsDir . '/' . $name;
            mkdir($dir, 0755, true);
            file_put_contents($dir . '/SKILL.md', $content);
        } else {
            file_put_contents($this->skillsDir . '/' . $name . '.md', $content);
        }
    }

    // ─── parseSkillFile (via reflection) ─────────────────────────────────

    private function parseSkillFile(string $name, string $content): SkillDefinition
    {
        $loader = new SkillLoader;
        $ref = new \ReflectionClass($loader);
        $method = $ref->getMethod('parseSkillFile');
        $method->setAccessible(true);
        return $method->invoke($loader, $name, $content, '/some/dir');
    }

    public function test_parse_skill_without_frontmatter_uses_first_line_as_description(): void
    {
        $skill = $this->parseSkillFile('my-skill', "This is the description\n\nBody content here.");
        $this->assertSame('This is the description', $skill->description);
        $this->assertStringContainsString('Body content here.', $skill->prompt);
    }

    public function test_parse_skill_with_frontmatter_uses_description_field(): void
    {
        $content = "---\ndescription: My skill description\n---\n\nDo this task.";
        $skill = $this->parseSkillFile('my-skill', $content);
        $this->assertSame('My skill description', $skill->description);
    }

    public function test_parse_skill_body_excludes_frontmatter(): void
    {
        $content = "---\ndescription: desc\n---\n\nActual prompt content.";
        $skill = $this->parseSkillFile('my-skill', $content);
        $this->assertSame('Actual prompt content.', $skill->prompt);
        $this->assertStringNotContainsString('---', $skill->prompt);
    }

    public function test_parse_skill_allowed_tools_parsed_from_csv(): void
    {
        $content = "---\nallowed-tools: Read,Write,Bash\n---\n\nPrompt";
        $skill = $this->parseSkillFile('my-skill', $content);
        $this->assertSame(['Read', 'Write', 'Bash'], $skill->allowedTools);
    }

    public function test_parse_skill_user_invocable_defaults_to_true(): void
    {
        $skill = $this->parseSkillFile('my-skill', 'No frontmatter');
        $this->assertTrue($skill->userInvocable);
    }

    public function test_parse_skill_user_invocable_false_from_frontmatter(): void
    {
        $content = "---\nuser-invocable: false\n---\n\nInternal only.";
        $skill = $this->parseSkillFile('my-skill', $content);
        $this->assertFalse($skill->userInvocable);
    }

    public function test_parse_skill_model_from_frontmatter(): void
    {
        $content = "---\nmodel: claude-opus-4\n---\n\nUse opus.";
        $skill = $this->parseSkillFile('my-skill', $content);
        $this->assertSame('claude-opus-4', $skill->model);
    }

    public function test_parse_skill_model_defaults_to_null(): void
    {
        $skill = $this->parseSkillFile('my-skill', 'Plain content');
        $this->assertNull($skill->model);
    }

    public function test_parse_skill_argument_hint_from_frontmatter(): void
    {
        $content = "---\nargument-hint: branch-name\n---\n\nCreate branch.";
        $skill = $this->parseSkillFile('my-skill', $content);
        $this->assertSame('branch-name', $skill->argumentHint);
    }

    public function test_parse_skill_context_defaults_to_inline(): void
    {
        $skill = $this->parseSkillFile('my-skill', 'Prompt');
        $this->assertSame('inline', $skill->context);
    }

    public function test_parse_skill_name_is_preserved(): void
    {
        $skill = $this->parseSkillFile('commit', 'Commit the changes');
        $this->assertSame('commit', $skill->name);
    }

    // ─── findSkill ────────────────────────────────────────────────────────

    public function test_find_skill_with_leading_slash_stripped(): void
    {
        $this->writeSkillFile('deploy', "Deploy the app");
        $loader = $this->makeLoader();

        $skill = $loader->findSkill('/deploy');
        $this->assertNotNull($skill);
        $this->assertSame('deploy', $skill->name);
    }

    public function test_find_skill_without_slash(): void
    {
        $this->writeSkillFile('test', "Run tests");
        $loader = $this->makeLoader();

        $skill = $loader->findSkill('test');
        $this->assertNotNull($skill);
    }

    public function test_find_skill_returns_null_for_unknown(): void
    {
        $loader = $this->makeLoader();
        $this->assertNull($loader->findSkill('no-such-skill'));
    }

    // ─── getSkillDescriptions ─────────────────────────────────────────────

    public function test_get_skill_descriptions_returns_empty_when_no_skills(): void
    {
        $loader = $this->makeLoader();
        $this->assertSame('', $loader->getSkillDescriptions());
    }

    public function test_get_skill_descriptions_contains_skill_names(): void
    {
        $this->writeSkillFile('commit', "---\ndescription: Create a git commit\n---\n\nDo it.");
        $loader = $this->makeLoader();

        $desc = $loader->getSkillDescriptions();
        $this->assertStringContainsString('/commit', $desc);
        $this->assertStringContainsString('Create a git commit', $desc);
    }

    public function test_get_skill_descriptions_respects_max_chars(): void
    {
        // Create many skills
        for ($i = 0; $i < 20; $i++) {
            $this->writeSkillFile("skill{$i}", "---\ndescription: A very long description that uses space\n---\n\nPrompt.");
        }
        $loader = $this->makeLoader();

        $desc = $loader->getSkillDescriptions(200);
        $this->assertLessThanOrEqual(250, strlen($desc), 'Should be roughly within max chars');
    }

    // ─── listSkills ───────────────────────────────────────────────────────

    public function test_list_skills_returns_array_with_required_keys(): void
    {
        $this->writeSkillFile('review', "---\ndescription: Code review\n---\n\nReview the code.");
        $loader = $this->makeLoader();

        $list = $loader->listSkills();
        $this->assertNotEmpty($list);
        $this->assertArrayHasKey('name', $list[0]);
        $this->assertArrayHasKey('description', $list[0]);
        $this->assertArrayHasKey('user_invocable', $list[0]);
    }

    public function test_list_skills_empty_when_no_skills(): void
    {
        $loader = $this->makeLoader();
        $this->assertSame([], $loader->listSkills());
    }

    // ─── caching ──────────────────────────────────────────────────────────

    public function test_load_skills_is_cached_on_second_call(): void
    {
        $this->writeSkillFile('cached', "Cached skill");
        $loader = $this->makeLoader();

        $first = $loader->loadSkills();
        // Add another file after first load — should not appear (cached)
        $this->writeSkillFile('new-skill', "New skill");
        $second = $loader->loadSkills();

        $this->assertSame($first, $second);
    }

    // ─── SKILL.md subdirectory format ─────────────────────────────────────

    public function test_skill_loaded_from_subdirectory_skill_md(): void
    {
        $this->writeSkillFile('build', "Build the project", useSubdir: true);
        $loader = $this->makeLoader();

        $skill = $loader->findSkill('build');
        $this->assertNotNull($skill);
        $this->assertSame('build', $skill->name);
    }

    // ─── parseYaml ────────────────────────────────────────────────────────

    public function test_parse_yaml_skips_comment_lines(): void
    {
        $content = "---\n# This is a comment\ndescription: Real desc\n---\n\nPrompt.";
        $skill = $this->parseSkillFile('test', $content);
        $this->assertSame('Real desc', $skill->description);
    }

    public function test_parse_yaml_strips_quotes_from_values(): void
    {
        $content = "---\ndescription: \"Quoted description\"\n---\n\nPrompt.";
        $skill = $this->parseSkillFile('test', $content);
        $this->assertSame('Quoted description', $skill->description);
    }
}
