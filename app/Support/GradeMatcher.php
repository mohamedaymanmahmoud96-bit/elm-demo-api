<?php

namespace App\Support;

class GradeMatcher
{
    public static function normalize(?string $grade): string
    {
        if ($grade === null || $grade === '') {
            return '';
        }
        $g = trim($grade);
        $g = str_replace(['الصف', ' '], '', $g);

        return mb_strtolower($g);
    }

    public static function matches(?string $courseGrade, ?string $studentGrade): bool
    {
        if ($studentGrade === null || $studentGrade === '') {
            return true;
        }
        if ($courseGrade === null || $courseGrade === '') {
            return true;
        }
        $a = self::normalize($courseGrade);
        $b = self::normalize($studentGrade);
        if ($a === $b) {
            return true;
        }

        return str_contains($a, $b) || str_contains($b, $a);
    }
}
