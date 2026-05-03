<?php

namespace ParticleAcademy\Fms\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use ParticleAcademy\Fms\Models\FeatureGroupAssignment;

/**
 * Drop-in trait for any model that should be assignable to feature groups
 * (User, Team, Org, Product, anything).
 *
 * Usage:
 *
 *   class User extends Authenticatable {
 *       use HasFeatureGroups;
 *   }
 *
 *   $user->attachFeatureGroup('pro-plan');
 *   $user->detachFeatureGroup('pro-plan');
 *   $user->featureGroups();           // ['pro-plan', ...]
 *   $user->hasFeatureGroup('pro-plan'); // bool
 */
trait HasFeatureGroups
{
    /** @return MorphMany<FeatureGroupAssignment> */
    public function featureGroupAssignments(): MorphMany
    {
        return $this->morphMany(FeatureGroupAssignment::class, 'assignable');
    }

    /**
     * Group keys assigned to this subject via the pivot. Does NOT include
     * groups whose `enabled` callable matches — that's resolved at
     * feature-check time inside the FeatureManager.
     *
     * @return array<int,string>
     */
    public function featureGroups(): array
    {
        return $this->featureGroupAssignments()
            ->pluck('group_key')
            ->unique()
            ->values()
            ->all();
    }

    public function hasFeatureGroup(string $key): bool
    {
        return $this->featureGroupAssignments()
            ->where('group_key', $key)
            ->exists();
    }

    public function attachFeatureGroup(string $key): FeatureGroupAssignment
    {
        return $this->featureGroupAssignments()->firstOrCreate(
            ['group_key' => $key],
            ['assigned_at' => Carbon::now()]
        );
    }

    public function detachFeatureGroup(string $key): int
    {
        return $this->featureGroupAssignments()
            ->where('group_key', $key)
            ->delete();
    }

    /** Sync to exactly the given group keys (attaches missing, detaches extras). */
    public function syncFeatureGroups(array $keys): void
    {
        $current = $this->featureGroups();
        $toAdd = array_diff($keys, $current);
        $toRemove = array_diff($current, $keys);
        foreach ($toAdd as $key) {
            $this->attachFeatureGroup($key);
        }
        foreach ($toRemove as $key) {
            $this->detachFeatureGroup($key);
        }
    }
}
