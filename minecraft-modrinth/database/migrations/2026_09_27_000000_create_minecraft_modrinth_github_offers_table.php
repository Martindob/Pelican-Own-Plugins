<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-server state of plugins offered from the GitHub repository source: dismissed by an
     * admin ("doesn't belong on this server") and already announced by a notification.
     */
    public function up(): void
    {
        if (Schema::hasTable('minecraft_modrinth_github_offers')) {
            return;
        }

        Schema::create('minecraft_modrinth_github_offers', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('server_id');
            $table->string('plugin_id', 64);
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->string('notified_version', 32)->nullable();
            $table->timestamps();

            $table->unique(['server_id', 'plugin_id']);
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minecraft_modrinth_github_offers');
    }
};
