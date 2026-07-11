<?php

namespace Database\Seeders;

use App\Models\Page;
use Illuminate\Database\Seeder;

class PageSeeder extends Seeder
{
    public function run(): void
    {
        $pages = [
            [
                'slug' => 'about-us',
                'name' => 'About Us',
                'meta_tag_title' => 'About Us - Sundoritoma',
                'meta_tag_description' => 'Learn about Sundoritoma, traditional and imitation jewelry with home delivery across Bangladesh.',
                'details' => <<<'HTML'
<p>Sundoritoma is a Bangladesh-based jewelry store specializing in traditional and imitation jewelry — German silver, brass, beads, and handcrafted collections for everyday wear and special occasions.</p>
<p>From necklaces and earrings to bangles and festive sets, we curate pieces that blend heritage craftsmanship with contemporary style. Our goal is simple: beautiful jewelry that feels special, at accessible prices, delivered to your door.</p>
<p>We offer cash on delivery and home delivery all over Bangladesh so shopping stays easy wherever you are.</p>
<p><strong>Contact:</strong> 01880001255 &middot; info@sundoritoma.com</p>
<p><strong>Address:</strong> Dhaka, Bangladesh</p>
HTML,
            ],
            [
                'slug' => 'privacy-policy',
                'name' => 'Privacy Policy',
                'meta_tag_title' => 'Privacy Policy - Sundoritoma',
                'meta_tag_description' => 'How Sundoritoma collects and uses customer information for orders and support.',
                'details' => <<<'HTML'
<p>This Privacy Policy explains how Sundoritoma (“we”, “us”) collects and uses information when you shop on sundoritoma.com.</p>
<h2>Information we collect</h2>
<p>We collect details needed to process and deliver orders, such as your name, phone number, delivery address, and order history. If you create an account, we also store your login credentials securely.</p>
<h2>How we use your information</h2>
<p>Your information is used to fulfill orders, coordinate delivery, provide customer support, and improve our storefront experience. We do not sell your personal data to third parties.</p>
<h2>Sharing</h2>
<p>We may share limited order details with delivery partners solely to complete your shipment. Payment and courier partners process only what is required for their service.</p>
<h2>Data security</h2>
<p>We take reasonable steps to protect your information. Please keep your account password confidential and contact us if you suspect unauthorized access.</p>
<h2>Contact</h2>
<p>For privacy-related questions, email info@sundoritoma.com or call 01880001255.</p>
<p><em>Last updated: July 2026</em></p>
HTML,
            ],
            [
                'slug' => 'terms-of-service',
                'name' => 'Terms of Service',
                'meta_tag_title' => 'Terms of Service - Sundoritoma',
                'meta_tag_description' => 'Terms for shopping at Sundoritoma, including pricing, delivery, and returns.',
                'details' => <<<'HTML'
<p>Welcome to Sundoritoma. By browsing or placing an order on sundoritoma.com, you agree to the following terms.</p>
<h2>Products</h2>
<p>We sell traditional and imitation jewelry. Product images and descriptions are provided for reference; slight variations in color, finish, or handmade detail may occur.</p>
<h2>Pricing and payment</h2>
<p>All prices are listed in Bangladeshi Taka (৳). Cash on delivery is available subject to service area and order acceptance by our team. We reserve the right to cancel orders in case of pricing errors, stock issues, or suspected fraud.</p>
<h2>Delivery</h2>
<p>We provide home delivery all over Bangladesh. Delivery times depend on location and courier schedules. You are responsible for providing an accurate phone number and delivery address.</p>
<h2>Returns and exchanges</h2>
<p>Returns and exchanges are handled according to our customer care policy. Please contact us promptly if your order arrives damaged or incorrect so we can help resolve the issue.</p>
<h2>Contact</h2>
<p>For order assistance, call 01880001255 or email info@sundoritoma.com.</p>
<p><em>Last updated: July 2026</em></p>
HTML,
            ],
        ];

        foreach ($pages as $page) {
            Page::query()->updateOrCreate(
                ['slug' => $page['slug']],
                [
                    'name' => $page['name'],
                    'details' => $page['details'],
                    'meta_tag_title' => $page['meta_tag_title'],
                    'meta_tag_description' => $page['meta_tag_description'],
                ],
            );
        }
    }
}
