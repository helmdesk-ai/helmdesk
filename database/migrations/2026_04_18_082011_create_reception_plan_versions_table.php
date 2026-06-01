<?php

use App\Enums\ReceptionPlanVersionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reception_plan_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->timestamps();

            $table->ulid('reception_plan_id');

            $table->unsignedInteger('version_number');
            $table->string('description', 500)->nullable();

            $table->json('snapshot_config');
            $table->json('compiled_config');

            $table->string('status', 20)->default(ReceptionPlanVersionStatus::Published->value);

            $table->timestamp('published_at')->nullable();
            $table->ulid('published_by_user_id')->nullable();

            $table->unique(['reception_plan_id', 'version_number'], 'uniq_reception_plan_versions_plan_number');
            $table->index(['reception_plan_id', 'status'], 'idx_reception_plan_versions_plan_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reception_plan_versions');
    }
};
