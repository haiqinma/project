<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_token_nonces', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('token_id');
            $table->char('nonce_hash', 64);
            $table->timestamp('expires_at')->index();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['token_id', 'nonce_hash'], 'automation_token_nonce_unique');
            $table->foreign('token_id')->references('id')->on('automation_tokens')->cascadeOnDelete();
        });

        Schema::table('automation_token_audits', function (Blueprint $table) {
            $table->index(['result', 'created_at'], 'automation_audit_result_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('automation_token_audits', function (Blueprint $table) {
            $table->dropIndex('automation_audit_result_created_index');
        });
        Schema::dropIfExists('automation_token_nonces');
    }
};
