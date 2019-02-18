<?php

namespace console\models;

use common\models\Error;
use common\models\BaseEntity;
use common\models\Brand;
use common\models\BrandItem;
use common\models\Category;
use common\models\Item;
use common\classes\HtmlParser;
use common\models\ItemBan;
use common\models\ItemPriceImage;
use common\models\Marketplace;
use common\models\Price;
use common\models\Rating;
use common\models\Result;
use Imagine\Image\Box;
use Imagine\Image\ImageInterface;
use linslin\yii2\curl\Curl;

abstract class BaseGrab
{
	const DAY_PAGE_CACHE = 86400;
	const HOUR_PAGE_CACHE = 3600;
	const MAX_EXISTS_ITEMS = 100;

	const IMAGE_RESIZE_HEIGHT = 600;
	const IMAGE_RESIZE_WIDTH = 263;

	const CURL_INSTANCE = '\linslin\yii2\curl\Curl';
	const HTML_PARSER_INSTANCE = '\common\classes\HtmlParser';

	const SEX_MALE = 'M';
	const SEX_FEMALE = 'F';
	const SEX_UNISEX = 'U';
	const SEX_KIDS = 'K';
	const SEX_KID_MALE = 'KM';
	const SEX_KID_FEMALE = 'KF';
	const SEX_KID_UNISEX = 'KU';

	const TYPE_SHOES = 'shoes';
	const TYPE_OUTERWEAR = 'outerwear';

	const ERROR_ITEM_NOT_FOUND = 'item not found';
	const ERROR_ITEM_NOT_AVAILABLE = 'item not available';
	const ERROR_TITLE_NOT_FOUND = 'title not found';
	const ERROR_TITLE_IS_EMPTY = 'title is empty';
	const ERROR_WRONG_TITLE = 'title wrong';
	const ERROR_ARTICLE_NOT_FOUND = 'article not found';
	const ERROR_CATEGORY_NOT_FOUND = 'category not found';
	const ERROR_SEX_NOT_FOUND = 'sex not found';
	const ERROR_DISALLOW_TYPE_ITEM = 'disallow type item';
	const ERROR_BRAND_NOT_FOUND = 'brand not found';
	const ERROR_MODEL_NOT_FOUND = 'model not found';
	const ERROR_WRONG_SEASON = 'wrong season';
	const ERROR_PARAMS_NOT_FOUND = 'params not found';
	const ERROR_SOLD_OUT = 'item sold out';
	const ERROR_WITHOUT_DISCOUNT = 'item without discount';
	const ERROR_SIZE_NOT_FOUND = 'size not found';
	const ERROR_COLOR_NOT_FOUND = 'color not found';
	const ERROR_LOW_DISCOUNT = 'discount is too low';
	const ERROR_IMAGES_NOT_FOUND = 'images not found';
	const ERROR_CONTENT_EMPTY = 'content is empty';
	const ERROR_LOW_SIZES = 'count sizes is too low';

	const LOG_LEVEL_LOW = 1;
	const LOG_LEVEL_MIDDLE = 2;
	const LOG_LEVEL_HIGH = 3;
	const LOG_LEVEL_CRITICAL = 4;

	protected static $instances = [];
	protected $params = [];

	protected $encoding = 'utf8';

	protected function __construct($encoding = null)
	{
		if (!empty($encoding))
		{
			$this->encoding = $encoding;
		}

		return $this;
	}

	/**
	 * @return null|string
	 */
	public function getEncoding()
	{
		return $this->encoding;
	}

	/**
	 * @param null $encoding
	 * @return BaseGrab
	 */
	public static function create($encoding = null)
	{
		return new static($encoding);
	}

	/**
	 * @return string
	 */
	public static function className()
	{
		return get_called_class();
	}

	/**
	 * @return array
	 */
	public function getRussianProxy()
	{
		return [
			[
				'ip' => '89.191.233.87:65234',
				'type' => CURLPROXY_SOCKS5,
				'auth' => 'thomail:B7n8AxI'
			],
		];
	}

	/**
	 * @return bool
	 * @throws \ErrorException
	 */
	public function setProxy()
	{
		static $goodList = [];
		static $badList = [];

		$curl = null;
		$found = false;
		static::log('get proxy', static::LOG_LEVEL_HIGH);
		$proxyList = $this->getRussianProxy();

		$countProxy = count($proxyList);
		$count = 0;
		while($count < $countProxy)
		{
			$proxyIp = null;
			$proxyType = null;

			$proxySelectList = $proxyList;

			if (!empty($goodList))
			{
				$proxySelectList = $goodList;
			}

			$proxyIndex = array_rand($proxySelectList);
			$proxyData = $proxySelectList[$proxyIndex];

			if (is_array($proxyData))
			{
				$proxyIp = $proxyData['ip'];
				if (isset($proxyData['type']))
				{
					$proxyType = $proxyData['type'];
				}

				if (isset($proxyData['auth']))
				{
					$proxyAuth = $proxyData['auth'];
				}
			}
			else
			{
				$proxyIp = $proxyData;
			}

			static::log('check '.$proxyIp, static::LOG_LEVEL_HIGH);

			$curl = static::getCurl();

			$curl->setOption(CURLOPT_PROXY, $proxyIp);

			if (!empty($proxyType))
			{
				$curl->setOption(CURLOPT_PROXYTYPE, $proxyType);
			}
			if (isset($proxyAuth))
			{
				$curl->setOption(CURLOPT_PROXYUSERPWD, $proxyAuth);
			}
			$curl->setOption(CURLOPT_TIMEOUT, 10);

			$options = ['dont_try_reconnect' => true];
			$content = static::getPage('https://tema.ru', -1, $options);

			if (mb_strpos($content, 'Lebedev') !== false)
			{
				static::log('good!', static::LOG_LEVEL_HIGH);
				$found = true;
				$curl->setOption(CURLOPT_TIMEOUT, 30);
				$goodList[$proxyIp] = $proxyData;
				break;
			}
			else
			{
				$badList[$proxyIp] = $proxyData;
				if (isset($goodList[$proxyIp]))
				{
					unset($goodList[$proxyIp]);
				}
				static::log('wrong', static::LOG_LEVEL_HIGH);

				if (empty($goodList) && count($badList) == count($proxyList))
				{
					break;
				}
			}

			$count++;
		}

		if (!$found)
		{
			$this->resetCurlOptions();
		}

		return $found;
	}

	/**
	 * @return array
	 * @throws \ErrorException
	 */
	public function getItems()
	{
		static::log("start");

		$statistic = [
			'added' => 0,
			'deleted' => 0,
			'updated' => 0,
		];

		$checkExistsItems = \Yii::$app->params['checkExistsItems'];

		$this->init();
//		$htmlParser = static::getHtmlParser();
		$countExistsItems = 0;

		$basePageUrlList = static::getItemsListUrl();

		$offset = 0;

		if (!is_array($basePageUrlList))
		{
			$basePageUrlList = [$basePageUrlList];
		}

		$parseSexList = (isset(\Yii::$app->params['parseSex']) ? \Yii::$app->params['parseSex'] : []);

		$oldPrices = [];
		$pageStep = static::getPageStep();
		try
		{
			$oldPrices = Price::loadBySource(static::getCodeName());
		}
		catch (\Exception $e)
		{

		}

		foreach ($basePageUrlList as $sexCode => $baseDumbPageUrlList)
		{
			if (!empty($parseSexList))
			{
				if (!in_array($sexCode, $parseSexList))
				{
					static::log("sex ".$sexCode." skip\n", static::LOG_LEVEL_HIGH);
					continue;
				}
			}

			if (!is_array($baseDumbPageUrlList))
			{
				$baseDumbPageUrlList = [$baseDumbPageUrlList];
			}

			foreach ($baseDumbPageUrlList as $baseDumbPageUrl)
			{
				$startPageNumber = static::getStartPageNumber();
				$stopPageNumber = static::getStopPageNumber();

				if (!$stopPageNumber)
				{
					$pageUrl = str_replace('#PAGE#', 1, $baseDumbPageUrl);

					$maxPage = static::getMaxPage($pageUrl);
					if ($maxPage > 0)
					{
						$stopPageNumber = $maxPage;
					}
				}

				if (!$stopPageNumber)
				{
					$stopPageNumber = 1;
				}

				$stopPageNumber *= $pageStep;

				for ($i = $startPageNumber; $i <= $stopPageNumber; $i += $pageStep)
				{
					static::log("sex: ".$sexCode.", page: ".$i." / ".$stopPageNumber, static::LOG_LEVEL_MIDDLE);

					$pageUrl = str_replace('#PAGE#', $i, $baseDumbPageUrl);

					if (strrpos($baseDumbPageUrl, '#OFFSET#') !== false)
					{
						$pageUrl = str_replace('#OFFSET#', $offset, $pageUrl);
					}

					$pageUrl = str_replace('#SEASON_PARAM#', '', $pageUrl);

					static::log('url: '.$pageUrl. "\n", static::LOG_LEVEL_MIDDLE);

					$itemList = $this->getListContent($pageUrl);
					if (!$itemList)
					{
						break;
					}

					$countItems = $this->countElements($itemList);
					if ($itemList && $countItems > 0)
					{
						$offset += $countItems;
						foreach ($itemList as $itemRaw)
						{
							if ($checkExistsItems && $countExistsItems >= static::MAX_EXISTS_ITEMS)
							{
								static::log("max exists items\n\nprocessing stoped\n", static::LOG_LEVEL_MIDDLE);
								break 2;
							}

							$itemPresetData = static::getItemPreset($itemRaw);
							if (empty($itemPresetData['title']))
							{
								static::log('item title is empty',static::LOG_LEVEL_HIGH);
								continue;
							}

							if (empty($itemPresetData['url']))
							{
								static::log('item url is empty',static::LOG_LEVEL_HIGH);
								continue;
							}

							if (!static::isDiscount($itemRaw))
							{
								static::log('item without discount',static::LOG_LEVEL_HIGH);
								continue;
							}

							$itemPresetData['sex'] = $sexCode;

							static::log("item: " . $itemPresetData['title']. " ".$itemPresetData['url'], static::LOG_LEVEL_HIGH);

							/** @var Item|null $item */
							$item = $this->prepareItem($itemPresetData);
							if (!$item)
							{
								$this->clearAllParams();
								static::log("item skip\n", static::LOG_LEVEL_HIGH);
								continue;
							}

							/** @var Price $price */
							$price = $this->getItemPrice();
							if (!$price)
							{
								$this->clearAllParams();
								static::log("price empty\n", static::LOG_LEVEL_HIGH);
								continue;
							}

							if ($item->isNew)
							{
								if (empty($price->sex))
								{
									$price->sex = $sexCode;
								}
							}
							else
							{
								foreach ($oldPrices as $oldIndex => $oldPrice)
								{
									if ($oldPrice->article == $price->article)
									{
										unset($oldPrices[$oldIndex]);
									}
								}
							}

							if ($item && static::needSaveItem($item, $this->getItemParams()))
							{
								try
								{
									if (!$item->save())
									{
										static::log('item not saved', static::LOG_LEVEL_HIGH);
										continue;
									}
								}
								catch (\Exception $e)
								{
									Error::create($item->source_url, $e->getMessage(), $e->getCode());
									static::error($e, $item->source_url, static::LOG_LEVEL_MIDDLE);
									$this->clearAllParams();
									continue;
								}


								if ($item->isNew)
								{
									$statistic['added'] += 1;
								}
								else
								{
									$statistic['updated'] += 1;
								}

								static::log("item " . ($item->isNew ? "added\n" : "updated"), static::LOG_LEVEL_HIGH);
								if (!$item->isNew)
								{
									if ($price->isNew)
									{
										static::log("new price add\n", static::LOG_LEVEL_HIGH);
									}
									else
									{
										static::log("price updated\n", static::LOG_LEVEL_HIGH);
									}
								}


								try
								{
									$this->includeAllParamsToItem($item);
								}
								catch (\Exception $e)
								{
									Error::create($item->source_url, $e->getMessage(), $e->getCode());

									static::error($e, $item->source_url, 2);
									$this->clearAllParams();
									continue;
								}

							}

							$this->clearAllParams();
						}
						unset($itemRaw);
					}
					else
					{
						break;
					}

					unset($pageListContent, $itemList);
				}
			}
		}

		if (!empty($oldPrices))
		{
			foreach ($oldPrices as $oldPrice)
			{
				$oldPrice->active = 0;
				try
				{
					$oldPrice->save();
				}
				catch (\Exception $e)
				{
					Error::create('priceId: '.$oldPrice->id, $e->getMessage(), $e->getCode());

					static::error($e, $oldPrice->url, 2);
					$this->clearAllParams();
					continue;
				}
			}
			unset($oldPrices);
		}

		static::log("done");

		return $statistic;
	}

	protected function init()
	{
		$this->resetCurlOptions();
		return true;
	}

	/**
	 * @return string|void
	 * @throws \ErrorException
	 */
	protected function getItemsListPattern()
	{
		throw new \ErrorException('The method getItemsListPattern is not inherited');
	}

	/**
	 * @return array|void
	 * @throws \ErrorException
	 */
	protected static function getItemsListUrl()
	{
		throw new \ErrorException('The method getItemsListUrl is not inherited');
	}

	/**
	 * @param \DOMElement $element
	 * @throws \ErrorException
	 */
	protected static function getItemId(\DOMElement $element)
	{
		throw new \ErrorException('The method getItemId is not inherited');
	}

	/**
	 * @return string
	 * @param \DOMElement $element
	 */
	protected static function getItemTitle(\DOMElement $element)
	{
		return static::clearText($element->textContent);
	}

	/**
	 * @param $element
	 * @return array
	 * @throws \ErrorException
	 */
	protected static function getItemPreset($element)
	{
		return [
			'title' => static::getItemTitle($element),
			'url' => static::getItemUrl($element),
		];
	}

	/**
	 * @param \DOMElement $element
	 * @return string
	 * @throws \ErrorException
	 */
	protected static function getItemUrl(\DOMElement $element)
	{
		$itemUrl = trim($element->getAttribute('href'));
		if (strpos($itemUrl, 'http') === false)
		{
			if (substr($itemUrl, 0, 1) != '/')
			{
				$itemUrl = '/' . $itemUrl;
			}

			$itemUrl = static::getUrl().$itemUrl;
		}

		return $itemUrl;
	}

	protected static function isDiscount($element)
	{
		return true;
	}

	/**
	 * @return int
	 */
	protected static function getStartPageNumber()
	{
		return 1;
	}

	/**
	 * @return int
	 */
	protected static function getStopPageNumber()
	{
		return false;
	}

	protected static function getPageStep()
	{
		return 1;
	}

	/**
	 * @param $url
	 * @param array $itemPreset
	 * @return Result|void
	 * @throws \ErrorException
	 */
	public function getItemValues($url, array $itemPreset = [])
	{
		throw new \ErrorException('The method getItemValues is not inherited');
	}

	/**
	 * @param $url
	 * @param array $options
	 * @return HtmlParser
	 */
	public function getItemDom($url, array $options = [])
	{
		$duration = -1;
		if (isset(\Yii::$app->params['cachedItemPage']) && \Yii::$app->params['cachedItemPage'] === true)
		{
			$duration = static::HOUR_PAGE_CACHE;
		}

		try
		{
			$pageItemRaw = static::getPage($url, $duration, $options);
		}
		catch (\Exception $e)
		{
		}

		$htmlParser = new HtmlParser();
		if (!empty($pageItemRaw))
		{
			$htmlParser->loadHtml($pageItemRaw, $this->getEncoding());
		}

		return $htmlParser;
	}
	/**
	 * @return array
	 */
	public function getAllParams()
	{
		return $this->params;
	}

	/**
	 * @return array
	 */
	public function clearAllParams()
	{
		return $this->params = [];
	}

	/**
	 * @return bool|array
	 */
	public function getItemParams()
	{
		$params = $this->getAllParams();
		return (isset($params['PARAMS']) ? $params['PARAMS'] : false);
	}

	/**
	 * @param array $params
	 */
	public function setItemParams(array $params = [])
	{
		$this->params['PARAMS'] = (isset($this->params['PARAMS']) ? $this->params['PARAMS'] : []) + $params;
	}

	/**
	 * @param Price $price
	 */
	public function setItemPrice(Price $price)
	{
		$this->params['PRICE'] = $price;
	}

	/**
	 * @return bool|Price
	 */
	public function getItemPrice()
	{
		$params = $this->getAllParams();
		return (isset($params['PRICE']) ? $params['PRICE'] : false);
	}


	/**
	 * @param array $images
	 */
	public function setPriceImages(array $images)
	{
		$this->params['IMAGES'] = $images;
	}

	/**
	 * @return bool|array
	 */
	public function getPriceImages()
	{
		$params = $this->getAllParams();
		return (isset($params['IMAGES']) ? $params['IMAGES'] : false);
	}

	/**
	 * @param $url
	 * @return bool|string
	 */
	protected function getCountPages($url)
	{
		$countAllPages = false;
		try
		{
			$pageContent = static::getPage($url);
		}
		catch (\Exception $e)
		{
			return false;
		}

		$htmlParser = new HtmlParser();
		$htmlParser->loadHtml($pageContent, $this->getEncoding());
		unset($pageContent);

		try
		{
			$pageCountPages = $htmlParser->parse(static::getCountPagePattern());
			if ($pageCountPages->length > 0)
			{
				$countAllPages = $pageCountPages->item(0)->textContent;
			}
		}
		catch (\Exception $e)
		{

		}

		return $countAllPages;
	}

	/**
	 * @return string|void
	 * @throws \ErrorException
	 */
	protected static function getCountPagePattern()
	{
		throw new \ErrorException('The method getCountPagePattern is not inherited');
	}

	/**
	 * @param array $preset
	 * @return bool|Item
	 * @throws \ErrorException
	 */
	protected function prepareItem(array $preset)
	{
		$itemTitle = $preset['title'];
		$itemUrl = $preset['url'];
		$sexCode = $preset['sex'];

		$itemParams = [];
		try
		{
			/** @var Result $r */
			$r = $this->getItemValues($itemUrl, $preset);
			unset($preset);

			$this->setItemParams($r->getData());
			$itemParams = $this->getItemParams();
		}
		catch (\Exception $e)
		{
			Error::create($itemUrl, $e->getMessage(), $e->getCode());

			static::error($e, $itemUrl, 2);
		}

		if (isset($r) && !$r->isSuccess())
		{

			$errors = $r->getErrors();
			if (!empty($errors))
			{
				foreach ($errors as $codeError => $errorText)
				{
					static::log($itemTitle. ": ".$errorText, static::LOG_LEVEL_CRITICAL);
					if (static::allowSaveError($errorText, $itemParams))
					{
						Error::create($itemUrl, $errorText, $codeError);
					}
				}
			}

			$price = Price::getByUrl($itemUrl);
			if ($price)
			{
				$price->active = 0;
				$price->save();
			}

			return false;
		}

		$discountSex = 'M';
		if (!empty($itemParams['sex']))
		{
			$discountSex = $itemParams['sex'];
		}

		$minDiscountPercent = static::getMinDiscountPercent($discountSex);

		if (!empty($minDiscountPercent) && isset($itemParams['percent']))
		{
			if ($itemParams['percent'] < $minDiscountPercent)
			{
				static::log($itemTitle. ": ". static::ERROR_LOW_DISCOUNT . " [".$itemParams['percent']."]", static::LOG_LEVEL_CRITICAL);

				if (static::allowSaveError(static::ERROR_LOW_DISCOUNT, $itemParams))
				{
					Error::create($itemUrl, static::ERROR_LOW_DISCOUNT . " [".$itemParams['percent']."]", 'ERROR_LOW_DISCOUNT');
				}

				$price = Price::getByUrl($itemUrl);
				if ($price)
				{
					$price->active = 0;
					try
					{
						$price->save();
					}
					catch (\Exception $e)
					{
						if (static::allowSaveError($e->getCode(), $itemParams))
						{
							Error::create('priceId: '.$price->id, $e->getMessage(), $e->getCode());
						}
						static::error($e, $price->url, static::LOG_LEVEL_MIDDLE);
						$this->clearAllParams();
					}
				}

				return false;
			}
		}

		if (!static::checkSizes($itemParams))
		{
			static::log($itemTitle. ": ". static::ERROR_LOW_SIZES . " [".$itemParams['percent']."]", static::LOG_LEVEL_CRITICAL);
			if (static::allowSaveError(static::ERROR_LOW_SIZES, $itemParams))
			{
				Error::create($itemUrl, static::ERROR_LOW_SIZES . " [".$itemParams['percent']."]", 'ERROR_LOW_SIZES');
			}

			$price = Price::getByUrl($itemUrl);
			if ($price)
			{
				$price->active = 0;
				try
				{
					$price->save();
				}
				catch (\Exception $e)
				{
					if (static::allowSaveError($e->getCode(), $itemParams))
					{
						Error::create('priceId: '.$price->id, $e->getMessage(), $e->getCode());
					}
					static::error($e, $price->url, static::LOG_LEVEL_MIDDLE);
					$this->clearAllParams();
				}
			}

			return false;
		}

		$this->setItemParams([
								  'source_url' => $itemUrl,
								  'source' => static::getCodeName(),
							  ]);

		$itemParams = static::prepareItemAttributes($this->getItemParams());
		if (!static::checkModel($itemParams))
		{
			static::log($itemTitle. ": ".static::ERROR_MODEL_NOT_FOUND, static::LOG_LEVEL_CRITICAL);
			if (static::allowSaveError(static::ERROR_MODEL_NOT_FOUND, $itemParams))
			{
				Error::create($itemUrl, static::ERROR_MODEL_NOT_FOUND, 'ERROR_MODEL_NOT_FOUND');
			}
			return false;
		}

		if (!static::checkSeason($itemParams))
		{
			static::log($itemTitle. ": ".static::ERROR_WRONG_SEASON, static::LOG_LEVEL_CRITICAL);
			if (static::allowSaveError(static::ERROR_WRONG_SEASON, $itemParams))
			{
				Error::create($itemUrl, static::ERROR_WRONG_SEASON, 'ERROR_WRONG_SEASON');
			}
			return false;
		}

		$item = $this->searchItem($itemParams);
		if (!$item)
		{
			$item = new Item();
			$item->active = 1;

			$item->isNew = true;
		}
		else
		{
			$itemBan = ItemBan::find()->where(['item_id' => $item->id])->one();
			if ($itemBan)
			{
				$item->active = 0;
				return $item;
			}
		}
//		else
//		{
//			$models = $item->modelAlias;
//			if (!empty($models))
//			{
//				print_R($models);
//			}
//
//		}

//		static::log('item '.($item->isNew) ? 'new' : 'old');
		$price = null;

		if ($item->isNew)
		{
			$item->scenario = 'add-item';

			if (!empty($itemParams['category_name']))
			{
				$categoryId = static::prepareCategory($itemParams['category_name']);
				if (!empty($categoryId))
				{
					$item->category_id = $categoryId;
				}

				unset($itemParams['category_name']);
			}

//			$item->image_url = $itemParams['imageUrl'];

			if (!empty($itemParams))
			{
				$item->setAttributes($itemParams);
			}
		}
		else
		{
			$item->scenario = 'update-item';

			static::preUpdateItemAttributes($item, $itemParams);

//			Price::deactivateByArticle($item->id, $itemParams['article']);
			$price = static::getPriceByParams($item->id, $itemParams);
		}

		if (empty($price))
		{
			$price = new Price();
			$price->isNew = true;
			$price->source = $itemParams['source'];
		}
		else
		{
			$itemBan = ItemBan::find()->where(['price_id' => $price->id])->one();
			if ($itemBan)
			{
				$price->active = 0;
				$price->save();
				return false;
			}
		}

		if (!isset($itemParams['sex']))
		{
			$itemParams['sex'] = $sexCode;
		}

		$this->setPriceAttributes($price, $itemParams);
		if ($price->active == 0)
		{
			try
			{
				$price->save();
			}
			catch (\Exception $e)
			{
				if (static::allowSaveError($e->getCode(), $itemParams))
				{
					Error::create('priceId: '.$price->id, $e->getMessage(), $e->getCode());
				}
				static::error($e, $price->url, 2);
				$this->clearAllParams();
			}
			return false;
		}

		if (!empty($itemParams['images']))
		{
			$images = unserialize($itemParams['images']);
			if (is_array($images) && !empty($images))
			{
				$this->setPriceImages($images);
			}
		}

		$this->setItemPrice($price);

		unset($htmlParser);

//		$result->setData(['item' => $item]);

		return $item;
	}

	/**
	 * @param string $sex
	 * @return bool|int
	 */
	public static function getMinDiscountPercent($sex = 'M')
	{
		$minDiscountPercent = \Yii::$app->params['minDiscountPercent'];

		if (!empty($minDiscountPercent))
		{
			if (!is_array($minDiscountPercent))
			{
				return $minDiscountPercent;
			}
			else if (!empty($minDiscountPercent[$sex]))
			{
				return $minDiscountPercent[$sex];
			}
		}

		return false;
	}

	/**
	 * @return int
	 */
	public static function getMinCountSizes()
	{
		return \Yii::$app->params['minCountSizes'];
	}

	/**
	 * @param array $itemParams
	 * @return bool
	 */
	public function checkSizes(array $itemParams)
	{
		$minCountSizes = static::getMinCountSizes();
		if (!empty($minCountSizes) && isset($itemParams['sizes']))
		{
			$itemSizes = [];
			if (!empty($itemParams['sizes']))
			{
				$itemSizes = $itemParams['sizes'];
				if (!is_array($itemParams['sizes']))
				{
					$itemSizes = unserialize($itemParams['sizes']);
				}
			}

			return (count($itemSizes) >= $minCountSizes);
		}

		return true;
	}

	protected static function preUpdateItemAttributes(Item $item, array $params = [])
	{
		return true;
	}

	protected static function checkModel(array $params = [])
	{
		return (!empty($params['model']));
	}

	protected static function checkSeason(array $params = [])
	{
		return true;
	}

	/**
	 * @param $code
	 * @param array $params
	 * @return bool
	 */
	protected static function allowSaveError($code, array $params = [])
	{
		return true;
	}

	public static function getCurrentSeason()
	{
		return static::getSeasonByDate(date('Y-m-d'));
	}

	/**
	 * @param string $date
	 * @return bool|string
	 */
	public static function getSeasonByDate($date)
	{
		$nowMonth = date('n', strtotime($date));
		$seasons = Item::getSeasons();

		foreach ($seasons as $monthCode => $monthCodeList)
		{
			if (in_array($nowMonth, $monthCodeList))
			{
				return $monthCode;
			}
		}

		return false;
	}

	/**
	 * @param array $params
	 * @return array
	 */
	protected static function prepareItemAttributes(array $params = [])
	{
		if (empty($params))
		{
			return $params;
		}

		if (!empty($params['brand']))
		{
			$params['brand'] = static::clearText($params['brand']);

			$brand = Brand::getByName($params['brand']);
			if ($brand)
			{
				$params['brand'] = $brand->name;
			}
			unset($brand);
		}

		if (!empty($params['category_name']))
		{
			$params['category_name'] = static::clearText($params['category_name']);

			$category = Category::getByName($params['category_name']);
			if ($category)
			{
				$params['category_name'] = $category->name;
			}
			unset($category);
		}

		if (!empty($params['model']) && !empty($params['brand']))
		{
			$params['model'] = str_replace([$params['category_name'], $params['brand']], '', $params['model']);
		}

		$itemTitle = static::prepareItemTitle($params);
		if (!empty($itemTitle))
		{
			$params['title'] = $itemTitle;
		}

		$result = [];
		foreach ($params as $name => $value)
		{
			if (is_array($value))
			{
				if ($name == 'sizes')
				{
					sort($value);
				}
				$value = serialize($value);
			}
			elseif ($name == 'title' || $name == 'brand' || $name == 'model')
			{
				$value = static::clearText($value);
			}
			elseif ($name == 'article')
			{
				$value = static::clearText(mb_strtoupper($value));
			}
			elseif ($name == 'season')
			{
				$value = Item::getSeasonCode($value);
			}

			$result[$name] = $value;
		}

		return $result;
	}

	protected static function prepareItemTitle(array $params = [])
	{
		if (!empty($params['model']) && !empty($params['brand']))
		{
			return $params['brand'].' '.$params['model'];
		}

		return false;
	}

	protected function setPriceAttributes(Price $price, array $attributes = [])
	{
		$marketplaceId = Marketplace::getIdByCode($attributes['source']);

		$active = 1;
		$attributeKeys = array_fill_keys(array_keys($attributes), true);
		if (isset($attributeKeys['active']))
		{
			$active = $attributeKeys['active'];
		}

		$price->setAttributes([
								  'article' => $attributes['article'],
								  'price' => $attributes['price'],
								  'discount_price' => $attributes['discount_price'],
								  'percent' => $attributes['percent'],
								  'size_type' => $attributes['size_type'],
								  'sizes' => $attributes['sizes'],
								  'marketplace_id' => $marketplaceId,
								  'url' => $attributes['source_url'],
								  'sex' => $attributes['sex'],
								  'active' => $active,
							  ]);

		if (!empty($attributes['color']))
		{
			$price->setAttribute('color', $attributes['color']);
		}

		if (!empty($attributes['color_title']))
		{
			$price->setAttribute('color_title', $attributes['color_title']);
		}

		if (!empty($attributes['material']))
		{
			$price->setAttribute('material', $attributes['material']);
		}

		if (!empty($attributes['material_title']))
		{
			$price->setAttribute('material_title', $attributes['material_title']);
		}

		if (!empty($attributes['imageUrl']))
		{
			$this->applyImage($price, $attributes['imageUrl']);
		}

		return $price;
	}

	protected function applyImage(Price $price, $imageUrl)
	{
		if (empty($price->image))
		{
			$price->image_url = $imageUrl;
		}

		return $price;
	}


	protected static function prepareImageUrl($url)
	{
		return $url;
	}
	/**
	 * @param string $url
	 * @return bool|int
	 */
	protected static function getMaxPage($url)
	{
		return false;
	}

	/**
	 * @param $url
	 * @return bool|\DOMNodeList
	 * @throws \ErrorException
	 */
	protected function getListContent($url)
	{
		$pageListContent = static::getPage($url, -1);

		$htmlParser = static::getHtmlParser();
		$htmlParser->loadHtml($pageListContent, $this->getEncoding());

		try
		{
			$pattern = $this->getItemsListPattern();
			return $htmlParser->parse($pattern);
		}
		catch (\Exception $e)
		{

		}
		return false;
	}

	protected function countElements($elements)
	{
		$count = false;
		if ($elements instanceof \DOMNodeList)
		{
			$count = $elements->length;
		}
		elseif (is_array($elements))
		{
			$count = count($elements);
		}

		return $count;
	}

	public static function getPercent($price, $discountPrice)
	{
		return ceil(abs((($discountPrice - $price) / $price) * 100));
	}

	/**
	 * @param string$url
	 * @param null|int $duration
	 * @param null|array $options
	 * @return string
	 * @throws \ErrorException
	 */
	protected static function getPage($url, $duration = null, array $options = [])
	{
		$cache = \Yii::$app->getCache();
		if ($duration === null)
		{
			$duration = static::HOUR_PAGE_CACHE;
		}

		$cacheId = static::getCodeName() . '.' . md5($url);
		$pageCache = $cache->get($cacheId);
		if ($duration > 0 && $pageCache)
		{
			$pageContent = $pageCache;
		}
		else
		{
			$pause = static::getPause();
			if ($pause > 0)
			{
				static::log("Sleep ".$pause." sec.", 2);
				sleep($pause);
			}

			$curl = static::getCurlByUrl($url, $options);
			$pageContent = $curl->response;
			if (!empty($pageContent) && $duration > 0)
			{
				$cache->set($cacheId, $pageContent, $duration);
			}

		}

		return $pageContent;
	}

	public static function getPause()
	{
		return false;
	}

	/**
	 * @param string $url
	 * @param array $options
	 * @return Curl
	 */
	public static function getCurlByUrl($url, $options = [])
	{
		$curl = static::getCurl();

		$curlRequest = clone $curl;

		if (!empty($options))
		{
			$curlOptions = $curlRequest->getOptions();
			foreach ($curlOptions as $keyOption => $valueOption)
			{
				if (isset($options[$keyOption]))
				{
					$setOption = $valueOption;
					if (is_array($valueOption))
					{
						$setOption = array_merge($setOption, $options[$keyOption]);
					}

					$curlRequest->setOption($keyOption, $setOption);
				}
			}
		}

		$allowHttpCodes = [
			200,
			302,
			301
		];
		$limit = 5;
		$count = 0;
		$timeout = 5;
		while(1)
		{
			$pageContent = $curlRequest->get($url, true);
			if (!in_array($curlRequest->responseCode, $allowHttpCodes))
			{
				$curlRequest->response = null;
				static::log('page '.$url.' disallow [ code : ' . $curlRequest->responseCode . ' ]');
				break;
			}

			if (!empty($pageContent))
			{
				break;
			}
			else
			{
				if ($curlRequest->errorCode == 7 || $curlRequest->errorCode == 28)
				{
					if (isset($options['dont_try_reconnect']))
					{
						static::log("Couldn't connect to server. Continue.", 2);
						break;
					}
					if ($count >= $limit)
					{
						static::log("Couldn't connect to server. I try to connect ".$limit." times! It's wrong... Break!");
						break;
					}

					$count++;

					if ($count == 1)
					{
						static::log("\nurl: ".$url);
					}
					$timeout *= $count;
					static::log("Couldn't connect to server".($count > 0 ? ". Again.." : "").". Sleeping ".$timeout." seconds[".$count."/".$limit."]");

					sleep($timeout);

					continue;
				}
				else
				{
					static::log('page '.$url.' not found [' . $curlRequest->errorText . '' . $curlRequest->errorCode . ']');
					break;
				}
			}
		}

		return $curlRequest;
	}

	public function prepareTitle($title)
	{
		return $title;
	}


	protected static function prepareCategory($categoryName)
	{
		$category = Category::getByName($categoryName);
		if (!$category)
		{
			$category = new Category();
			$category->name = $categoryName;
			$category->code = BaseEntity::makeCode($categoryName);
			$category->save();
		}

		return $category->id;
	}

	/**
	 * @param string $url
	 */
	protected static function deletePageCache($url)
	{
		$codeName = null;
		try
		{
			$codeName = static::getCodeName();
		}
		catch (\Exception $e)
		{

		}

		$cacheId = $codeName . '.' . md5($url);
		$cache = \Yii::$app->getCache();
		$cache->delete($cacheId);
	}

	/**
	 * @return string|void
	 * @throws \ErrorException
	 */
	public static function getCodeName()
	{
		throw new \ErrorException('The method getCodeName is not inherited');
	}

	/**
	 * @return string|void
	 * @throws \ErrorException
	 */
	protected static function getUrl()
	{
		throw new \ErrorException('The method getUrl is not inherited');
	}

	/**
	 * @param array $itemParams
	 * @return bool
	 */
	protected static function needDownloadImage(array $itemParams = array())
	{
		return true;
	}

	/**
	 * @param Item $item
	 * @param array $itemParams
	 * @return bool
	 */
	protected static function needSaveItem(Item $item, array $itemParams = array())
	{
		return true;
	}

	/**
	 * @param Item $item
	 * @return bool|string
	 */
	public function downloadImage(Item $item)
	{
		/** @var Price $price */
		$price = $this->getItemPrice();

		$url = $price->image_url;
		$code = $item->title."_".$price->article;

		return static::downloadImageByUrl($url, $code);
	}

	/**
	 * @param string $url
	 * @param string $filename
	 * @return bool|string
	 */
	public static function downloadImageByUrl($url, $filename)
	{
		$url = static::prepareImageUrl($url);

		$curl = static::getCurl();
		$curl->setOption(CURLOPT_SSL_VERIFYPEER, false);

		$uploadDir = \Yii::$app->params['upload_dir'];
		$itemsImagesDir = \Yii::$app->params['items_images'];
		$webRoot = \Yii::getAlias('@frontend/web');

		$filename = BaseEntity::makeCode($filename);
		$checkFilename = $filename;

		while(1)
		{
			if (!file_exists($webRoot.'/'.$uploadDir.'/'.$itemsImagesDir.'/'.$checkFilename.'.png'))
			{
				$filename = $checkFilename;
				break;
			}
			$checkFilename = $filename."_".uniqid(intval(rand()), true);
		}

		$filenameThumb = $filename.'_thumb';
		$imageThumb = $filenameThumb.'.png';

		$countTry = 1;
		$imageSize = null;

		$imgSrc = null;

		while(1)
		{
			$imgSrc = $curl->get($url);

//			static::log('try '.$countTry);
			if (empty($imgSrc) || $curl->responseCode != 200)
			{
				if ($curl->errorCode == 28 || $curl->errorCode == 7)
				{
					if ($countTry > 3)
					{
						static::log("image don't downloaded.\nTimeout...\nBreak");
						return false;
					}
					static::log("image don't downloaded. Timeout... wait 3 seconds");
					sleep(3);
					static::log("try again [" . $countTry . "]...");
					$countTry++;
				}
				elseif ($curl->responseCode == 404)
				{
					static::log('image '.$url.' not found');
					Error::create($url, 'IMAGE NOT FOUND', 'IMAGE_NOT_FOUND');
					return false;
				}
				else
				{
					static::log('image '.$url.' not downloaded [' . $curl->errorText . ' ' . $curl->errorCode . ']');
					return false;
				}
			}
			else
			{
//				static::log('image get');
				break;
			}
		}

		if (empty($imgSrc))
		{
			return false;
		}

		$imageTypes = [
			'image/',
			'jpg',
			'jpeg',
		];

		$foundImageType = false;
		foreach ($imageTypes as $imageType)
		{
			if (mb_strpos($curl->responseType, $imageType) === 0)
			{
				$foundImageType = true;
				break;
			}
		}

		if (!$foundImageType)
		{
			return false;
		}

//		static::log('create image');
		$pngFile = $webRoot.'/'.$uploadDir.'/'.$itemsImagesDir.'/'.$filename.'.png';
		$thumbFile = $webRoot.'/'.$uploadDir.'/'.$itemsImagesDir.'/'.$imageThumb;

		static::createImage($curl->responseType, $imgSrc, $pngFile);

		unset($imgSrc);
		if (file_exists($pngFile))
		{
			$imgImage = \yii\imagine\Image::getImagine()->open($pngFile);
			$imageSize = $imgImage->getSize();
			$box = new Box($imageSize->getWidth(), $imageSize->getHeight());

			$imgImage = $imgImage->resize($box->heighten(static::IMAGE_RESIZE_HEIGHT));
			$imgImage = static::prepareImage($imgImage);
			$imgImage->save($pngFile, ['quality' => 95]);

			$box = new Box($imageSize->getWidth(), $imageSize->getHeight());

			$imgImage->thumbnail($box->widen(static::IMAGE_RESIZE_WIDTH), ImageInterface::THUMBNAIL_OUTBOUND)->save($thumbFile, ['quality' => 95]);

			unset($imgImage, $box);
		}

		if (file_exists($webRoot.'/'.$uploadDir.'/'.$itemsImagesDir.'/'.$filename.'.png'))
		{
			return $filename.'.png';
		}

		return false;
	}

	/**
	 * @param $type
	 * @param $src
	 * @param $filename
	 * @return bool|resource
	 */
	protected static function createImage($type, $src, $filename)
	{
//		echo $type;exit;
		$image = false;

		if (strpos($type, 'webp') !== false)
		{
			if ($fp = fopen($filename.'.webp', "wb+"))
			{
				fwrite($fp, $src);
				fclose($fp);

				exec('dwebp '.$filename.'.webp -o '.$filename);
//				if (IS_LOCAL)
//				{
//					exec('dwebp '.$filename.'.webp -o '.$filename);
//				}
//				else
//				{
//					try
//					{
//						$image = \imagecreatefromwebp($filename.'.webp');
//					}
//					catch (\Exception $e)
//					{
//						exec('dwebp '.$filename.'.webp -o '.$filename);
//					}
//				}
				unlink($filename.'.webp');

				if (!isset($image))
				{
					return true;
				}
			}
		}
		else
		{
			$image = imagecreatefromstring($src);
		}
		if (!$image)
		{
			return false;
		}

		imagepng($image, $filename);
		imagedestroy($image);

		return $image;
	}

	/**
	 * @param ImageInterface $image
	 * @return ImageInterface
	 */
	protected static function prepareImage(ImageInterface $image)
	{
		return $image;
	}

	/**
	 * @param $source
	 * @param $sexCode
	 * @return array|Item[]|\yii\db\ActiveRecord[]
	 */
	public static function loadItems($source, $sexCode)
	{
		return Item::find()
			->where(['sex' => $sexCode, 'source' => $source])
			->all();
	}

	/**
	 * @param Item $item
	 * @return bool
	 */
	public function includeAllParamsToItem(Item $item)
	{
		$params = $this->getAllParams();
		if (!empty($params['PARAMS']))
		{
			if ($item->isNew && !empty($params['PARAMS']['brand']))
			{
				$brand = static::saveBrand($params['PARAMS']['brand']);
				$item->title = str_replace($item->brand, $brand->name, $item->title);
				$item->brand = $brand->name;
				$item->brand_id = $brand->id;
				$item->save();

				static::includeBrandToItem($item, $brand);
			}
		}

		if (!empty($params['PRICE']) && $params['PRICE'] instanceof Price)
		{
			$r = $this->includePriceToItem($item);
			if ($r->isSuccess())
			{
				if (isset(\Yii::$app->params['downloadImages']) && \Yii::$app->params['downloadImages'] === true)
				{
					$this->includeImagesToPrice($params['PRICE']);
				}
			}
		}

		return true;
	}

	/**
	 * @param string $value
	 * @return Brand
	 */
	protected static function saveBrand($value)
	{
		$brand = Brand::getByName($value);
		if (!$brand)
		{
			$brandParent = null;
			static $brands = [];
			if (empty($brands))
			{
				$brands = Brand::find()->where(['is not', 'parent', null])->orderBy(['parent' => SORT_ASC])->all();
			}

			$valueLower = mb_strtolower($value);
			foreach ($brands as $brandItem)
			{
//				$brandWithoutBy = null;
//				if (mb_strpos(mb_strtolower($valueLower), ' by ') !== false)
//				{
//					$brandWithoutBy = str_replace(' by ', ' x ', mb_strtolower($valueLower));
//				}

				if (mb_strpos($valueLower, mb_strtolower($brandItem->name)) !== false)
				{
					$brandParent = $brandItem;
					break;
				}
				elseif (mb_strpos($valueLower, mb_strtolower($brandItem->name)) !== false)
				{
					$brandParent = $brandItem;
					break;
				}
			}

			$brand = new Brand();
			$brand->name = $value;
			$brand->code = BaseEntity::makeCode($value);
			if ($brandParent)
			{
				$brand->parent = $brandParent->id;
			}
			$brand->save();
		}

		return $brand;
	}

	/**
	 * @param Item $item
	 * @param Brand $brand
	 * @return bool
	 */
	public static function includeBrandToItem(Item $item, Brand $brand)
	{
		$brandItem = BrandItem::findOne(['item_id' => $item->id, 'brand_id' => $brand->id]);
		if (!$brandItem)
		{
			$brandItem = new BrandItem();
			$brandItem->setAttributes([
											 'item_id' => $item->id,
											 'brand_id' => $brand->id,
										 ]);
			$brandItem->save();
		}

		return true;
	}

	/**
	 * @param Item $item
	 * @return Result
	 */
	public function includePriceToItem(Item $item)
	{
		$result = new Result();
		/** @var Price $price */
		$price = $this->getItemPrice();

		if ($price->item_id == 0)
		{
			$price->item_id = $item->id;
		}

		if (!empty($price->image_url) && empty($price->image))
		{
			if (isset(\Yii::$app->params['downloadImages']) && \Yii::$app->params['downloadImages'] === true)
			{
				try
				{
					$imageFile = $this->downloadImage($item);
					if (!empty($imageFile))
					{
						$price->image = $imageFile;
					}
					else
					{
						$price->active = 0;
					}
				}
				catch (\Exception $e)
				{
					$result->addError('image '.$price->image_url.' not downloaded exception ['.$e->getMessage().']');
					static::log('image '.$price->image_url.' not downloaded exception ['.$e->getMessage().']');
					return $result;
				}

			}
		}

		if ($price->save())
		{
			if ($price->isNew)
			{
				$seasonCodes = Item::getSeasonCodes();
				foreach ($seasonCodes as $seasonCode)
				{
					Rating::create($price->id, -1, $seasonCode);
				}
			}
		}

		return $result;
	}

	/**
	 * @param Price $price
	 * @return Result
	 */
	public function includeImagesToPrice(Price $price)
	{
		$result = new Result();
		/** @var Price $price */
		$images = $this->getPriceImages();

		if (empty($images))
		{
			return $result;
		}

		if (!$price->isNew || $price->id <= 0)
		{
			return $result;
		}

		foreach ($images as $imageUrl)
		{
			$priceImage = new ItemPriceImage();
			$priceImage->setAttributes([
									  'price_id' => $price->id,
									  'image_url' => $imageUrl,
									  'status' => ItemPriceImage::STATUS_HIDDEN,
								  ]);

			$code = BaseEntity::makeCode($price->item->title."_".$price->article."_".date('Ymd'));

			$imageFilename = static::downloadImageByUrl($imageUrl, $code);
			if (!empty($imageFilename))
			{
				$priceImage->image = $imageFilename;
			}

			$priceImage->save();
		}

		return $result;
	}

	/**
	 * @return array
	 */
	protected static function getSexList()
	{
		return [
			static::SEX_FEMALE => [
				'женск', 'женщ', 'women', 'woman'
			],
			static::SEX_MALE => [
				'мужск', 'мужч', 'men', 'унисекс', 'unisex'
			],
//			static::SEX_UNISEX => ['унисекс'],
			static::SEX_KIDS => ['детск', 'дети'],
			static::SEX_KID_MALE => ['мальч'],
			static::SEX_KID_FEMALE => ['девоч'],
		];
	}

	/**
	 * @return array
	 */
	protected static function getSexNames()
	{
		return [
			static::SEX_FEMALE => 'женские',
			static::SEX_MALE => 'мужские',
//			static::SEX_UNISEX => 'унисекс',
			static::SEX_KIDS => 'детские',
			static::SEX_KID_MALE => 'для мальчика',
			static::SEX_KID_FEMALE => 'для девочки',
		];
	}

	/**
	 * @return array
	 */
	protected static function getSeasonList()
	{
		$seasonNamesAndRulesList = Item::getSeasonCodesAndRulesList();
	}

	/**
	 * @param $code
	 * @return bool|mixed
	 */
	public static function getSexNameByCode($code)
	{
		$sexNames = static::getSexNames();
		if (isset($sexNames[$code]))
		{
			return $sexNames[$code];
		}

		return false;
	}

	/**
	 * @return string
	 */
	protected static function getUnisexCode()
	{
		return static::SEX_UNISEX;
	}

	/**
	 * @param $type
	 * @return mixed
	 */
	private static function getInstance($type)
	{
		if (!isset(static::$instances[$type]))
		{
			static::$instances[$type] = new $type();
		}
		return static::$instances[$type];
	}

	/**
	 * @return Curl
	 */
	public static function getCurl()
	{
		$curl = static::getInstance(static::CURL_INSTANCE);
		static::setDefaultOptions($curl);

		return $curl;
	}

	/**
	 *
	 */
	public function resetCurlOptions()
	{
		$curl = static::getCurl();

		static::setDefaultOptions($curl);

		$curl->setOption(CURLOPT_PROXY, false);
		$curl->setOption(CURLOPT_PROXYTYPE, false);
		$curl->setOption(CURLOPT_PROXYUSERPWD, false);
		$curl->setOption(CURLOPT_TIMEOUT, 30);
	}

	/**
	 * @param Curl $curl
	 */
	protected static function setDefaultOptions(Curl $curl)
	{
		$curl->setOption(CURLOPT_FOLLOWLOCATION, true);
		$curl->setOption(CURLOPT_USERAGENT, 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Ubuntu/11.10 Chromium/71.0.3578.98 Chrome/71.0.3578.98 Safari/537.36');

		$curl->setOption(CURLOPT_HTTPHEADER, [
			'cache-control: max-age=0',
			'accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8',
			'user-agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Ubuntu/11.10 Chromium/71.0.3578.98 Chrome/71.0.3578.98 Safari/537.36',
//			'accept-encoding: gzip, deflate, br',
			'HTTPS: 1',
			'dnt: 1',
			'accept-Language: en-US,en;q=0.8,en-GB;q=0.6,es;q=0.4',
		]);

	}

	/**
	 * @return HtmlParser
	 */
	protected static function getHtmlParser()
	{
		return static::getInstance(static::HTML_PARSER_INSTANCE);
	}

	/**
	 * @param $value
	 * @param int $level
	 * @return bool
	 */
	public static function log($value, $level = null)
	{
		if (empty($level))
		{
			$level = static::LOG_LEVEL_LOW;
		}
		if (\Yii::$app->params['showLog'] === true)
		{
			if (\Yii::$app->params['logLevel'] < $level)
			{
				return false;
			}
			try
			{
				echo static::getCodeName().": " . $value."\n";
			}
			catch (\Exception $e)
			{
				return false;
			}

		}
		return true;
	}

	/**
	 * @param \Exception $e
	 * @param $value
	 * @param int $level
	 * @return bool
	 * @throws \ErrorException
	 */
	public static function error(\Exception $e, $value, $level = 1)
	{
		if (\Yii::$app->params['showLog'] === true)
		{
			if (\Yii::$app->params['logLevel'] < $level)
			{
				return false;
			}
			echo "-----------\n".static::getCodeName()." error: \n".
				$e->getMessage()."\n".
				"file:" .$e->getFile()."\n".
				"line:" .$e->getLine()."\n".
				"value:" .$value."\n\n";
		}
		return true;
	}

	/**
	 * @param $value
	 * @return string
	 */
	public static function clearText($value)
	{
		$value = trim($value);
		$value = str_replace([chr(194).chr(160), "\n","\r","\t"], ' ', $value);
		$value = preg_replace('/  +/i', ' ', $value);
		return trim($value);
	}

	/**
	 * @param $value
	 * @return string
	 */
	public static function clearRussianText($value)
	{
		return trim(preg_replace('/[\x{0410}-\x{042F}]+.*[\x{0410}-\x{042F}]+/iu', '', $value));
	}

	/**
	 * @param $value
	 * @return float
	 */
	public static function getNumber($value)
	{
		$value = trim($value);
//		$value = str_replace([' ','&nsbp;', '\n', "\n", chr(194).chr(160), chr(160)], '', $value);
		$value = str_replace(',', '.', $value);
		$value = preg_replace("/[^0-9.]/", "", $value);
		return floatval($value);
	}

	/**
	 * @param $params
	 * @param $itemOriginalNameParams
	 * @param $originalCategoryName
	 * @param bool $isSexInTitle
	 * @return bool|string
	 */
	protected static function getClearTitle($params, $itemOriginalNameParams, $originalCategoryName, $isSexInTitle = false)
	{
		$clearCategoryList = static::getClearCategoryList();
		$clearTitle = false;
		$brandCountWords = 0;
		$brandWords = [];
		if (!empty($params['brand']))
		{
			$brandWords = explode(' ', mb_strtolower(trim($params['brand'])));
			$brandCountWords = count($brandWords);
		}

		$isIssetForSomething = false;
		$isIssetForSomethingIndex = false;
		foreach ($itemOriginalNameParams as $index => $nameItemValue)
		{
			if (mb_strtolower($nameItemValue) == 'для')
			{
				$isIssetForSomething = true;
				$isIssetForSomethingIndex = $index;
				break;
			}
		}

		$itemName = '';
		foreach ($itemOriginalNameParams as $index => $nameItemValue)
		{
			if (empty($nameItemValue))
			{
				continue;
			}

			if ($isSexInTitle && $index == 0)
			{
				continue;
			}

			if (!empty($params['brand']) && in_array(mb_strtolower($nameItemValue), $brandWords))
			{
				if ($brandCountWords == 1 && (($index == 0 && !$isSexInTitle ) || ($index == 1 && !$isSexInTitle ) || ($index == 2 && $isSexInTitle)))
				{
					continue;
				}
				elseif ($brandCountWords > 1
					&& (
						($index >= 0 && $index <= ($brandCountWords + 1) && !$isSexInTitle)
						|| ($index >= 1 && $index <= ($brandCountWords + 1) && !$isSexInTitle)
						|| ($index >= 2 && $index <= ($brandCountWords + 1) && $isSexInTitle)
					))
				{
					continue;
				}
			}

			if ($isIssetForSomething && ($index >= $isIssetForSomethingIndex && $index <= ($isIssetForSomethingIndex + 1)))
			{
				continue;
			}

			if (!empty($originalCategoryName) &&
				(in_array(mb_strtolower($originalCategoryName), $clearCategoryList)) &&
				($originalCategoryName == $nameItemValue || mb_strtolower($originalCategoryName) == mb_strtolower($nameItemValue)))
			{
				continue;
			}

			if (!empty($params['source_id']) && strpos(mb_strtolower($nameItemValue), mb_strtolower($params['source_id'])) !== false)
			{
				continue;
			}

			$itemName .= (!empty($itemName) ? ' ' : '') . $nameItemValue;
		}

		if (!empty($itemName))
		{
			$clearTitle = $itemName;
		}

		return $clearTitle;
	}

	/**
	 * @return array
	 */
	protected static function getClearCategoryList()
	{
		return ['кроссовки', 'кеды'];
	}

	/**
	 * @param $string
	 * @return bool|int|mixed|string
	 */
	public function getSexFromString($string)
	{
		$return = false;
		$foundList = [];
		preg_match('/('.static::getSexPattern().')/i', mb_strtolower($string), $sexData);
		if (!empty($sexData[1]))
		{
			$sex = trim($sexData[1]);

			$sexList = static::getSexList();
			foreach ($sexList as $sexCode => $sexNameList)
			{
				foreach ($sexNameList as $sexName)
				{
					$pos = mb_strpos($sexName, mb_strtolower($sex));
					if ($pos !== false)
					{
						$foundList[$sexCode] = $sexName;
					}
				}
			}
		}

		if (count($foundList) == 1)
		{
			$foundListKeys = array_keys($foundList);
			$return = reset($foundListKeys);
		}
		elseif (count($foundList) > 1)
		{
			$words = explode(' ', $string);
			foreach ($words as $word)
			{
				foreach ($foundList as $sexCode => $foundValue)
				{
					if (mb_strpos(mb_strtolower($word), mb_strtolower($foundValue)) === 0)
					{
						$return = $sexCode;
						break 2;
					}
				}
			}
		}


		return $return;
	}

	/**
	 * @param $string
	 * @return bool
	 */
	public function getOriginalSexFromString($string)
	{
		$result = false;

		$words = explode(' ', $string);

		preg_match('/^('.static::getSexPattern().')/i', mb_strtolower($string), $sexData);
		if (!empty($sexData[1]))
		{
			$sexList = static::getSexList();

			foreach ($words as $word)
			{
				foreach ($sexList as $sexCode => $sexNameList)
				{
					foreach ($sexNameList as $sexName)
					{
						if (strpos(mb_strtolower($word), $sexName) !== false)
						{
							$result = $word;
							break 3;
						}
					}
				}

			}
		}

		return $result;
	}

	/**
	 * @return string
	 */
	public static function getSexPattern()
	{
		return 'женск|мужск|женщ|мужч|унисекс|unisex|women|woman|men|для него|для нее|дети|детск|мальч|девоч';
	}

	public static function getSeasonPattern()
	{
		$seasonNamesAndRulesList = Item::getSeasonCodesAndRulesList();
		$pattern = '';
		foreach ($seasonNamesAndRulesList as $seasonCode => $seasonRules)
		{
			$pattern .= (!empty($pattern) ? '|' : '') . join('|', $seasonRules);
		}

		return $pattern;
	}

	/**
	 * @param $string
	 * @return bool|int|mixed|string
	 */
	public function getSeasonFromString($string)
	{
		$return = false;
		$pattern = static::getSeasonPattern();
		if (empty($pattern))
		{
			return $return;
		}

		preg_match('/('.static::getSeasonPattern().')/i', mb_strtolower($string), $seasonData);
		if (!empty($seasonData[1]))
		{
			$seasonRaw = trim($seasonData[1]);

			$seasonNamesAndRulesList = Item::getSeasonCodesAndRulesList();
			foreach ($seasonNamesAndRulesList as $seasonCode => $seasonRulesList)
			{
				foreach ($seasonRulesList as $seasonRule)
				{
					$pos = mb_strpos($seasonRule, mb_strtolower($seasonRaw));
					if ($pos !== false)
					{
						$return = $seasonCode;
						break 2;
					}
				}
			}
		}

		return $return;
	}

	/**
	 * @param string $text
	 * @return bool
	 */
	public function isAllowItem($text)
	{
		$disallowWords = $this->getDisallowWords();

		if (empty($disallowWords))
		{
			return true;
		}

		foreach ($disallowWords as $disallowWord)
		{
			if (mb_strpos(mb_strtolower($text), mb_strtolower($disallowWord)) !== false)
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * @return bool|array
	 */
	public function getDisallowWords()
	{
		return false;
	}

	/**
	 * @param $string
	 * @param null $sex
	 * @return bool|mixed
	 */
	public function getCategoryFromString($string, $sex = null)
	{
		$result = false;

		$itemTypes = static::getAllowCategoryTypes($sex);

		foreach ($itemTypes as $itemTypeValue)
		{
			if (mb_strpos(mb_strtolower($string), $itemTypeValue) !== false)
			{
				$result = $itemTypeValue;
				break;
			}
		}

		return $result;
	}

	/**
	 * @param $string
	 * @return bool
	 */
	public function getOriginalCategoryFromString($string)
	{
		$result = false;

		$itemTypes = static::getAllowCategoryTypes();

		$words = explode(' ', $string);

		foreach ($words as $word)
		{
			foreach ($itemTypes as $itemTypeValue)
			{
				if (mb_strpos(mb_strtolower($word), $itemTypeValue) !== false)
				{
					$result = $word;
					break;
				}
			}
		}

		return $result;
	}

	/**
	 * @param null $sexCode
	 * @return array
	 */
	protected static function getAllowCategoryTypes($sexCode = null)
	{
		$list = [
			'кроссовки',
			'кеды',
//			'слипоны',

			'ботинки',
			'полуботинки',

			'туфли',
			'лодочки',

//			'мокасины',
			'топсайдеры',

			'сапоги',
//			'полусапоги',

//			'сандали',
//			'сабо',

			'лоферы',
			'батильоны',
			'ботильоны',
//			'балетки',
//			'босоножки',
			'эспадрильи',

//			'угги',
//			'валенки',
//			'луноходы',

			'ботфорты',
		];

		$moreCategoriesList = [];

		if (!empty($sexCode))
		{
			$moreCategoriesListRaw = static::getMoreCategoriesBySex();
			if (!empty($moreCategoriesListRaw[$sexCode]))
			{
				$moreCategoriesList = $moreCategoriesListRaw[$sexCode];
			}

			$excludeList = static::getExcludeCategoryBySex($sexCode);
		}

		$result = [];
		$resultRaw = array_merge($list, $moreCategoriesList);
		if (isset($excludeList) && !empty($excludeList))
		{
			foreach ($resultRaw as $category)
			{
				if (!in_array($category, $excludeList))
				{
					$result[] = $category;
				}
			}
		}
		else
		{
			$result = $resultRaw;
		}

		return $result;
	}

	/**
	 * @return array
	 */
	protected static function getMoreCategoriesBySex()
	{
		return [
			static::SEX_KIDS => ['обувь'],
			static::SEX_KID_MALE => ['обувь'],
			static::SEX_KID_FEMALE => ['обувь'],
		];
	}

	/**
	 * @param array $itemParams
	 * @return Item|null
	 */
	protected function searchItem(array $itemParams)
	{
		return Item::getByModel($itemParams['model'], $itemParams['brand']);
	}

	/**
	 * @return array
	 */
	protected static function getExcludeCategoryList()
	{
		return [
			static::SEX_MALE => [
				'сандали'
			],
		];
	}

	/**
	 * @param $sexCode
	 * @return bool|mixed
	 */
	public static function getExcludeCategoryBySex($sexCode)
	{
		$excludeList = static::getExcludeCategoryList();

		if (!empty($excludeList[$sexCode]))
		{
			return $excludeList[$sexCode];
		}

		return false;
	}

	public static function getPriceByParams($itemId, array $params)
	{
		return Price::getByArticle($itemId, $params['article'], $params['source']);
	}

	public static function getGrabProviderList()
	{
		return [
			Nike::class,
			NewBalance::class,

			BasketShop::class,
			SlamDunk::class,
			SuperStep::class,
			SportPoint::class,
			SneakerHead::class,
			Puma::class,
			FarFetch::class,
			DCShoes::class,

			Lamoda::class,

			Adidas::class,
			Reebok::class,
		];
	}

	/**
	 * @param Price $price
	 * @return Result|\console\models\Result|void
	 * @throws \ErrorException
	 */
	public function getPriceItemPageValue(Price $price)
	{

		$result = new Result();
		$url = $price->url;
		try
		{
			/** @var Result $r */
			return $this->getItemValues($url);
		}
		catch (\Exception $e)
		{
			Error::create($url, $e->getMessage(), $e->getCode());
			static::error($e, $url, 2);

			$result->addError($e->getMessage(), $e->getCode());
		}

		return $result;
	}

	/**
	 * @param Result $result
	 * @param Price $price
	 * @return bool
	 */
	public function applyPageResultToPriceItem(Result $result, Price $price)
	{
		if (!$result->isSuccess())
		{
			$price->active = 0;
		}

		$resultData = $result->getData();
		$isPriceChanged = false;

		if (!empty($resultData) && is_array($resultData))
		{

			$itemPostParameterValues = [];
			$percent = null;

			if (empty($resultData['discount_price']) || empty($resultData['price']))
			{
				$price->active = 0;
			}
			else
			{
				if (!empty($resultData['discount_price'])  && $price->discount_price != $resultData['discount_price'])
				{
					$price->discount_price = $resultData['discount_price'];
					$itemPostParameterValues['discount_price'] = $resultData['discount_price'];

					$isPriceChanged = true;
				}

				if (!empty($resultData['price']) && $price->price != $resultData['price'])
				{
					$price->price = $resultData['price'];
//					$itemPostParameterValues['price'] = $resultData['price'];
					$isPriceChanged = true;
				}

				if ($isPriceChanged)
				{
					$percent = static::getPercent($price->price, $price->discount_price);
					if ($percent != $price->percent)
					{
						$price->percent = $resultData['percent'];
					}
				}
			}

			if (!empty($resultData['sizes']) && !is_array($price->sizes))
			{

				$priceSizes = unserialize($price->sizes);

				foreach ($priceSizes as $priceSize)
				{
					if (!in_array($priceSize, $resultData['sizes']))
					{
						if (count($resultData['sizes']) == 0)
						{
							$price->active = 0;
						}
						else
						{
							$price->sizes = serialize($resultData['sizes']);
						}

						break;
					}
				}

				$minCountSizes = static::getMinCountSizes();
				if (!empty($minCountSizes))
				{
					if (count($priceSizes) <= $minCountSizes)
					{
						$price->active = 0;
					}
				}
			}

			if ($isPriceChanged)
			{
				$minDiscountPercent = static::getMinDiscountPercent($price->sex);
				if (!empty($minDiscountPercent) && !empty($percent))
				{
					if ($percent < $minDiscountPercent)
					{
						static::log($price->item->title. ": ". static::ERROR_LOW_DISCOUNT . " [".$percent."]", 4);
						Error::create($price->url, static::ERROR_LOW_DISCOUNT . " [".$percent."]", 'ERROR_LOW_DISCOUNT');
						$price->active = 0;
					}
				}
			}
		}

		if ($price->validate() && count($price->getDirtyAttributes()) > 0)
		{
			$price->save();
		}

		return ($price->active == 1);
	}

}