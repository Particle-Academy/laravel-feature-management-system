<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Polymorphic pivot binding feature groups to assignable subjects
 * (User, Team, Org, Product, anything implementing HasFeatureGroups).
 *
 * `group_key` is the FeatureGroup's stable string id from config/registry —
 * intentionally not a foreign key, since groups are config-defined and the
 * key is the source of truth. If the key is renamed, an app should write a
 * migration to update existing rows.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('feature_group_assignments', function (Blueprint $table) {
            $table->id();
            $table->string('group_key');
            $table->morphs('assignable'); // assignable_type + assignable_id
            $table->timestamp('assigned_at')->nullable();
            $table->timestamps();

            // A subject shouldn't be assigned to the same group twice.
            $table->unique(
                ['group_key', 'assignable_type', 'assignable_id'],
                'fga_unique_assignment'
            );

            $table->index('group_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_group_assignments');
    }
};
