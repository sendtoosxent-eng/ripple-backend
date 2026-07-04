<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->unique()->nullable()->after('name');
            $table->string('avatar')->nullable()->after('username');
            $table->string('status')->nullable()->after('avatar');
            $table->text('bio')->nullable()->after('status');
            $table->boolean('online')->default(false)->after('bio');
            $table->timestamp('last_seen_at')->nullable()->after('online');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['username', 'avatar', 'status', 'bio', 'online', 'last_seen_at']);
        });
    }
};
