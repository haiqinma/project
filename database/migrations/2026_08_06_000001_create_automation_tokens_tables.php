<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('userid')->index();
            $table->string('access_key', 64)->unique();
            $table->char('secret_hash', 64);
            $table->string('name', 100);
            $table->json('scopes');
            $table->json('project_ids');
            $table->timestamp('expires_at');
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 64)->nullable();
            $table->string('status', 16)->default('active')->index();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->foreign('userid')->references('userid')->on('users')->cascadeOnDelete();
        });

        Schema::create('automation_token_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('token_id')->nullable()->index();
            $table->unsignedBigInteger('userid')->nullable()->index();
            $table->string('action', 80)->index();
            $table->string('resource_type', 32)->nullable();
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->string('ip', 64)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('request_id', 100)->nullable()->index();
            $table->string('result', 80);
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('token_id')->references('id')->on('automation_tokens')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_token_audits');
        Schema::dropIfExists('automation_tokens');
    }
};
