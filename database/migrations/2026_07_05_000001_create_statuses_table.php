<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['text', 'image']);
            $table->text('text')->nullable();          // caption or the text-only status content
            $table->string('media_path')->nullable();   // for type=image
            $table->string('background')->nullable();   // hex color for text-only statuses
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        Schema::create('status_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('status_id')->constrained()->cascadeOnDelete();
            $table->foreignId('viewer_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('viewed_at');

            $table->unique(['status_id', 'viewer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_views');
        Schema::dropIfExists('statuses');
    }
};
