<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use ParticleAcademy\Fms\Database\FeatureUsagesSchema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The definition lives in `FeatureUsagesSchema` because the reconcile
     * migration beside this one has to rebuild the same table, and a schema
     * written out twice is a schema that will disagree with itself.
     *
     * Table names are config-driven (config/fms.php → `tables`) so apps with
     * prefixed or renamed schemas don't have to fork this file, and
     * `subscription_id` takes the type of the host's own subscription key —
     * bigint, ULID, UUID or string — rather than assuming auto-increment. See
     * that class for why.
     *
     * The migration self-skips (no error) when:
     *   - the feature_usages table already exists (e.g. created by an
     *     older fork under a different/prefixed name), or
     *   - either FK target table is missing at apply time.
     * The second guard lets this migration sit harmlessly early in a
     * consumer's chronological migration order — they can build the real
     * table later in their own migration if their FK targets come after.
     */
    public function up(): void
    {
        if (! FeatureUsagesSchema::shouldCreate()) {
            return;
        }

        FeatureUsagesSchema::create();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(FeatureUsagesSchema::usagesTable());
    }
};
