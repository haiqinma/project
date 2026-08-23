<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('user_wallets', function (Blueprint $table) {
            $table->string('wallet_identity_did', 128)->nullable()->after('address_normalized');
            $table->unique('wallet_identity_did', 'user_wallets_wallet_identity_did_unique');
        });
    }

    public function down(): void
    {
        Schema::table('user_wallets', function (Blueprint $table) {
            $table->dropUnique('user_wallets_wallet_identity_did_unique');
            $table->dropColumn('wallet_identity_did');
        });
    }
};
