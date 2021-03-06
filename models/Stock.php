<?php

namespace app\models;

use Yii;

/**
 * This is the model class for table "{{%stock}}".
 *
 * @property int $id
 * @property int $inventory_id
 * @property int $product_id
 * @property int $quantity
 * @property string $created_at
 *
 * @property Inventory $inventory
 * @property Product $product
 */
class Stock extends \yii\db\ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return '{{%stock}}';
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['inventory_id', 'product_id', 'quantity'], 'required'],
            [['inventory_id', 'product_id', 'quantity'], 'integer'],
            [['created_at'], 'safe'],
            [['inventory_id'], 'exist', 'skipOnError' => true, 'targetClass' => Inventory::className(), 'targetAttribute' => ['inventory_id' => 'id']],
            [['product_id'], 'exist', 'skipOnError' => true, 'targetClass' => Product::className(), 'targetAttribute' => ['product_id' => 'id']],
        ];
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels()
    {
        return [
            'id' => Yii::t('app', 'ID'),
            'inventory_id' => Yii::t('app', 'Inventory'),
            'product_id' => Yii::t('app', 'Product ID'),
            'quantity' => Yii::t('app', 'Quantity'),
            'avg_cost' => Yii::t('app', 'average cost'),
            'highest_rate' => Yii::t('app', 'highest_rate'),
            'created_at' => Yii::t('app', 'Created At'),
        ];
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getInventory()
    {
        return $this->hasOne(Inventory::className(), ['id' => 'inventory_id']);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getProduct()
    {
        return $this->hasOne(Product::className(), ['id' => 'product_id']);
    }

    public function getCategory()
    {
        return $this->hasOne(Product::className(), ['id' => 'product_id']);
    }

    public function Out($model)
    {   
        $out = Stocking::find()->where(['product_id' => $model->product_id, 'inventory_id'=> $model->inventory_id, 'transaction'=> 'out'])->sum('quantity');
        if ($out) {
            return $out ;
        }
        return 0 ;
            

    }

    public function In($model)
    {   
        $in = Stocking::find()->where(['product_id' => $model->product_id, 'inventory_id'=> $model->inventory_id, 'transaction'=> 'in'])->sum('quantity');
        if ($in) {
            return $in ;
        }
        return 0 ;
            

    }

    public function Available($model)
    {   
        $in = Stocking::find()
                ->where(['product_id' => $model->product_id, 'inventory_id'=> $model->inventory_id, 'transaction'=> 'in'])
                ->orWhere(['transaction'=> 'returned'])
                ->sum('quantity');
        if ($in) {
            return $in ;
        }
        return 0 ;
            

    }

    public function Trans($model)
    {   
        $trans = Stocking::find()->where(['product_id' => $model->product_id, 'inventory_id'=> $model->inventory_id, 'transaction'=> 'transfered'])->sum('quantity');
        if ($trans) {
            return $trans ;
        }
        return 0 ;
            

    }

    public function Returned($model)
    {   
        $trans = Stocking::find()->where(['product_id' => $model->product_id, 'inventory_id'=> $model->inventory_id, 'transaction'=> 'returned'])->sum('quantity');
        if ($trans) {
            return $trans ;
        }
        return 0 ;
            

    }

    // public function getCategory()
    // {
    //     return $this->hasOne(Product::className(), ['id' => 'product_id']);
    // }

    // public function getCategory()
    // {
    //     return $this->hasOne(Product::className(), ['id' => 'product_id']);
    // }


    /**
     * @inheritdoc
     * @return StockQuery the active query used by this AR class.
     */
    public static function find()
    {
        return new StockQuery(get_called_class());
    }
}
