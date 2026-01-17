<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('model_translations', function (Blueprint $table) {
            $table->string('translatable_type');
            $table->string('translatable_id'); // A string for numaric/string ids
            $table->string('locale');
            $table->string('key');
            $table->text('value');
            $table->timestamps();

            $table->primary(['translatable_type', 'translatable_id', 'locale', 'key']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('model_translations');
    }
};
