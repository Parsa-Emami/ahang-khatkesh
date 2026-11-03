<?php

namespace App\Actions\Ops;

class AssessReleaseReadiness
{
    /**
     * @return array{ready: bool, production_approved: bool, blockers: list<string>}
     */
    public function handle(): array
    {
        $blockers = [];
        if (config('app.debug') === true) {
            $blockers[] = 'app_debug';
        }
        if (config('session.secure') !== true) {
            $blockers[] = 'session_secure_cookie';
        }
        if (config('session.http_only') !== true) {
            $blockers[] = 'session_http_only';
        }
        $signedPath = base_path('docs/verification/f16-release-evidence.json');
        if (! is_file($signedPath)) {
            $blockers[] = 'signed_release_evidence_missing';
            $template = json_decode((string) file_get_contents(base_path('docs/verification/f16-release-evidence.template.json')), true, flags: JSON_THROW_ON_ERROR);
            foreach ($template['production_approved_requires'] ?? [] as $required) {
                $blockers[] = (string) $required;
            }
        } else {
            $release = json_decode((string) file_get_contents($signedPath), true, flags: JSON_THROW_ON_ERROR);
            foreach ($release['production_approved_requires'] ?? [] as $required) {
                $cursor = $release;
                foreach (explode('.', (string) $required) as $part) {
                    $cursor = is_array($cursor) ? ($cursor[$part] ?? null) : null;
                }
                if ($cursor !== true) {
                    $blockers[] = (string) $required;
                }
            }
            if (($release['independent_reviewer']['distinct_from_author'] ?? null) !== true) {
                $blockers[] = 'independent_reviewer.distinct_from_author';
            }
        }
        $matrixPath = base_path('docs/verification/f16-device-matrix.json');
        $matrix = json_decode((string) file_get_contents($matrixPath), true, flags: JSON_THROW_ON_ERROR);
        if (($matrix['signed'] ?? false) !== true) {
            $blockers[] = 'device_matrix.signed';
        }

        return [
            'ready' => $blockers === [],
            'production_approved' => false,
            'blockers' => array_values(array_unique($blockers)),
        ];
    }
}
