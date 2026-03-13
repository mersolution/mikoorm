<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 * See LICENSE file for details.
 *
 * @contact hello@mersolution.com
 * @website https://www.mersolution.com/
 */

namespace Miko\Database\Factories;

use Miko\Database\ORM\Model;

/**
 * Base Factory class
 * 
 * Create fake model instances for testing and seeding.
 * 
 * Usage:
 * $user = UserFactory::new()->create();
 * $users = UserFactory::new()->count(10)->create();
 */
abstract class Factory
{
    protected int $count = 1;
    protected array $states = [];
    protected array $afterCreating = [];
    protected array $afterMaking = [];

    /**
     * The model class this factory creates
     */
    abstract protected function model(): string;

    /**
     * Define the model's default state
     */
    abstract public function definition(): array;

    /**
     * Create a new factory instance
     */
    public static function new(): static
    {
        return new static();
    }

    /**
     * Set the number of models to create
     */
    public function count(int $count): static
    {
        $this->count = $count;
        return $this;
    }

    /**
     * Create model(s) and persist to database
     */
    public function create(array $attributes = []): Model|array
    {
        $models = $this->make($attributes);

        if (is_array($models)) {
            foreach ($models as $model) {
                $model->save();
                $this->callAfterCreating($model);
            }
        } else {
            $models->save();
            $this->callAfterCreating($models);
        }

        return $models;
    }

    /**
     * Create model(s) without persisting
     */
    public function make(array $attributes = []): Model|array
    {
        if ($this->count === 1) {
            $model = $this->makeInstance($attributes);
            $this->callAfterMaking($model);
            return $model;
        }

        $models = [];
        for ($i = 0; $i < $this->count; $i++) {
            $model = $this->makeInstance($attributes);
            $this->callAfterMaking($model);
            $models[] = $model;
        }

        return $models;
    }

    /**
     * Create a single model instance
     */
    protected function makeInstance(array $attributes = []): Model
    {
        $modelClass = $this->model();
        $definition = $this->definition();

        // Apply states
        foreach ($this->states as $state) {
            $stateAttributes = $this->$state();
            $definition = array_merge($definition, $stateAttributes);
        }

        // Apply custom attributes
        $definition = array_merge($definition, $attributes);

        return new $modelClass($definition);
    }

    /**
     * Apply a state to the factory
     */
    public function state(string $state): static
    {
        $this->states[] = $state;
        return $this;
    }

    /**
     * Add a callback to run after creating
     */
    public function afterCreating(callable $callback): static
    {
        $this->afterCreating[] = $callback;
        return $this;
    }

    /**
     * Add a callback to run after making
     */
    public function afterMaking(callable $callback): static
    {
        $this->afterMaking[] = $callback;
        return $this;
    }

    /**
     * Call after creating callbacks
     */
    protected function callAfterCreating(Model $model): void
    {
        foreach ($this->afterCreating as $callback) {
            $callback($model);
        }
    }

    /**
     * Call after making callbacks
     */
    protected function callAfterMaking(Model $model): void
    {
        foreach ($this->afterMaking as $callback) {
            $callback($model);
        }
    }

    // ========================================
    // Fake Data Generators
    // ========================================

    /**
     * Generate a random string
     */
    protected function randomString(int $length = 10): string
    {
        return substr(str_shuffle(str_repeat('abcdefghijklmnopqrstuvwxyz', $length)), 0, $length);
    }

    /**
     * Generate a random email
     */
    protected function randomEmail(): string
    {
        return $this->randomString(8) . '@example.com';
    }

    /**
     * Generate a random name
     */
    protected function randomName(): string
    {
        $firstNames = ['Ali', 'Ayşe', 'Mehmet', 'Fatma', 'Ahmet', 'Zeynep', 'Mustafa', 'Elif', 'Emre', 'Selin'];
        $lastNames = ['Yılmaz', 'Kaya', 'Demir', 'Çelik', 'Şahin', 'Yıldız', 'Aydın', 'Özdemir', 'Arslan', 'Doğan'];
        
        return $firstNames[array_rand($firstNames)] . ' ' . $lastNames[array_rand($lastNames)];
    }

    /**
     * Generate a random integer
     */
    protected function randomInt(int $min = 0, int $max = 1000): int
    {
        return random_int($min, $max);
    }

    /**
     * Generate a random float
     */
    protected function randomFloat(int $min = 0, int $max = 1000, int $decimals = 2): float
    {
        return round($min + mt_rand() / mt_getrandmax() * ($max - $min), $decimals);
    }

    /**
     * Generate a random boolean
     */
    protected function randomBool(): bool
    {
        return (bool) random_int(0, 1);
    }

    /**
     * Generate a random date
     */
    protected function randomDate(string $start = '-1 year', string $end = 'now'): string
    {
        $startTimestamp = strtotime($start);
        $endTimestamp = strtotime($end);
        $randomTimestamp = random_int($startTimestamp, $endTimestamp);
        
        return date('Y-m-d', $randomTimestamp);
    }

    /**
     * Generate a random datetime
     */
    protected function randomDateTime(string $start = '-1 year', string $end = 'now'): string
    {
        $startTimestamp = strtotime($start);
        $endTimestamp = strtotime($end);
        $randomTimestamp = random_int($startTimestamp, $endTimestamp);
        
        return date('Y-m-d H:i:s', $randomTimestamp);
    }

    /**
     * Pick a random element from array
     */
    protected function randomElement(array $array): mixed
    {
        return $array[array_rand($array)];
    }

    /**
     * Generate a UUID
     */
    protected function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    /**
     * Generate lorem ipsum text
     */
    protected function lorem(int $words = 10): string
    {
        $loremWords = [
            'lorem', 'ipsum', 'dolor', 'sit', 'amet', 'consectetur', 'adipiscing', 'elit',
            'sed', 'do', 'eiusmod', 'tempor', 'incididunt', 'ut', 'labore', 'et', 'dolore',
            'magna', 'aliqua', 'enim', 'ad', 'minim', 'veniam', 'quis', 'nostrud'
        ];

        $result = [];
        for ($i = 0; $i < $words; $i++) {
            $result[] = $loremWords[array_rand($loremWords)];
        }

        return ucfirst(implode(' ', $result));
    }

    /**
     * Generate a paragraph
     */
    protected function paragraph(int $sentences = 3): string
    {
        $result = [];
        for ($i = 0; $i < $sentences; $i++) {
            $result[] = $this->lorem(random_int(8, 15)) . '.';
        }
        return implode(' ', $result);
    }

    /**
     * Generate a hashed password
     */
    protected function hashedPassword(string $password = 'password'): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    /**
     * Generate a slug from text
     */
    protected function slug(string $text): string
    {
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9\s-]/', '', $text);
        $text = preg_replace('/[\s-]+/', '-', $text);
        return trim($text, '-');
    }
}
