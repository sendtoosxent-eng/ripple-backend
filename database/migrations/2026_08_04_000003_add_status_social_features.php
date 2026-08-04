<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('statuses', function (Blueprint $table) {
            $table->foreignId('reposted_from_id')->nullable()->after('user_id')->constrained('statuses')->nullOnDelete();
        });

        Schema::create('status_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('status_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['status_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_likes');
        Schema::table('statuses', fn (Blueprint $table) => $table->dropConstrainedForeignId('reposted_from_id'));
    }
};
