<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('type', 20)->default('text')->change();
            $table->string('call_status', 20)->nullable()->after('voice_duration');
            $table->unsignedInteger('call_duration')->nullable()->after('call_status');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['call_status', 'call_duration']);
        });
    }
};
