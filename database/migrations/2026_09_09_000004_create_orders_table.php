<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 32)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('pending');
            $table->decimal('total_amount', 19, 2)->unsigned();
            $table->timestamps();

            $table->index(['user_id', 'status', 'created_at', 'id'], 'orders_user_status_created_id_index');
            $table->index(['user_id', 'created_at', 'id'], 'orders_user_created_id_index');
            $table->index(['status', 'created_at', 'id'], 'orders_status_created_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
