<?php
namespace console\controllers;

use common\models\Price;
use console\models;
use Longman;


class GrabController extends BaseController
{
	protected static function getGrabberList()
	{

		return [
			models\Nike::class,
			models\NewBalance::class,
			models\BasketShop::class,
			models\SlamDunk::class,
			models\SuperStep::class,
			models\SportPoint::class,
			models\SneakerHead::class,
			models\Puma::class,
			models\FarFetch::class,
			models\DCShoes::class,
			models\Lamoda::class,

			models\Adidas::class,
			models\Reebok::class,
		];
	}

	public function actionGetItems()
	{
		foreach(static::getGrabberList() as $grabberClass)
		{
			try
			{
				/** @var models\BaseGrab $grabber */
				$grabber = $grabberClass::create();
				$grabber->getItems();
			}
			catch (\Exception $exception)
			{
				$chatId = \Yii::$app->params['telegram_admin_id'];

				$message = "Ошибка парсера ".$grabberClass."\n" . $exception->getMessage()."\n"
					."trace: \n". $exception->getTraceAsString();

				try
				{
					models\Bot::sendMessage($chatId, $message);
				}
				catch (\Exception $e)
				{

				}

			}

		}

		$this->comparePrices();
		$this->sendBrandStat();

		echo "done";
	}

	protected function comparePrices()
	{
		$priceIndex = [];
		if (IS_LOCAL)
		{
			$prices = Price::find()->where(['active' => '1'])->andWhere(['id' => [488, 4692]])->all();
		}
		else
		{
			$prices = Price::find()->where(['active' => '1'])->all();
		}

		if (!empty($prices))
		{
			/** @var Price $price */
			foreach ($prices as $price)
			{
				$priceIndex[$price->item_id][$price->article][$price->id] = $price;
			}
		}

		if (!empty($priceIndex))
		{
			foreach ($priceIndex as $itemId => $articleList)
			{
				if (empty($articleList))
				{
					continue;
				}

				foreach ($articleList as $article => $priceList)
				{
					if (count($priceList) == 1)
					{
						break;
					}

					$prevPrice = null;
					$prevCountSizes = null;
					$prevPriceId = null;

					$deactiveList = [];
					/**
					 * @var  $priceIndex
					 * @var Price $priceItem
					 */
					foreach ($priceList as $priceIndex => $priceItem)
					{
						$priceSizes = $priceItem->getAvailableSizes();
						$countSizes[$priceItem->article] = count($priceSizes);

						$isMoreCountSizes = (isset($prevCountSizes[$priceItem->article]) && $countSizes[$priceItem->article] > $prevCountSizes[$priceItem->article]);

						if (
							(!isset($prevPrice[$priceItem->article])
								|| ($priceItem->discount_price > $prevPrice[$priceItem->article] && $isMoreCountSizes)))
						{
							if (!empty($prevPriceId[$priceItem->article]))
							{
								$deactiveList[] = $priceList[$prevPriceId[$priceItem->article]];
							}

							$prevPrice[$priceItem->article] = $priceItem->discount_price;
							$prevPriceId[$priceItem->article] = $priceIndex;
							$prevCountSizes[$priceItem->article] = $countSizes[$priceItem->article];
						}
						else
						{
							$deactiveList[] = $priceItem;
						}
					}

					if (!empty($deactiveList))
					{
						foreach ($deactiveList as $deactivatePrice)
						{
							$deactivatePrice->active = 0;
							$deactivatePrice->save();
						}
					}
				}
			}
		}
	}

	public function actionUpdate()
	{
		$prices = Price::find()
			->where(['is not', 'image_url', null])
			->andWhere(['is', 'image', null])
			->all();
		foreach ($prices as $price)
		{
			$item = $price->item;
			if (!$item)
			{
				continue;
			}

			$url = $price->image_url;
			$code = $item->title."_".$price->article;

			echo "url: ".$url."\n";
			echo "code: ".$code."\n";

			$imageFile = models\BaseGrab::downloadImageByUrl($url, $code);
			if (!empty($imageFile))
			{
				$price->image = $imageFile;
				$price->save();
				echo "downloaded\n\n";
			}

		}
		echo "done";
	}

	public function sendBrandStat()
	{
		$chatId = \Yii::$app->params['telegram_admin_id'];
		$message = '';
		foreach(static::getGrabberList() as $grabberClass)
		{
			try
			{
				/** @var models\BaseGrab $grabber */
				$grabber = $grabberClass::create();

				$codeName = $grabber::getCodeName();
				$message .= $codeName.': ';

				$countMale = Price::find()->where(['active' => 1, 'source' => $codeName, 'sex' => Price::SEX_MALE])->count();
				$countFemale = Price::find()->where(['active' => 1, 'source' => $codeName, 'sex' => Price::SEX_FEMALE])->count();
				$message .= Price::SEX_MALE." = ".$countMale."; ".Price::SEX_FEMALE." = " . $countFemale. "\n";
			}
			catch (\Exception $exception)
			{
				$message = "Ошибка парсера ".$grabberClass."\n" . $exception->getMessage()."\n"
					."trace: \n". $exception->getTraceAsString();
			}
		}

		try
		{
			models\Bot::sendMessage($chatId, $message);
		}
		catch (\Exception $e)
		{

		}
	}
}