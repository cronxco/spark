<?php

namespace App\Jobs\Data\Immich;

use App\Jobs\Base\BaseProcessingJob;
use App\Models\Person;
use App\Services\ImmichUrlBuilder;
use Illuminate\Support\Facades\Log;

class PeopleData extends BaseProcessingJob
{
    /**
     * Get the service name for this job
     */
    protected function getServiceName(): string
    {
        return 'immich';
    }

    /**
     * Get the job type for logging
     */
    protected function getJobType(): string
    {
        return 'people';
    }

    /**
     * Process the raw people data and create/update Person objects
     */
    protected function process(): void
    {
        $people = $this->rawData['people'] ?? [];

        if (empty($people)) {
            Log::info('No people to process for Immich integration', [
                'integration_id' => $this->integration->id,
            ]);

            return;
        }

        $urlBuilder = app(ImmichUrlBuilder::class);
        $serverUrl = $this->integration->group->auth_metadata['server_url'] ?? null;

        foreach ($people as $personData) {
            $personName = $personData['name'] ?? 'Unknown Person';
            $personId = $personData['id'] ?? null;

            // Build person thumbnail URL if available
            $thumbnailUrl = null;
            if ($serverUrl && $personId) {
                $thumbnailUrl = $urlBuilder->getPersonUrl($serverUrl, $personId);
            }

            // Create or update Person
            Person::updateOrCreate(
                [
                    'user_id' => $this->integration->user_id,
                    'concept' => 'person',
                    'type' => 'immich_person',
                    'title' => $personName,
                ],
                [
                    'time' => now(),
                    'metadata' => [
                        'immich_person_id' => $personId,
                        'birth_date' => $personData['birthDate'] ?? null,
                        'is_hidden' => $personData['isHidden'] ?? false,
                        'face_count' => count($personData['faces'] ?? []),
                    ],
                    'media_url' => $thumbnailUrl,
                ]
            );
        }

        Log::info('Processed people for Immich integration', [
            'integration_id' => $this->integration->id,
            'people_count' => count($people),
        ]);
    }
}
