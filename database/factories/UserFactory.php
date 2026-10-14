<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->userName().'@'.config('meetingpoll.allowed_domain'),
            'google_id' => (string) fake()->unique()->numerify('##################'),
            'timezone' => 'America/Chicago',
            'remember_token' => Str::random(10),
        ];
    }
}
