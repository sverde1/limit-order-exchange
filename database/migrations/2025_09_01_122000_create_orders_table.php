<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('symbol', 16);
            $table->enum('side', ['buy', 'sell']);
            $table->decimal('price', 18, 8);
            $table->decimal('amount', 28, 18);
            $table->unsignedTinyInteger('status')->default(1)->comment('1=open, 2=filled, 3=canceled');
            $table->timestamps();

            $table->index(['user_id', 'symbol']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
