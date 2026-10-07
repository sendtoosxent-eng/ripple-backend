<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('deleted_for_everyone_at')->nullable();

            // Which users have chosen "Delete for me".
            $table->json('deleted_for_user_ids')->nullable();

            // Original message when this message was forwarded.
            $table->unsignedBigInteger('forwarded_from_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['forwarded_from_id']);

            $table->dropColumn([
                'edited_at',
                'deleted_for_everyone_at',
                'deleted_for_user_ids',
                'forwarded_from_id',
            ]);
        });
    }
};