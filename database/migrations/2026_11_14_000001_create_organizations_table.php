<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type', 20)->default('store');
            foreach (['address', 'city', 'state', 'zipCode', 'phone', 'email', 'manager'] as $field) {
                $table->string($field)->nullable();
            }
            $table->unsignedInteger('employees')->default(0);
            $table->string('status', 20)->default('active');
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
