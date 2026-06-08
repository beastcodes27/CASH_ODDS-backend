<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sports', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('icon')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('leagues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sport_id')->constrained();
            $table->string('name');
            $table->string('country')->nullable();
            $table->string('logo')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('tips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tipster_id')->constrained('users');
            $table->foreignId('sport_id')->constrained();
            $table->foreignId('league_id')->constrained();
            $table->string('home_team');
            $table->string('away_team');
            $table->dateTime('match_date');
            $table->string('prediction');
            $table->enum('odds_type', ['1x2', 'over_under', 'btts', 'handicap', 'correct_score', 'other']);
            $table->decimal('odds', 8, 2);
            $table->enum('confidence', ['low', 'medium', 'high'])->default('medium');
            $table->text('analysis')->nullable();
            $table->enum('status', ['pending', 'won', 'lost', 'void'])->default('pending');
            $table->integer('home_score')->nullable();
            $table->integer('away_score')->nullable();
            $table->boolean('is_premium')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tips');
        Schema::dropIfExists('leagues');
        Schema::dropIfExists('sports');
    }
};
