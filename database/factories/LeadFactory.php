<?php
namespace Database\Factories;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class LeadFactory extends Factory
{
    protected $model = Lead::class;

    public function definition(): array
    {
        return [
            // Случайный менеджер (предпочтительно с ролью manager) или любой пользователь
            'user_id' => User::exists()
                ? (User::where('role', 'manager')->inRandomOrder()->first()?->id ?? User::inRandomOrder()->first()->id)
                : null,
            
            'client_name' => $this->faker->name(),
            'phone' => $this->faker->e164PhoneNumber(),
            'email' => $this->faker->unique()->safeEmail(),
            
            'title' => $this->faker->sentence(3),
            'description' => $this->faker->paragraph(),
            'price' => $this->faker->randomFloat(2, 1000, 500000),
            
            'status' => $this->faker->randomElement(['new', 'processing', 'won', 'rejected']),
            'source' => $this->faker->randomElement(['whatsapp', 'site', 'manual']),
            
            'next_action_at' => $this->faker->optional(0.5)->dateTimeBetween('+1 day', '+1 month'),
            
            'created_at' => $this->faker->dateTimeBetween('-3 months', 'now'),
            'updated_at' => now(),
        ];
    }
}