<?php

namespace console\models;


use common\models\Item;
use common\models\Marketplace;
use common\models\Price;
use common\models\Promocode;
use common\models\Result;
use common\models\Size;

class DCShoes extends BaseGrab
{
	/**
	 * @return string
	 */
	public static function getCodename()
	{
		return 'dcshoes';
	}

	/**
	 * @return string
	 */
	protected static function getUrl()
	{
		return 'https://www.dcrussia.ru';
	}

	/**
	 * @return array
	 */
	protected static function getItemsListUrl()
	{
		return [
			static::SEX_MALE => [
				'https://www.dcrussia.ru/skidki-men-kedy-krossovki/?sz=48&start=#PAGE#',
			],
			static::SEX_FEMALE => [
				'https://www.dcrussia.ru/skidki-women-kedy-krossovki/?sz=48&start=#PAGE#',
				'https://www.dcrussia.ru/skidki-women-botinki/?sz=48&start=#PAGE#',
			],
		];
	}

	/**
	 * @return string
	 */
	protected function getItemsListPattern()
	{
		return "//div[contains(concat(' ', normalize-space(@class), ' '), ' product ')]";
	}

	/**
	 * @param \DOMElement $element
	 * @return string
	 */
	protected static function getItemTitle(\DOMElement $element)
	{
		$htmlParser = static::getHtmlParser();

		$titleRaw = $htmlParser->query("div[contains(@class, 'producttileinner')]/div[contains(@class, 'name')]/a", $element);
		if ($titleRaw->length > 0)
		{
			return parent::getItemTitle($titleRaw->item(0));
		}

		return false;
	}

	/**
	 * @param \DOMElement $element
	 * @return bool|string
	 * @throws \ErrorException
	 */
	protected static function getItemUrl(\DOMElement $element)
	{
		$htmlParser = static::getHtmlParser();

		$urlRaw = $htmlParser->query("div[contains(@class, 'producttileinner')]/div[contains(@class, 'name')]/a", $element);
		if ($urlRaw->length > 0)
		{
			return parent::getItemUrl($urlRaw->item(0));
		}

		return false;

	}

	/**
	 * @param $element
	 * @return bool
	 */
	protected static function isDiscount($element)
	{
		//div[contains(@class, 'price')]
		$htmlParser = static::getHtmlParser();
		$countPrice = $htmlParser->query("div[contains(@class, 'producttileinner')]/div[contains(@class, 'pricinginitial')]/div[contains(@class, 'pricing')]/div[contains(concat(' ', normalize-space(@class), ' '), ' data-price ')]/div[contains(concat(' ', normalize-space(@class), ' '), ' salesprice ')]", $element);

		return ($countPrice->length > 0);
	}

	/**
	 * @param string $url
	 * @return bool|int
	 */
	protected static function getMaxPage($url)
	{
		return 10;
	}

	/**
	 * @param $url
	 * @param array $itemPreset
	 * @return Result
	 */
	public function getItemValues($url, array $itemPreset = [])
	{
		if (IS_LOCAL)
		{
//			$url = 'https://www.dcrussia.ru/3613373735362.html';
//			$url = 'https://www.kupivip.ru/product/w17011783672/alina_assi-plate_futlyar_maksi';
		}

		$result = new Result();
		$htmlParser = $this->getItemDom($url);
		if ($htmlParser->isDomEmpty())
		{
			$result->addError(static::ERROR_CONTENT_EMPTY, 'ERROR_CONTENT_EMPTY');
			return $result;
		}

		$itemNameRaw = $htmlParser->parse("//h1");
		if ($itemNameRaw->length == 0)
		{
			$result->addError(static::ERROR_TITLE_NOT_FOUND, 'ERROR_TITLE_NOT_FOUND');
			return $result;
		}

		$fields = [
			'brand' => 'DC Shoes',
			'season' => Item::ITEM_SEASON_ALL
		];

		$itemNameRaw = $itemNameRaw->item(0);
		$itemName = static::clearText($itemNameRaw->textContent);
		$itemNameOriginal = $itemName;
		unset($itemNameRaw);

		$itemName = mb_strtolower(trim($itemName));
		if (!$this->isAllowItem($itemName))
		{
			$result->addError(static::ERROR_DISALLOW_TYPE_ITEM, 'ERROR_DISALLOW_TYPE_ITEM');
			return $result;
		}

		$fields['title'] = $itemNameOriginal;

		$categoryName = null;
		$originalCategoryName = null;

		$categoryName = $this->getCategoryFromString($itemName);
		if (!empty($categoryName))
		{
			$fields['category_name'] = $categoryName;
		}

		$seasonCode = $this->getSeasonFromString($itemName);
		if (!empty($seasonCode))
		{
			$fields['season'] = $seasonCode;
		}

		$breadCrumbsRaw = $htmlParser->parse("//a[contains(@class, 'd-breadcrumbs')]");
		if ($breadCrumbsRaw->length > 0)
		{
			foreach ($breadCrumbsRaw as $breadcrumbItem)
			{
				$breadcrumbText = static::clearText($breadcrumbItem->textContent);
				$sexCode = $this->getSexFromString($breadcrumbText);
				if (!empty($sexCode))
				{
					$fields['sex'] = $sexCode;
					break;
				}
			}
		}

		if (!empty($itemPreset['sex']) && empty($fields['sex']))
		{
			$fields['sex'] = $itemPreset['sex'];
		}

		if (empty($fields['sex']))
		{
			$result->addError(static::ERROR_SEX_NOT_FOUND, 'ERROR_SEX_NOT_FOUND');
			return $result;
		}

		unset($breadcrumbText, $sexCode, $breadCrumbsRaw, $breadcrumbItem);

		if (empty($fields['category_name']))
		{
			$result->addError(static::ERROR_CATEGORY_NOT_FOUND, 'ERROR_CATEGORY_NOT_FOUND');
			return $result;
		}

		$itemPriceRaw = $htmlParser->query("//div[contains(@class, 'salesprice')]");
		if ($itemPriceRaw->length > 0)
		{
			$price = static::clearText($itemPriceRaw->item(0)->textContent);
			$fields['discount_price'] = static::getNumber($price);
		}
		unset($itemPriceRaw);

		$itemPriceRaw = $htmlParser->query("//div[contains(@class, 'standardprice')]");
		if ($itemPriceRaw->length > 0)
		{
			$price = static::clearText($itemPriceRaw->item(0)->textContent);
			$fields['price'] = static::getNumber($price);
		}
		unset($itemPriceRaw);

		if (empty($fields['discount_price']) || empty($fields['price']) || ($fields['discount_price'] >= $fields['price']))
		{
			$result->addError(static::ERROR_WITHOUT_DISCOUNT, 'ERROR_WITHOUT_DISCOUNT');
			return $result;
		}

		$discountValue = $fields['discount_price'];
		$marketplace = Marketplace::getByCode(static::getCodename());
		if ($marketplace)
		{
			$discountValue = Promocode::getDiscountPrice($marketplace->id, $fields['discount_price']);
		}

		$fields['percent'] = static::getPercent($fields['price'], $discountValue);
		unset($discountValue);

		$itemSizesList = $htmlParser->parse("//li[contains(@class, 'variations-box-variation') and not(contains(@class, 'variant-off') )]/a");
		if ($itemSizesList->length > 0)
		{
			/**
			 * @var int $index
			 * @var \DOMElement $itemSizeRaw
			 */
			foreach ($itemSizesList as $index => $itemSizeRaw)
			{
				$sizeValue = static::getNumber($itemSizeRaw->getAttribute('title'));
				if (empty($sizeValue))
				{
					continue;
				}

				if (empty($fields['size_type']))
				{
					if (mb_strpos($sizeValue, Size::SIZE_EU) == 0)
					{
						$fields['size_type'] = Size::SIZE_EU;
					}
					elseif (mb_strpos($sizeValue, Size::SIZE_RU) == 0)
					{
						$fields['size_type'] = Size::SIZE_RU;
					}
				}
				$fields['sizes'][] = static::clearText($sizeValue);
				unset($sizeValue, $sizeIntValue);
			}
		}
		unset($itemSizesList, $itemSizeRaw);

		if (empty($fields['sizes']))
		{
			$result->addError(static::ERROR_SIZE_NOT_FOUND, 'ERROR_SIZE_NOT_FOUND');
			return $result;
		}

		$itemColor = $htmlParser->parse("//div[contains(@class, 'color')]/div[contains(@class, 'color')]/div[contains(@class, 'attrTitle')]/p/span[2]");
		if ($itemColor->length == 0)
		{
			$result->addError(static::ERROR_COLOR_NOT_FOUND, 'ERROR_COLOR_NOT_FOUND');
			return $result;
		}

		$fields['color'] = static::clearText($itemColor->item(0)->textContent);

		$itemArticle = $htmlParser->parse("//div[@id='master-product-id']");
		if ($itemArticle->length == 0)
		{
			$result->addError(static::ERROR_ARTICLE_NOT_FOUND, 'ERROR_ARTICLE_NOT_FOUND');
			return $result;
		}
		$article = static::clearText($itemArticle->item(0)->textContent);
		$articleList = explode(' ', $article);

		if (!empty($articleList))
		{
			if (count($articleList) == 2)
			{
				$article = $articleList[1];
			}
			elseif (count($articleList) > 2)
			{
				foreach ($articleList as $articleIndex => $articleRaw)
				{
					if ($articleIndex === 0)
					{
						continue;
					}

					$article .= (!empty($article) ? ' '. $article : '') . $articleRaw;
				}
			}
		}

		$fields['article'] = $fields['source_id'] = $article;


		$itemName = null;

		if (!empty($fields['title']))
		{
			$fields['model'] = str_replace($fields['category_name'], '', $fields['title']);
			$fields['model'] = static::clearRussianText($fields['model']);
			$fields['model'] = static::clearText($fields['model']);
		}



		if (empty($fields['model']) || mb_strlen($fields['model']) == 1)
		{
			$result->addError(static::ERROR_MODEL_NOT_FOUND, 'ERROR_MODEL_NOT_FOUND');
			return $result;
		}

		$itemImagesList = $htmlParser->parse("//a[contains(@class, 'main-image')]");
		if ($itemImagesList->length == 0)
		{
			$result->addError(static::ERROR_PARAMS_NOT_FOUND, 'ERROR_PARAMS_NOT_FOUND');
			return $result;
		}

		$imageUrl = trim($itemImagesList->item(0)->getAttribute('href'));
		if (strpos($imageUrl, 'http') === false)
		{
			$imageUrl = static::getUrl().$imageUrl;
		}

		if (empty($fields['imageUrl']))
		{
			$fields['imageUrl'] = trim($imageUrl);
		}
		unset($itemImagesList, $itemImage);

		$result->setData($fields);

		return $result;

	}


	/**
	 * @return int
	 */
	protected static function getStartPageNumber()
	{
		return 0;
	}

	/**
	 * @return int
	 */
	protected static function getPageStep()
	{
		return 48;
	}

	public static function getPriceByParams($itemId, array $params)
	{
		$query = Price::find()->where(['item_id' => $itemId, 'article' => $params['article']]);
		if (!empty($params['source']))
		{
			$query->andWhere(['source' => $params['source']]);
		}

		$prices = $query->all();
		if ($prices)
		{
			foreach ($prices as $price)
			{
				if ($price->color == $params['color'] || $price->url == $params['source_url'])
				{
					return $price;
				}
			}
		}

		return null;
	}


}