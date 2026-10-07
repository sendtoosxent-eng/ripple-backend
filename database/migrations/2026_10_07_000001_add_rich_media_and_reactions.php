<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->string('media_path')->nullable()->after('image_path');
            $table->string('media_type', 20)->nullable()->after('media_path');
            $table->unsignedInteger('media_duration_ms')->nullable()->after('media_type');
        });

        Schema::table('statuses', function (Blueprint $table) {
            $table->string('type', 20)->change();
            $table->unsignedInteger('media_duration_ms')->nullable()->after('media_path');
        });

        Schema::create('post_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('emoji', 16);
            $table->timestamps();
            $table->unique(['post_id', 'user_id']);
        });

        Schema::create('status_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('status_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('emoji', 16);
            $table->timestamps();
            $table->unique(['status_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_reactions');
        Schema::dropIfExists('post_reactions');
        Schema::table('statuses', fn (Blueprint $table) => $table->dropColumn('media_duration_ms'));
        Schema::table('posts', fn (Blueprint $table) => $table->dropColumn(['media_path', 'media_type', 'media_duration_ms']));
    }
};
