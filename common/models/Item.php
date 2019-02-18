<?php

namespace common\models;

use yii\behaviors\TimestampBehavior;

/**
 * This is the model class for table "items".
 *
 * @property int $id
 * @property int $category_id
 * @property string $sex
 * @property string $title
 * @property Price[] $price
 * @property string $model
 * @property string $brand
 * @property string $brand_id
 * @property string $description
 * @property string $source_id
 * @property string $source_url
 * @property string $source
 * @property string $season
 * @property string $created_at
 * @property string $updated_at
 * @property int $active
 * @property Category $category
 * @property Price[] $activePrice
 * @property Price[] $availablePrice
 * @property ModelAlias[] $modelAlias
 */
class Item extends BaseEntity
{
	const ITEM_SEASON_WINTER = 'WT';
	const ITEM_SEASON_SPRING = 'SP';
	const ITEM_SEASON_SUMMER = 'SM';
	const ITEM_SEASON_FALL = 'FL';
	const ITEM_SEASON_ALL = 'AL';

	public $isNew = false;
    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'items';
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

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
		return [
			[['category_id', 'sex', 'title',
				'description', 'parameters',
				'source_id', 'source_url',
				'model', 'brand', 'brand_id',
				'active', 'season'
			], 'default', 'on' => 'add-item'],
//
			[['active', 'sex', 'title', 'model', 'brand'], 'default', 'on' => 'update-item'],
			[['code'], 'default', 'value' => ''],
		];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
		return [
			'id' => 'ID',
			'category_id' => 'Category ID',
			'sex' => 'Sex',
			'title' => 'Title',
			'price' => 'Price',
			'discount_price' => 'Discount Price',
			'is_sale' => 'Is Sale',
			'image' => 'Image',
			'image_url' => 'Image Url',
			'description' => 'Description',
			'parameters' => 'Parameters',
			'source_id' => 'Source ID',
			'source_url' => 'Source Url',
			'source' => 'Source',
			'short_url' => 'Short Url',
			'created_at' => 'Created At',
			'updated_at' => 'Updated At',
			'active' => 'Active',
		];
    }

    public static function getBySource($source, $sourceId)
	{
		return static::find()->where(['source_id' => $sourceId, 'source' => $source])->one();
	}

	/**
	 * @param $model
	 * @param null $brand
	 * @return Item|null
	 */
    public static function getByModel($model, $brand = null)
	{
		$query = static::getItemsQueryByModel($model, $brand);
		return $query->one();
	}

	/**
	 * @param $model
	 * @param null $brand
	 * @return Item[]|null
	 */
    public static function getAllByModel($model, $brand = null)
	{
		$query = static::getItemsQueryByModel($model, $brand);
		return $query->all();
	}

	/**
	 * @param string $model
	 * @param null|string $brandName
	 * @return \yii\db\ActiveQuery
	 */
	public static function getItemsQueryByModel($model, $brandName = null)
	{
		$query = static::find()
			->joinWith(['modelAlias'])
			->where(['LOWER(model)' => trim(mb_strtolower($model))])
			->orWhere(['LOWER({{'.ModelAlias::tableName().'}}.alias)' => trim(mb_strtolower($model))]);
		if (!empty($brandName))
		{
			$brand = Brand::getByName($brandName);

			$brandNamesFilter = [
				'or',
				['LOWER(brand)' => trim(mb_strtolower($brandName))]
			];

			if ($brand && ($brand->isChild() || $brand->isParent()))
			{
				$brandChild = $brand->getAllChild();
				if (!empty($brandChild))
				{
					foreach ($brandChild as $subBrand)
					{
						$brandNamesFilter[] = ['LOWER(brand)' => trim(mb_strtolower($subBrand->name))];
					}
				}
			}

			$query->andWhere($brandNamesFilter);
		}



		return $query;
	}

	/**
	 * @param $article
	 * @param null $brand
	 * @return Item|null
	 */
    public static function getByArticle($article, $brand = null)
	{
		$query = static::find()->where(['LOWER(article)' => trim(mb_strtolower($article))]);
		if (!empty($brand))
		{
			$query->andWhere(['LOWER(brand)' => trim(mb_strtolower($brand))]);
		}

		return $query->one();
	}

	/**
	 * @param $url
	 * @return Item|null
	 */
    public static function getByUrl($url)
	{
		return static::find()->where(['source_url' => $url])->one();
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

		$prices = Price::find()->where(['item_id' => $this->id])->all();
		if (!empty($prices))
		{
			/** @var Price $price */
			foreach ($prices as $price)
			{
				try
				{
					$price->delete();
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
	public function getCategory()
	{
		return $this->hasOne(Category::class, ['id' => 'category_id']);
	}


	/**
	 * @return \yii\db\ActiveQuery
	 */
	public function getPrice()
	{
		return $this->hasMany(Price::class, ['item_id' => 'id']);
	}

	/**
	 * @return \yii\db\ActiveQuery
	 */
	public function getActivePrice()
	{
		return $this->hasMany(Price::class, ['item_id' => 'id'])->where(['active' => 1]);
	}

	/**
	 * @return \yii\db\ActiveQuery
	 */
	public function getAvailablePrice()
	{
		return $this->hasMany(Price::class, ['item_id' => 'id'])
			->where(['active' => 1])
			->andWhere(['not exists', ItemPosts::find()
				   ->select('price_id')
				   ->where('price_id={{'.Price::tableName().'}}.id')]
		);
	}

	/**
	 * @return \yii\db\ActiveQuery
	 */
	public function getBrandItem()
	{
		return $this->hasOne(BrandItem::class, ['item_id' => 'id']);
	}

	/**
	 * @return \yii\db\ActiveQuery
	 */
	public function getItemPost()
	{
		return $this->hasMany(ItemPosts::class, ['price_id' => 'id'])->via('price');
	}

	/**
	 * @return \yii\db\ActiveQuery
	 */
	public function getModelAlias()
	{
		return $this->hasMany(ModelAlias::class, ['item_id' => 'id']);
	}

	/**
	 * @param $value
	 * @return bool|string
	 */
	public static function getSeasonCode($value)
	{
		$list = static::getSeasonCodesAndNamesList();

		foreach ($list as $code => $nameRaw)
		{
			if ($value == $code)
			{
				return $code;
			}

			if (!is_array($nameRaw))
			{
				$nameRaw = [$nameRaw];
			}

			foreach ($nameRaw as $name)
			{
				if (mb_strpos(mb_strtolower($value), $name) !== false)
				{
					return $code;
				}
			}
		}

		return false;
	}

	/**
	 * @return array
	 */
	public static function getSeasonCodesAndNamesList()
	{
		return [
			static::ITEM_SEASON_ALL => ['всесезон', 'демисезон', 'мульти'],
			static::ITEM_SEASON_WINTER => 'зима',
			static::ITEM_SEASON_SPRING => 'весна',
			static::ITEM_SEASON_SUMMER => 'лето',
			static::ITEM_SEASON_FALL => 'осень',
		];
	}

	/**
	 * @return array
	 */
	public static function getSeasonCodesAndRulesList()
	{
		return [
			static::ITEM_SEASON_ALL => ['всесезон', 'демисезон', 'мульти'],
			static::ITEM_SEASON_WINTER => ['зима', 'зимн', 'холод'],
			static::ITEM_SEASON_SPRING => ['весна', 'весенн'],
			static::ITEM_SEASON_SUMMER => ['лето', 'летн'],
			static::ITEM_SEASON_FALL => ['осень', 'осенн'],
		];
	}

	/**
	 * @return array
	 */
	public static function getSeasons()
	{
		return [
			static::ITEM_SEASON_WINTER => [11, 12, 1],
			static::ITEM_SEASON_SPRING => [2, 3, 4],
			static::ITEM_SEASON_SUMMER => [5, 6, 7, 8],
			static::ITEM_SEASON_FALL => [8, 9, 10],
		];
	}

	/**
	 * @return array
	 */
	public static function getSeasonCodes()
	{
		$seasons = static::getSeasons();
		return array_keys($seasons);
	}
}
