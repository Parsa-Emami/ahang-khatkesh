<?php

namespace Tests\Feature;

use App\Actions\Ops\AssessReleaseReadiness;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class UniversalReleaseMatrixTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_device_matrix_schema_stays_unsigned_until_human_qualification(): void
    {
        $matrix = json_decode((string) file_get_contents(base_path('docs/verification/f16-device-matrix.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $matrix['schema_version']);
        $this->assertFalse($matrix['signed']);
        $engines = collect($matrix['browsers'])->pluck('engine')->all();
        $this->assertSame(['Chromium', 'Firefox', 'Safari'], $engines);
        $this->assertTrue(collect($matrix['browsers'])->firstWhere('engine', 'Chromium')['automated']);
        $this->assertFalse(collect($matrix['browsers'])->firstWhere('engine', 'Safari')['qualified']);
        $platforms = collect($matrix['devices'])->pluck('platform')->all();
        $this->assertContains('iOS', $platforms);
        $this->assertContains('Android', $platforms);
        $assistive = collect($matrix['assistive'])->pluck('tool')->all();
        $this->assertSame(['keyboard', 'NVDA', 'VoiceOver'], $assistive);
        $this->assertFalse(collect($matrix['assistive'])->firstWhere('tool', 'NVDA')['qualified']);
    }

    public function test_release_evidence_template_blocks_ci_only_production_approval(): void
    {
        $template = json_decode((string) file_get_contents(base_path('docs/verification/f16-release-evidence.template.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach ([
            'dast',
            'pentest',
            'keyboard_sr',
            'device_matrix',
            'restore_drill',
            'rollback',
            'acceptance_owner',
            'independent_reviewer',
        ] as $key) {
            $this->assertArrayHasKey($key, $template);
            if (is_array($template[$key]) && array_key_exists('signed', $template[$key])) {
                $this->assertFalse($template[$key]['signed'], $key.' must stay unsigned in template');
            }
        }
        $this->assertTrue($template['dast']['staging_only']);
        $this->assertTrue($template['dast']['synthetic_data']);
        $this->assertFileExists(base_path('docs/AFTER_F16.fa.md'));
        $this->assertFileExists(base_path('docs/F16_ASVS_MAPPING.fa.md'));
        $this->assertFileExists(base_path('tests/Browser/f09.e2e.ts'));
        $this->assertFileExists(base_path('tests/Browser/f10.e2e.ts'));
        $roadmap = json_decode((string) file_get_contents(base_path('docs/roadmap/phases.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertFalse($roadmap['production_approved']);
    }

    public function test_release_readiness_stays_blocked_without_human_signatures(): void
    {
        $result = app(AssessReleaseReadiness::class)->handle();
        $this->assertFalse($result['ready']);
        $this->assertFalse($result['production_approved']);
        $this->assertContains('signed_release_evidence_missing', $result['blockers']);
        $this->assertContains('independent_reviewer.signed', $result['blockers']);
        $this->assertContains('device_matrix.signed', $result['blockers']);
        $this->assertSame(1, Artisan::call('ops:release-readiness'));
    }

    public function test_keyboard_contract_docs_require_independent_screen_reader_signoff(): void
    {
        $mapping = file_get_contents(base_path('docs/F16_ASVS_MAPPING.fa.md'));
        $this->assertNotFalse($mapping);
        $this->assertStringContainsString('NVDA/VoiceOver', $mapping);
        $contract = file_get_contents(base_path('docs/F16_RELEASE_SECURITY.fa.md'));
        $this->assertNotFalse($contract);
        $this->assertStringContainsString('سبزشدن CI به‌تنهایی تأیید production نیست', $contract);
    }
}
