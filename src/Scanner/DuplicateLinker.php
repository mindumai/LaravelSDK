<?php

declare(strict_types=1);

namespace Mindum\Laravel\Scanner;

/**
 * Post-processor that runs once all extractors have produced their entries.
 *
 * Annotates cross-entry relationships so downstream LLM analysis can see
 * when the same operation is exposed through more than one surface — e.g.,
 * a controller that dispatches a Job, or an Action that duplicates a
 * Model's auto-CRUD create tool.
 *
 * The linker only annotates — it never removes entries. The orchestrator
 * decides which version to keep enabled.
 */
class DuplicateLinker
{
    public int $controllerJobPairsLinked = 0;

    public int $sameIdConflictGroups = 0;

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<int, array<string, mixed>>
     */
    public function link(array $entries): array
    {
        $entries = $this->linkControllerToJob($entries);
        $entries = $this->linkSameIdConflicts($entries);

        return $entries;
    }

    /**
     * Annotate jobs that are dispatched from a controller endpoint. Controllers
     * already carry dispatches_jobs from ControllerExtractor; here we add the
     * inverse pointer on the job side.
     *
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<int, array<string, mixed>>
     */
    private function linkControllerToJob(array $entries): array
    {
        // Build FQCN => entry index for jobs.
        $jobByClass = [];
        foreach ($entries as $i => $e) {
            if ($e['kind'] === 'job') {
                $jobByClass[$e['source']['class']] = $i;
            }
        }

        foreach ($entries as $i => $e) {
            if ($e['kind'] !== 'controller_endpoint') {
                continue;
            }
            $dispatched = $e['kind_data']['dispatches_jobs'] ?? [];
            if ($dispatched === []) {
                continue;
            }

            foreach ($dispatched as $jobFqcn) {
                if (! isset($jobByClass[$jobFqcn])) {
                    continue;
                }
                $jobIdx = $jobByClass[$jobFqcn];
                $pairRef = $e['source']['class'].'::'.$e['source']['entry_method'];

                // Annotate job with the controller that dispatches it.
                $existing = $entries[$jobIdx]['kind_data']['paired_with_controllers'] ?? [];
                if (! in_array($pairRef, $existing, true)) {
                    $existing[] = $pairRef;
                    $entries[$jobIdx]['kind_data']['paired_with_controllers'] = $existing;
                    $this->controllerJobPairsLinked++;
                }
            }
        }

        return $entries;
    }

    /**
     * Group entries by id; any group with >1 entry has a same-id conflict.
     * Each entry in the group gets a kind_data.conflicts_with_id annotation
     * listing its siblings (class + kind).
     *
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<int, array<string, mixed>>
     */
    private function linkSameIdConflicts(array $entries): array
    {
        $byId = [];
        foreach ($entries as $i => $e) {
            $byId[$e['id']][] = $i;
        }

        foreach ($byId as $id => $indices) {
            if (count($indices) < 2) {
                continue;
            }
            $this->sameIdConflictGroups++;

            // Build the full list of (class, kind) for this id group.
            $allMembers = [];
            foreach ($indices as $idx) {
                $allMembers[] = [
                    'class' => $entries[$idx]['source']['class'],
                    'kind' => $entries[$idx]['kind'],
                ];
            }

            // On each entry, list the OTHER members of the group.
            foreach ($indices as $idx) {
                $selfClass = $entries[$idx]['source']['class'];
                $others = array_values(array_filter(
                    $allMembers,
                    fn (array $m) => $m['class'] !== $selfClass,
                ));
                $entries[$idx]['kind_data']['conflicts_with_id'] = $others;
            }
        }

        return $entries;
    }
}
