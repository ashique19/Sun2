<?php

namespace Database\Seeders;

use App\Models\HeroSlide;
use Illuminate\Database\Seeder;

class HeroSlideSeeder extends Seeder
{
    public function run(): void
    {
        $slides = [
            [
                'title' => 'Sparkle with Tradition',
                'subtitle' => 'Handmade silver-plated jhumkas & layered necklaces',
                'image' => '/img/hero/slide-1.jpg',
                'link_url' => '#collection',
                'link_label' => 'Shop Collection',
                'display_order' => 1,
                'is_published' => true,
            ],
            [
                'title' => 'Handcrafted for Every Occasion',
                'subtitle' => 'Antique gold bangles & German silver collections',
                'image' => '/img/hero/slide-2.jpg',
                'link_url' => '#collection',
                'link_label' => 'Explore Jewellery',
                'display_order' => 2,
                'is_published' => true,
            ],
        ];

        foreach ($slides as $slide) {
            HeroSlide::query()->updateOrCreate(
                ['image' => $slide['image']],
                $slide,
            );
        }
    }
}
