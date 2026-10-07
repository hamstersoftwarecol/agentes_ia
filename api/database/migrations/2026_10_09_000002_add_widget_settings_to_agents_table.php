<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->string('widget_position', 8)->default('right')->after('is_active');
            $table->string('widget_color', 7)->default('#b45309')->after('widget_position');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn(['widget_position', 'widget_color']);
        });
    }
};
