<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '+2519'.fake()->unique()->numerify('########'),
            'password' => static::$password ??= Hash::make('Password@123'),
            'status' => 'active',
            'city' => 'Addis Ababa',
            'remember_token' => Str::random(10),
        ];
    }
}
