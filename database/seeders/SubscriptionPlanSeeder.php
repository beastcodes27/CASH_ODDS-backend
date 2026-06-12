<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

class SubscriptionPlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Daily Plan',
                'slug' => 'daily',
                'description' => 'Access to all premium tips for 24 hours',
                'price' => 2.99,
                'duration' => 'daily',
                'duration_days' => 1,
                'features' => [
                    'All premium tips',
                    'Expert analysis',
                    '24-hour access',
                ],
            ],
            [
                'name' => 'Weekly Plan',
                'slug' => 'weekly',
                'description' => 'Access to all premium tips for 7 days',
                'price' => 9.99,
                'duration' => 'weekly',
                'duration_days' => 7,
                'features' => [
                    'All premium tips',
                    'Expert analysis',
                    'Email notifications',
                    '7-day access',
                ],
            ],
            [
                'name' => 'Monthly Plan',
                'slug' => 'monthly',
                'description' => 'Full access for 30 days - Best value',
                'price' => 29.99,
                'duration' => 'monthly',
                'duration_days' => 30,
                'features' => [
                    'All premium tips',
                    'Expert analysis',
                    'Email notifications',
                    'Priority support',
                    '30-day access',
                ],
            ],
            [
                'name' => 'Yearly Plan',
                'slug' => 'yearly',
                'description' => 'Full access for 365 days - Save 50%',
                'price' => 149.99,
                'duration' => 'yearly',
                'duration_days' => 365,
                'features' => [
                    'All premium tips',
                    'Expert analysis',
                    'Email notifications',
                    'Priority support',
                    'Personal tipster consultation',
                    '365-day access',
                    'Save 50%',
                ],
            ],
        ];

        foreach ($plans as $plan) {
            SubscriptionPlan::create($plan);
        }
    }
}
