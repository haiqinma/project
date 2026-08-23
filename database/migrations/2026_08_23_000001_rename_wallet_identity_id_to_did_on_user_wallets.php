<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('user_wallets', 'wallet_identity_did')) {
            return;
        }

        if (!Schema::hasColumn('user_wallets', 'wallet_identity_id')) {
            Schema::table('user_wallets', function (Blueprint $table) {
                $table->string('wallet_identity_did', 128)->nullable()->after('address_normalized');
                $table->unique('wallet_identity_did', 'user_wallets_wallet_identity_did_unique');
            });
            return;
        }

        Schema::table('user_wallets', function (Blueprint $table) {
            $table->dropUnique('user_wallets_wallet_identity_unique');
            $table->renameColumn('wallet_identity_id', 'wallet_identity_did');
        });

        Schema::table('user_wallets', function (Blueprint $table) {
            $table->unique('wallet_identity_did', 'user_wallets_wallet_identity_did_unique');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('user_wallets', 'wallet_identity_id')) {
            return;
        }

        if (!Schema::hasColumn('user_wallets', 'wallet_identity_did')) {
            Schema::table('user_wallets', function (Blueprint $table) {
                $table->string('wallet_identity_id', 128)->nullable()->after('address_normalized');
                $table->unique('wallet_identity_id', 'user_wallets_wallet_identity_unique');
            });
            return;
        }

        Schema::table('user_wallets', function (Blueprint $table) {
            $table->dropUnique('user_wallets_wallet_identity_did_unique');
            $table->renameColumn('wallet_identity_did', 'wallet_identity_id');
        });

        Schema::table('user_wallets', function (Blueprint $table) {
            $table->unique('wallet_identity_id', 'user_wallets_wallet_identity_unique');
        });
    }
};
