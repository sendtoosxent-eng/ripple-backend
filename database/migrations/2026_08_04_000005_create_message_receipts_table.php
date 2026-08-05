<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->unique(['message_id', 'user_id']);
            $table->index(['user_id', 'read_at']);
        });

        DB::table('messages')->orderBy('id')->each(function ($message) {
            $recipients = DB::table('conversation_user')->where('conversation_id', $message->conversation_id)->where('user_id', '!=', $message->sender_id)->pluck('user_id');
            foreach ($recipients as $userId) DB::table('message_receipts')->insertOrIgnore([
                'message_id' => $message->id, 'user_id' => $userId,
                'delivered_at' => in_array($message->status, ['delivered', 'read']) ? $message->updated_at : null,
                'read_at' => $message->status === 'read' ? $message->updated_at : null,
                'created_at' => $message->created_at, 'updated_at' => $message->updated_at,
            ]);
        });
    }

    public function down(): void { Schema::dropIfExists('message_receipts'); }
};
