<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Mollsoft\LaravelMoneroModule\Models\MoneroAccount;
use Mollsoft\LaravelMoneroModule\Models\MoneroAddress;
use Mollsoft\LaravelMoneroModule\Models\MoneroWallet;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('monero_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('txid')->index();
            $table->string('address')->index();
            $table->enum('type', ['in', 'out', 'pool']);
            $table->decimal('amount', 30, 12);
            $table->decimal('amount_usd', 30, 12)->default(0);
            $table->decimal('fee', 30, 12);
            $table->decimal('fee_usd', 30, 12)->default(0);
            $table->bigInteger('block_height')
                ->nullable();
            $table->integer('confirmations');
            $table->timestamp('time_at');
            $table->json('data');
            $table->timestamps();

            $table->unique(['txid', 'address'], 'unique_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monero_transactions');
    }
};
