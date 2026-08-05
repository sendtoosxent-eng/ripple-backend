<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->uuid('client_message_id')->nullable()->after('sender_id');
            $table->unique(['sender_id', 'client_message_id'], 'messages_sender_client_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropUnique('messages_sender_client_id_unique');
            $table->dropColumn('client_message_id');
        });
    }
};
