<?php

namespace App\Libs;

use App\Models\OcCategoryDescription;
use App\Models\OcProductDescription;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class Gpt
{
    private static string $categoryPrompt = 'Выступи в роли SEO специалиста.
    В интернет-магазине создана категория "%s".
    Напиши в формате json {meta_title,meta_description,meta_keyword,meta_h1,description}
    контент для этой категории. Не используй символ переноса строки "\n".
    Избегай слово Опт и все что связано с оптовыми продажами.
    Текст для description оформи красиво html разметкой.';
    private static string $productPrompt = 'Выступи в роли SEO специалиста.
    В интернет-магазине создан товар "%s".
    Напиши в формате json {name,meta_title,meta_description,meta_keyword,meta_h1,description, tag}
    контент для этой категории. Не используй символ переноса строки "\n".
    Избегай слово Опт и все что связано с оптовыми продажами.
    Текст для name короткий не более 80 символов.
    Текст для description не менее 1200 символов оформи красиво html разметкой.
    Текст для tag - это релевантные ключевые слова через запятую.
    Не ипользуй в описании характеристики: Страна производства, комплектацию, Габариты упаковки,
    Количество дизайнов в минимальной упаковке, Количество дизайнов в транспортной упаковке,
    Товар относится к сегменту ЭКОНОМ.
    Не используй в названии и описании артикулы, модели и иные тьехнические именования.
    Вот описание и характеристики товара:';

    /**
     * @throws ConnectionException
     */
    public static function category($prompt, $params)
    {
        if (!empty($params['categoryId'])) {
            $result = Http::withHeaders([
                "Content-Type" => "application/json",
                'Authorization' => 'Bearer ' . env("GPT_TOKEN"),
            ])
                ->timeout(3600)
                ->connectTimeout(3600)
                ->acceptJson()
                ->post("https://api.aitunnel.ru/v1/chat/completions", [
                    'model' => "gpt-5-nano",
                    'maxTokens' => '50000',
                    'messages' => [
                        ['role' => 'user', 'content' => sprintf(self::$categoryPrompt, $prompt)]
                    ]
                ]);
            $result = $result->json();
            $message = $result['choices'][0]['message']['content'];
            $data = json_decode($message, true);
            OcCategoryDescription::where('category_id', $params['categoryId'])->update($data);
            print_r($data);
        }
        return false;
    }

    /**
     * @param $prompt
     * @param $params
     * @return false
     * @throws ConnectionException
     * @throws \Exception
     */
    public static function product($prompt, $params)
    {
        if (!empty($params['productId'])) {
            $result = Http::withHeaders([
                "Content-Type" => "application/json",
                'Authorization' => 'Bearer ' . env("GPT_TOKEN"),
            ])
                ->timeout(3600)
                ->connectTimeout(3600)
                ->acceptJson()
                ->post("https://api.aitunnel.ru/v1/chat/completions", [
                    'model' => "gpt-5-nano",
                    'maxTokens' => '50000',
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => sprintf(self::$productPrompt, $prompt)
                                . $params['productDescription']
                        ]
                    ]
                ]);
            $result = $result->json();
            try {
                $message = $result['choices'][0]['message']['content'];
            } catch (\Exception $e) {
                print_r($result);
                throw new \Exception($e->getMessage());
            }
            $data = json_decode($message, true);
            OcProductDescription::where('product_id', $params['productId'])->update($data);
            print_r($data);
        }
        return false;
    }

    /**
     * @throws ConnectionException
     */
    public static function callGPT($prompt = null)
    {
        $result = Http::withHeaders([
            "Content-Type" => "application/json",
            'Authorization' => 'Bearer ' . env("GPT_TOKEN"),
        ])
            ->timeout(3600)
            ->connectTimeout(3600)
            ->acceptJson()
            ->post("https://api.aitunnel.ru/v1/chat/completions", [
                'model' => "gpt-5-nano",
                'maxTokens' => '50000',
                'messages' => [
                    ['role' => 'user', 'content' => "Скажи интересный факт"]
                ]
            ]);
        return $result->json();
    }
}
