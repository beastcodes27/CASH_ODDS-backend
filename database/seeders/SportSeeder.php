<?php

namespace Database\Seeders;

use App\Models\League;
use App\Models\Sport;
use Illuminate\Database\Seeder;

class SportSeeder extends Seeder
{
    public function run(): void
    {
        $sports = [
            [
                'name' => 'Football',
                'slug' => 'football',
                'icon' => 'soccer',
                'leagues' => [
                    ['name' => 'Premier League', 'country' => 'England'],
                    ['name' => 'La Liga', 'country' => 'Spain'],
                    ['name' => 'Serie A', 'country' => 'Italy'],
                    ['name' => 'Bundesliga', 'country' => 'Germany'],
                    ['name' => 'Champions League', 'country' => 'Europe'],
                ]
            ],
            [
                'name' => 'Basketball',
                'slug' => 'basketball',
                'icon' => 'basketball',
                'leagues' => [
                    ['name' => 'NBA', 'country' => 'USA'],
                    ['name' => 'EuroLeague', 'country' => 'Europe'],
                ]
            ],
            [
                'name' => 'Tennis',
                'slug' => 'tennis',
                'icon' => 'tennis',
                'leagues' => [
                    ['name' => 'ATP Tour', 'country' => 'World'],
                    ['name' => 'WTA Tour', 'country' => 'World'],
                ]
            ],
        ];

        foreach ($sports as $sportData) {
            $leagues = $sportData['leagues'];
            unset($sportData['leagues']);
            
            $sport = Sport::create($sportData);
            
            foreach ($leagues as $leagueData) {
                $leagueData['sport_id'] = $sport->id;
                League::create($leagueData);
            }
        }
    }
}
