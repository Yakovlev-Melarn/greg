<?php

namespace App\Services;

use App\Models\SamsonCategory;

class CategoryPathService
{
    /**
     * Получить путь категории в виде "Корень/Подкатегория/..."
     *
     * @param int $categoryId
     * @return array
     */
    public static function getPathById(int $categoryId): array
    {
        $category = SamsonCategory::find($categoryId);
        if (!$category) {
            return []; // Категория не найдена
        }
        $pathParts = [];
        // Собираем все родительские категории, двигаясь вверх по дереву
        $current = $category;
        while ($current) {
            $pathParts[] = $current->name;
            $current = $current->parent; // переходим к родителю
        }
        // Разворачиваем массив (от корня к текущей категории)
        return array_reverse($pathParts);
    }
}
