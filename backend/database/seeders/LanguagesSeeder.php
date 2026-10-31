<?php

namespace Database\Seeders;

use App\Models\Language;
use App\Models\PlatformTranslation;
use Illuminate\Database\Seeder;

/**
 * section 1.2: "Languages to ship: English (en), Tamil (ta), Hindi (hi)...
 * Machine-draft translations are acceptable. Flag needs_review in
 * DECISIONS" - see docs/DECISIONS.md D-0008.
 */
class LanguagesSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([['en', 'English'], ['ta', 'Tamil'], ['hi', 'Hindi']] as [$code, $name]) {
            Language::updateOrCreate(['code' => $code], ['name' => $name, 'is_active' => true]);
        }

        $keys = [
            'app.boot.title' => ['en' => 'Advanced Universal POS', 'ta' => 'மேம்பட்ட யுனிவர்சல் பிஓஎஸ்', 'hi' => 'एडवांस्ड यूनिवर्सल पीओएस'],
            'app.boot.subtitle' => ['en' => 'Loading tenant configuration…', 'ta' => 'கணக்கமைவு ஏற்றப்படுகிறது…', 'hi' => 'कॉन्फ़िगरेशन लोड हो रहा है…'],
            'auth.login.title' => ['en' => 'Sign in', 'ta' => 'உள்நுழைக', 'hi' => 'साइन इन करें'],
            'auth.login.shop_code' => ['en' => 'Shop code', 'ta' => 'கடை குறியீடு', 'hi' => 'शॉप कोड'],
            'auth.login.username' => ['en' => 'Username', 'ta' => 'பயனர் பெயர்', 'hi' => 'उपयोगकर्ता नाम'],
            'auth.login.password' => ['en' => 'Password', 'ta' => 'கடவுச்சொல்', 'hi' => 'पासवर्ड'],
            'auth.login.submit' => ['en' => 'Sign in', 'ta' => 'உள்நுழைக', 'hi' => 'साइन इन करें'],
            'auth.login.failed' => ['en' => 'Invalid shop code, username, or password.', 'ta' => 'தவறான கடை குறியீடு, பயனர் பெயர் அல்லது கடவுச்சொல்.', 'hi' => 'अमान्य शॉप कोड, उपयोगकर्ता नाम, या पासवर्ड।'],
            'auth.login.locked' => ['en' => 'This account is temporarily locked. Try again later.', 'ta' => 'இந்தக் கணக்கு தற்காலிகமாக முடக்கப்பட்டுள்ளது.', 'hi' => 'यह खाता अस्थायी रूप से लॉक है।'],
            'menu.pos' => ['en' => 'Point of Sale', 'ta' => 'விற்பனை முனையம்', 'hi' => 'बिक्री केंद्र'],
            'menu.catalog' => ['en' => 'Catalogue', 'ta' => 'பட்டியல்', 'hi' => 'कैटलॉग'],
            'menu.inventory' => ['en' => 'Inventory', 'ta' => 'சரக்கு', 'hi' => 'इन्वेंटरी'],
            'menu.sales' => ['en' => 'Sales', 'ta' => 'விற்பனை', 'hi' => 'बिक्री'],
            'menu.purchases' => ['en' => 'Purchases', 'ta' => 'கொள்முதல்', 'hi' => 'खरीद'],
            'menu.customers' => ['en' => 'Customers', 'ta' => 'வாடிக்கையாளர்கள்', 'hi' => 'ग्राहक'],
            'menu.finance' => ['en' => 'Finance', 'ta' => 'நிதி', 'hi' => 'वित्त'],
            'menu.reports' => ['en' => 'Reports', 'ta' => 'அறிக்கைகள்', 'hi' => 'रिपोर्ट'],
        ];

        foreach ($keys as $key => $perLanguage) {
            foreach ($perLanguage as $languageCode => $value) {
                PlatformTranslation::updateOrCreate(
                    ['language_code' => $languageCode, 'key' => $key],
                    ['value' => $value, 'needs_review' => $languageCode !== 'en'],
                );
            }
        }
    }
}
