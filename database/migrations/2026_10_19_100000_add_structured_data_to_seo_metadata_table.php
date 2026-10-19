<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seo_metadata', function (Blueprint $table): void {
            $table->boolean('structured_data_enabled')->default(false);
            $table->string('structured_data_type', 80)->nullable();
            $table->json('structured_data')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('seo_metadata', function (Blueprint $table): void {
            $table->dropColumn(['structured_data_enabled', 'structured_data_type', 'structured_data']);
        });
    }
};
