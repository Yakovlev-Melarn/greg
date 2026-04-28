<?php

namespace App\Libs;

use Illuminate\Support\Str;

class Helper
{
    protected static $map = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd',
        'е' => 'e', 'ё' => 'yo', 'ж' => 'zh', 'з' => 'z', 'и' => 'i',
        'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n',
        'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't',
        'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'ts', 'ч' => 'ch',
        'ш' => 'sh', 'щ' => 'shch', 'ъ' => '', 'ы' => 'y', 'ь' => '',
        'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
        'А' => 'A', 'Б' => 'B', 'В' => 'V', 'Г' => 'G', 'Д' => 'D',
        'Е' => 'E', 'Ё' => 'Yo', 'Ж' => 'Zh', 'З' => 'Z', 'И' => 'I',
        'Й' => 'Y', 'К' => 'K', 'Л' => 'L', 'М' => 'M', 'Н' => 'N',
        'О' => 'O', 'П' => 'P', 'Р' => 'R', 'С' => 'S', 'Т' => 'T',
        'У' => 'U', 'Ф' => 'F', 'Х' => 'H', 'Ц' => 'Ts', 'Ч' => 'Ch',
        'Ш' => 'Sh', 'Щ' => 'Shch', 'Ъ' => '', 'Ы' => 'Y', 'Ь' => '',
        'Э' => 'E', 'Ю' => 'Yu', 'Я' => 'Ya',
    ];

    public static function toLatin(string $string): string
    {
        $transliterated = str_replace(
            array_keys(static::$map),
            array_values(static::$map),
            $string
        );
        return Str::slug($transliterated, '-');
    }

    public static function seoUrl($url): string
    {
        $url = explode('/', $url);
        $url = end($url);
        return strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '', $url)));
    }

    public static function getBasketNumber($nmId): array
    {
        $mid = (int)($nmId / 1000);
        $small = (int)($mid / 100);
        if ($small < 144) {
            $basket = '01';
        } elseif ($small < 288) {
            $basket = '02';
        } elseif ($small < 432) {
            $basket = '03';
        } elseif ($small < 720) {
            $basket = '04';
        } elseif ($small < 1008) {
            $basket = '05';
        } elseif ($small < 1062) {
            $basket = '06';
        } elseif ($small < 1116) {
            $basket = '07';
        } elseif ($small < 1170) {
            $basket = '08';
        } elseif ($small < 1314) {
            $basket = '09';
        } elseif ($small < 1602) {
            $basket = '10';
        } elseif ($small < 1656) {
            $basket = '11';
        } elseif ($small < 1920) {
            $basket = '12';
        } elseif ($small < 2046) {
            $basket = '13';
        } elseif ($small < 2190) {
            $basket = '14';
        } elseif ($small < 2406) {
            $basket = '15';
        } elseif ($small < 2622) {
            $basket = '16';
        } elseif ($small < 2838) {
            $basket = '17';
        } elseif ($small < 3054) {
            $basket = '18';
        } elseif ($small < 3270) {
            $basket = '19';
        } elseif ($small < 3486) {
            $basket = '20';
        } elseif ($small < 3702) {
            $basket = '21';
        } elseif ($small < 3918) {
            $basket = '22';
        } elseif ($small < 4134) {
            $basket = '23';
        } elseif ($small < 4350) {
            $basket = '24';
        } elseif ($small < 4566) {
            $basket = '25';
        } elseif ($small < 4878) {
            $basket = '26';
        } elseif ($small < 5190) {
            $basket = '27';
        } elseif ($small < 5502) {
            $basket = '28';
        } elseif ($small < 5814) {
            $basket = '29';
        } elseif ($small < 6126) {
            $basket = '30';
        } elseif ($small < 6438) {
            $basket = '31';
        } elseif ($small < 6750) {
            $basket = '32';
        } elseif ($small < 7062) {
            $basket = '33';
        } elseif ($small < 7374) {
            $basket = '34';
        } elseif ($small < 7686) {
            $basket = '35';
        } elseif ($small < 7998) {
            $basket = '36';
        } elseif ($small < 8310) {
            $basket = '37';
        } elseif ($small < 8742) {
            $basket = '38';
        } elseif ($small < 9174) {
            $basket = '39';
        } elseif ($small < 9606) {
            $basket = '40';
        } else {
            $basket = '41';
        }
        return [
            'basket' => $basket,
            'mid' => $mid,
            'small' => $small
        ];
    }

    public static function getSupplier(string $vendorCode): int
    {
        if (str_starts_with($vendorCode, 'W')) {
            return 10;
        }
        if (str_starts_with($vendorCode, 'S')) {
            return 20;
        }
        return 0;
    }

    public static function getSupplierName(string $vendorCode): string
    {
        if (str_starts_with($vendorCode, 'W')) {
            return 'WB';
        }
        if (str_starts_with($vendorCode, 'S')) {
            return 'Sima-Land';
        }
        return 'TopGiper';
    }

    public static function getVendorCode(string $vendorCode): string
    {
        $vc = explode('-', $vendorCode);
        return $vc[2];
    }

    public static function prettyPrintArray($array, $level = 0): string
    {
        $indent = str_repeat('    ', $level); // 4 пробела на уровень вложенности
        $output = '';
        if ($level === 0) {
            $output .= "Array\n";
            $output .= str_repeat('-', 40) . "\n"; // разделитель в начале
        }
        $output .= $indent . "[\n";
        foreach ($array as $key => $value) {
            $keyDisplay = is_numeric($key) ? $key : "\"$key\"";
            if (is_array($value)) {
                $output .= $indent . "    {$keyDisplay} => array (\n";
                $output .= self::prettyPrintArray($value, $level + 1);
                $output .= $indent . "    )\n";
            } elseif (is_string($value)) {
                $output .= $indent . "    {$keyDisplay} => \"{$value}\"\n";
            } elseif (is_numeric($value)) {
                $output .= $indent . "    {$keyDisplay} => {$value}\n";
            } elseif (is_bool($value)) {
                $output .= $indent . "    {$keyDisplay} => " . ($value ? 'true' : 'false') . "\n";
            } else {
                $output .= $indent . "    {$keyDisplay} => " . gettype($value) . "\n";
            }
        }
        $output .= $indent . "]\n";
        if ($level === 0) {
            $output .= str_repeat('-', 40) . "\n"; // разделитель в конце
        }
        return $output;
    }

}
