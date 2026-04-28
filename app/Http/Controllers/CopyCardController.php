<?php

namespace App\Http\Controllers;

use App\Jobs\WbJob;
use App\Libs\Helper;
use App\Libs\WBContent;
use App\Models\cards;
use App\Models\Sellers;
use App\Services\WildberriesService;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

class CopyCardController
{
    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ConnectionException
     */
    public function index(Request $request): Factory|View
    {
        if ($request->isMethod('POST')) {
            $result = $this->copy($request->all(), false);
            if (!$result['success']) {
                $request->session()->flash('error', $result['errorText']);
            } else {
                $request->session()->flash('success', $result['success']);
                WbJob::dispatch('getCardList', [
                    'seller_id' => session()->get('seller'),
                    'settings' => [
                        'settings' => [
                            'filter' => [
                                'textSearch' => $result['original_data']['variants'][0]['vendorCode'],
                                'withPhoto' => -1
                            ]
                        ]
                    ]
                ])->onQueue('updateCardsProcess')->delay(now()->addMinute());
            }
        }
        return view('CopyCard/index');
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ConnectionException
     */
    private function copy(array $post, $strictQuantity = true): array
    {
        $seller = Sellers::where('id', session()->get('seller'))->first();
        if ($this->cardIsExist((int)$post['nmID'], $seller->id)) {
            return ['success' => false, 'error' => ['message' => 'Карточка уже существует']];
        } else {
            $content = WBContent::getDetail($post['nmID']);
            if ($strictQuantity) {
                if ($content['totalQuantity'] < 5) {
                    return ['success' => false, 'error' => ['message' => 'Нет в наличии']];
                }
            }
            $info = WBContent::getCardInfo($post['nmID']);
            $service = new WildberriesService($seller->wb_api_key, $post);
            return $service->addProductFromSource($info);
        }
    }

    private function cardIsExist(int $nmID, int $sellerID)
    {
        return Cards::where('supplierVendorCode', $nmID)->where('sellerID', $sellerID)->exists();
    }
}
