<?php

namespace Database\Seeders;

use App\Models\GradeLevel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class GradeLevelSeeder extends Seeder
{
    public function run(): void
    {
        $levels = [
            'الأول الابتدائي',
            'الثاني الابتدائي',
            'الثالث الابتدائي',
            'الرابع الابتدائي',
            'الخامس الابتدائي',
            'السادس الابتدائي',
            'الأول الإعدادي',
            'الثاني الإعدادي',
            'الثالث الإعدادي',
            'الأول الثانوي',
            'الثاني الثانوي',
            'الثالث الثانوي',
        ];

        foreach ($levels as $order => $name) {
            GradeLevel::firstOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name, 'sort_order' => $order + 1]
            );
        }
    }
}
