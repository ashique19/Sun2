<?php

namespace Database\Seeders;

use App\Models\Page;
use Illuminate\Database\Seeder;

class PageSeeder extends Seeder
{
    public function run(): void
    {
        $pages = [
            ['slug' => 'about-us', 'name' => 'about-us'],
            ['slug' => 'privacy-policy', 'name' => 'privacy-policy'],
            ['slug' => 'terms-of-service', 'name' => 'terms-of-service'],
        ];

        foreach ($pages as $page) {
            Page::query()->updateOrCreate(
                ['slug' => $page['slug']],
                ['name' => $page['name']],
            );
        }
    }
}
