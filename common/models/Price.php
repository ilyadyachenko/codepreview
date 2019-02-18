<?php

namespace common\models;


use yii\behaviors\TimestampBehavior;

/**
 * Class Price
 *
 * @property int $id
 * @property int $item_id
 * @property string $article
 * @property double $price
 * @property double $discount_price
 * @property int $percent
 * @property string $size_type
 * @property string $sizes
 * @property string $sex
 * @property string $color
 * @property string $color_title
 * @property string $material
 * @property string $material_title
 * @property int $marketplace_id
 * @property string $source
 * @property string $url
 * @property string $source_url
 * @property string $image_url
 * @property string $image
 * @property int $session_id
 * @property string $created_at
 * @property string $updated_at
 * @property int $active
 * @property Item $item
 * @property ItemPosts[] $itemPost
 * @property ItemPriceImage[] $itemPriceImage
 * @property ItemPriceImage[] $availableItemPriceImage
 * @property ItemPriceImage[] $notAvailableItemPriceImage
 * @property Rating[] $rating
 * @package common\models
 */
class Price extends BaseEntity
{
	public $isNew = false;
	public $internalIndex = null;

	const SEX_MALE = 'M';
	const SEX_FEMALE = 'F';
	const SEX_KIDS = 'K';
	const PRICE_ERROR_NOT_ACTIVE = 'price not active';

	/**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'prices';
    }

	/**
	 * {@inheritdoc}
	 */
	public function behaviors()
	{
		return [
			TimestampBehavior::className(),
		];
	}

    public function getInternalIndex()
	{
		if ($this->internalIndex === null)
		{
			if ($this->id > 0)
			{
				$this->internalIndex = $this->id;
			}
			else
			{
				$this->internalIndex = md5(microtime());
			}
		}

		return $this->internalIndex;
	}

	/**
	 * {@inheritdoc}
	 */
	public function rules()
	{
		return [
			[
				[
					'item_id',
					'article',
					'discount_price',
					'price',
					'percent',
					'size_type',
					'sizes',
					'sex',
					'color',
					'color_title',
					'material',
					'material_title',
					'marketplace_id',
					'url',
					'image_url',
					'image',
					'session_id',
					'active',
				],
				'default',
				'except' => ['price-update'],
			],
			[
				[
					'discount_price',
					'price',
					'percent',
					'sizes',
					'active',
				],
				'default',
				'on' => 'price-update'
			],
			[
				[
					'discount_price',
					'price',
					'marketplace_id',
				],
				'required'
			],
			[
				[
					'created_at',
					'updated_at'
				],
				'safe'
			],
			[['active'], 'default', 'value' => 1],

			[
				[
					'color_title',
					'material_title',
				],
				'default',
				'on' => 'admin-fields-update'
			],

			[['code'], 'default', 'value' => '']
		];
	}

	public function beforeSave($insert)
	{
		$values = $this->getDirtyAttributes();
		if (empty($values))
		{
			return true;
		}

		return parent::beforeSave($insert);
	}

	public function afterSave($insert, $changedAttributes)
	{
		parent::afterSave($insert, $changedAttributes);

		if ($insert === true)
		{
			$seasonCodes = Item::getSeasonCodes();
			foreach ($seasonCodes as $seasonCode)
			{
				Rating::create($this->id, -1, $seasonCode);
			}
		}

		$sizes = [];
		if (!empty($this->sizes))
		{
			$sizes = unserialize($this->sizes);
		}


		$colors = [];
		if (!empty($this->color_title))
		{
			$colors = explode('/', $this->color_title);
		}

		$priceParameters = PriceParameter::findAll(['price_id' => $this->id]);
		foreach ($priceParameters as $priceParameterIndex => $priceParameter)
		{
			if (!empty($colors))
			{
				if ($priceParameter->type == PriceParameter::TYPE_COLOR)
				{
					foreach ($colors as $colorIndex => $color)
					{
						if (mb_strtolower(trim($color)) == mb_strtolower(trim($priceParameter->value)))
						{
							unset($colors[$colorIndex]);
							unset($priceParameters[$priceParameterIndex]);
							break;
						}
					}
				}
			}

			if (!empty($sizes))
			{
				if ($priceParameter->type == PriceParameter::TYPE_SIZE)
				{
					foreach ($sizes as $sizeIndex => $size)
					{
						if (trim($size) == trim($priceParameter->value))
						{
							unset($sizes[$sizeIndex]);
							unset($priceParameters[$priceParameterIndex]);
							break;
						}
					}
				}
			}
		}

		if (!empty($colors))
		{
			foreach ($colors as $color)
			{
				$priceParameter = new PriceParameter();
				$priceParameter->setAttributes([
												   'price_id' => $this->id,
												   'type' => PriceParameter::TYPE_COLOR,
												   'value' => mb_strtolower($color),
												   'active' => 1
											   ]);

				if ($priceParameter->validate())
				{
					$priceParameter->save();
				}
			}
		}

		if (!empty($sizes))
		{
			foreach ($sizes as $size)
			{
				$priceParameter = new PriceParameter();
				$priceParameter->setAttributes([
												   'price_id' => $this->id,
												   'type' => PriceParameter::TYPE_SIZE,
												   'value' => $size,
												   'active' => 1
											   ]);

				if ($priceParameter->validate())
				{
					$priceParameter->save();
				}
			}
		}

		if (!empty($priceParameters))
		{
			foreach ($priceParameters as $priceParameter)
			{
				$priceParameter->active = 0;
				if ($priceParameter->validate())
				{
					$priceParameter->save();
				}
			}
		}
	}


	/**
	 * @param string $url
	 * @return Price
	 */
	public static function getByUrl($url)
	{
		return static::find()->where(['url' => $url])->one();
	}

	/**
	 * @param int $itemId
	 * @param string $article
	 * @param null $source
	 * @return array|Price|null|\yii\db\ActiveRecord
	 */
	public static function getByArticle($itemId, $article, $source = null)
	{
		$query = static::find()->where(['item_id' => $itemId, 'article' => $article]);
		if (!empty($source))
		{
			$query->andWhere(['source' => $source]);
		}
		return $query->one();
	}

	/**
	 * @param $itemId
	 * @param $sessionId
	 * @return bool
	 */
	public static function deactivateById($itemId, $sessionId)
	{
		$items = static::find()
			->where(['item_id' => $itemId])
			->andWhere(['!=', 'session_id', $sessionId])
			->all();

		if (empty($items))
		{
			return true;
		}

		static::deactivateByList($items);

		return true;
	}

	/**
	 * @param $itemId
	 * @param $marketplaceId
	 * @return bool
	 */
	public static function deactivateByMarketplace($itemId, $marketplaceId)
	{
		$items = static::find()->where([
										   'item_id' => $itemId,
										   'marketplace_id' => $marketplaceId
									   ])->all();
		if (empty($items))
		{
			return true;
		}

		static::deactivateByList($items);

		return true;
	}

	/**
	 * @param $itemId
	 * @param $article
	 * @return bool
	 */
	public static function deactivateByArticle($itemId, $article)
	{
		$items = static::find()->where([
										   'item_id' => $itemId,
										   'article' => $article
									   ])->all();
		if (empty($items))
		{
			return true;
		}

		static::deactivateByList($items);

		return true;
	}

	/**
	 * @param null $sessionId
	 * @return bool
	 */
	public static function deactivateAllPrice($sessionId = null)
	{
		$query = static::find();

		if (!empty($sessionId))
		{
			$query->where(['!=', 'session_id', $sessionId]);
		}

		$items = $query->all();
		if (empty($items))
		{
			return true;
		}

		static::deactivateByList($items);

		return true;
	}

	/**
	 * @param $list
	 */
	protected static function deactivateByList($list)
	{
		/** @var static $item */
		foreach ($list as $item)
		{
			$item->active = 0;
			$item->save();
		}
	}

	/**
	 * @param $source
	 * @return Price[]
	 */
	public static function loadBySource($source)
	{
		return static::find()->where(['source' => $source])->all();
	}

	public function afterDelete()
	{
		parent::afterDelete();

		if (!empty($this->image))
		{
			$posExt = strrpos($this->image, '.');
			$ext = substr($this->image, $posExt + 1, strlen($this->image));
			$filename = substr($this->image, 0, $posExt);

			$uploadDir = \Yii::$app->params['upload_dir'];
			$itemsImagesDir = \Yii::$app->params['items_images'];
			$webRoot = \Yii::getAlias('@webroot');

			$imageDir = $webRoot . '/' . $uploadDir . '/' . $itemsImagesDir . '/';

			if (file_exists($imageDir . $filename . '_thumb.' . $ext))
			{
				unlink($imageDir . $filename . '_thumb.' . $ext);
			}

			if (file_exists($imageDir . $this->image))
			{
				unlink($imageDir . $this->image);
			}
		}


		$priceParameters = PriceParameter::find()->where(['price_id' => $this->id])->all();
		if (!empty($priceParameters))
		{
			/** @var PriceParameter $priceParameter */
			foreach ($priceParameters as $priceParameter)
			{
				try
				{
					$priceParameter->delete();
				}
				catch (\Throwable $throwable)
				{

				}
			}
		}

		$itemPriceImages = ItemPriceImage::find()->where(['price_id' => $this->id])->all();
		if (!empty($itemPriceImages))
		{
			/** @var ItemPriceImage $itemPriceImage */
			foreach ($itemPriceImages as $itemPriceImage)
			{
				try
				{
					$itemPriceImage->delete();
				}
				catch (\Throwable $throwable)
				{

				}
			}
		}

		$itemPosts = ItemPosts::find()->where(['price_id' => $this->id])->all();
		if (!empty($itemPosts))
		{
			/** @var ItemPosts $itemPost */
			foreach ($itemPosts as $itemPost)
			{
				try
				{
					$itemPost->delete();
				}
				catch (\Throwable $throwable)
				{

				}

			}
		}

		$ratingItems = Rating::find()->where(['price_id' => $this->id])->all();
		if (!empty($ratingItems))
		{
			/** @var Rating $ratingItem */
			foreach ($ratingItems as $ratingItem)
			{
				try
				{
					$ratingItem->delete();
				}
				catch (\Throwable $throwable)
				{

				}
			}
		}

		return true;
	}


	/**
	 * @return \yii\db\ActiveQuery
	 */
	public function getItem()
	{
		return $this->hasOne(Item::class, ['id' => 'item_id']);
	}

	/**
	 * @return \yii\db\ActiveQuery
	 */
	public function getBrandItem()
	{
		return $this->hasOne(BrandItem::class, ['item_id' => 'id'])->via('item');
	}

	/**
	 * @return \yii\db\ActiveQuery
	 */
	public function getMessage()
	{
		return $this->hasOne(Message::class, ['item_id' => 'id']);
	}

	/**
	 * @return \yii\db\ActiveQuery
	 */
	public function getItemPost()
	{
		return $this->hasMany(ItemPosts::class, ['price_id' => 'id']);
	}

	/**
	 * @return \yii\db\ActiveQuery
	 */
	public function getItemPriceImage()
	{
		return $this->hasMany(ItemPriceImage::class, ['price_id' => 'id'])->asArray();
	}

	/**
	 * @return \yii\db\ActiveQuery
	 */
	public function getAvailableItemPriceImage()
	{
		return $this->hasMany(ItemPriceImage::class, ['price_id' => 'id'])->where(['status' => ItemPriceImage::STATUS_SHOW]);
	}

	/**
	 * @return \yii\db\ActiveQuery
	 */
	public function getNotAvailableItemPriceImage()
	{
		return $this->hasMany(ItemPriceImage::class, ['price_id' => 'id'])->where(['status' => ItemPriceImage::STATUS_HIDDEN]);
	}


	/**
	 * @return \yii\db\ActiveQuery
	 */
	public function getRating()
	{
		return $this->hasMany(Rating::class, ['price_id' => 'id']);
	}

	/**
	 * @return \yii\db\ActiveQuery
	 */
	public function getPriceParameter()
	{
		return $this->hasMany(PriceParameter::class, ['price_id' => 'id']);
	}
	/**
	 * @return array
	 */
	public function getAvailableSizes()
	{
		$result = [];

		$priceSizes = $this->sizes;
		if (!is_array($this->sizes))
		{
			$priceSizes = unserialize($this->sizes);
		}

		$convertedSizes = $priceSizes;
		if ($this->size_type != Size::SIZE_RU)
		{
			$convertedSizes = Size::convertToRussian($this->sex, $this->size_type, $priceSizes);
		}

		if (isset(\Yii::$app->params['min_'.$this->sex.'_size']))
		{
			$minSexSize = \Yii::$app->params['min_'.$this->sex.'_size'];

			foreach ($priceSizes as $sizeIndex => $priceSize)
			{
				if ($convertedSizes[$sizeIndex] < $minSexSize)
				{
					continue;
				}
				$result[] = $priceSize;
			}
		}
		else
		{
			$result = $priceSizes;
		}

		return $result;
	}

	public function getNativeColorName()
	{
		$color = mb_strtolower($this->color);
		$colorList = static::getColorList();
		foreach ($colorList as $colorRussian => $colorEnglish)
		{
			$color = str_replace('ё', 'е', $color);
			$color = str_replace($colorRussian, $colorEnglish, $color);
		}

		return $color;
	}

	public static function convertColorNameEngToRus($colorName)
	{
		$colorExistsList = [];
		$output = '';
		$separator = '/';
		if (strpos($colorName, ',') !== false)
		{
			$separator = ',';
		}

		$colorNames = explode($separator, $colorName);
		foreach ($colorNames as $color)
		{
			$color = mb_strtolower($color);
			$color = str_replace([',', '.'], '', $color);

			$color = trim($color);

			$colorList = static::getColorList();
			foreach ($colorList as $colorRussian => $colorEnglish)
			{
				$color = str_replace('ё', 'е', $color);
				$color = str_replace($colorEnglish, $colorRussian, $color);
			}

			$color = mb_convert_case($color, MB_CASE_TITLE, "UTF-8");
			if (isset($colorExistsList[$color]))
			{
				continue;
			}
			$output .= (!empty($output) ? "/" : "") . trim($color);
			$colorExistsList[$color] = true;

		}
		return $output;
	}

	public static function getColorList()
	{
		return [
			'черный' => 'black',
			'серый' => 'grey',
			'красный' => 'red',
			'синий' => 'navy',
			'голубой' => 'blue',
			'зеленый' => 'green',
			'желтый' => 'yellow',
			'бежевый' => 'beige',
			'белый' => 'white',
			'бордовый' => 'burgundy',
			'коричневый' => 'brown',
			'оранжевый' => 'orange',
			'сиреневый' => 'lilac',
			'бирюзовый' => 'turquoise',
			'темно' => 'dark',
			'серебристый' => 'silver',
			'оливковый' => 'olive',
			'лиловый' => 'lilac',
			'розовый' => 'pink',
			'фиолетовый' => 'purple',
			'светлый' => 'light',
			'холодный' => 'cold',
			'персик' => 'peach',
			'лайм' => 'lime',
			'золотой' => 'gold',
			'парус' => 'sail',
			'чистый' => 'pure',
			'платина' => 'platinum',
		];
	}

	public function getDuplicateArticle()
	{
//		$article = preg_replace('/[^a-zA-Z0-9]/', '', $this->article);
		//$article = str_replace()
		return static::find()->where(['like', 'article', $this->article])
			->andWhere(['!=', 'id', $this->id])
			->andWhere(['item_id' => $this->item_id])
			->all();
	}
}
