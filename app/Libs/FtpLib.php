<?php

namespace App\Libs;

use App\Models\SamsonProduct;

class FtpLib
{
    public static function getImages($nmId, $count): array
    {
        return self::uploadWbImages($nmId, $count);
    }

    public static function uploadSamsonImages(SamsonProduct $product): array
    {
        $result = [];
        $i = 0;
        $photos = $product->files->where('type', 'photo');
        foreach ($photos as $photo) {
            $dir = "public/download/{$i}.jpg";
            $uploadDir = "public_html/image/catalog/products/{$product->sku}/{$i}.jpg";
            self::downloadFile($photo->url, $dir);
            if (self::uploadFile($dir, $uploadDir)) {
                $result[$i] = "catalog/products/{$product->sku}/{$i}.jpg";
            }
            $i++;
        }
        return $result;
    }

    public static function uploadWbImages($nmId, $count): array
    {
        $result = [];
        for ($i = 1; $i <= $count; $i++) {
            $basket = Helper::getBasketNumber($nmId);
            $dir = "public/download/{$i}.webp";
            $url = "https://basket-{$basket['basket']}.wbbasket.ru/vol{$basket['small']}/part{$basket['mid']}/{$nmId}/images/big/{$i}.webp";
            $uploadDir = "public_html/image/catalog/products/{$nmId}/{$i}.webp";
            self::downloadFile($url, $dir);
            if (self::uploadFile($dir, $uploadDir)) {
                $result[] = "catalog/products/{$nmId}/{$i}.webp";
            }
        }
        return $result;
    }

    public static function downloadFile($url, $dir): void
    {
        file_put_contents($dir, file_get_contents($url));
    }

    public static function uploadFile($fileDir, $uploadDir)
    {
        $connId = ftp_connect(env('FTP_SERVER'));
        $login_result = ftp_login($connId, env('FTP_LOGIN'), env('FTP_PASSWORD'));
        ftp_pasv($connId, true);
        if ($connId && $login_result) {
            self::checkDir(dirname($uploadDir), $connId);
            $upload = ftp_put($connId, $uploadDir, $fileDir, FTP_BINARY);
            ftp_close($connId);
            return $upload;
        }
    }

    public static function checkDir($dir, $connId): void
    {
        $folder_exists = is_dir('ftp://' . env('FTP_LOGIN') . ':' . env('FTP_PASSWORD') . '@' . env('FTP_SERVER') . '/' . $dir);
        if (!$folder_exists) {
            ftp_mkdir($connId, $dir);
        }
    }
}
