<?php
use yii\helpers\Html;
use yii\helpers\ArrayHelper;
use yii\helpers\Url;
use kartik\grid\GridView;
use yii\widgets\Pjax;
use yii\data\ActiveDataProvider;


/* @var $this yii\web\View */
/* @var $model app\models\Inventory */

$this->title = $model->name;
$this->params['breadcrumbs'][] = ['label' => Yii::t('app', 'Inventories'), 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="inventory-view">
    <br>
    <div class="box box-info">
        <?php 
            $dataProvider =  new ActiveDataProvider([
                'query' => \app\models\Stock::find(),
                // 'sort'=> ['defaultOrder' => ['date'=>SORT_DESC, 'account_id'=>SORT_ASC, 'timestamp'=>SORT_ASC]],

            ]);
            $dataProvider->query->where(['inventory_id'=>$model->id])->all();

           $gridColumns  = 
            [   
                [ 
                    'class'=>'kartik\grid\ExpandRowColumn',
                    'width'=>'50px',
                    'value'=>function ($model, $key, $index, $column) {
                        return GridView::ROW_COLLAPSED;
                    },
                    'detail'=>function ($model, $key, $index, $column) {
                        return Yii::$app->controller->renderPartial('_stocking', ['model'=>$model]);
                    },
                    // 'group'=>false, 
                    // 'subGroupOf'=>7,
                    'headerOptions'=>['class'=>'kartik-sheet-style'],
                    'expandOneOnly'=>true
                ],
                [
                    'attribute'=>'product_name',
                    'header'=> Yii::t('inventory', 'Item'),
                    'width'=>'25%',
                    'headerOptions'=>['class'=>'kartik-sheet-style'],
                    'hAlign'=>'center',
                    'vAlign'=>'center',
                ],
                [
                    'class'=>'kartik\grid\DataColumn',
                    'header'=> Yii::t('inventory', 'Sold'),
                    'headerOptions'=>['class'=>'kartik-sheet-style'],
                    'hAlign'=>'center',
                    'vAlign'=>'center',
                    'width'=>'8%',
                    'format' => 'raw',
                    'value' =>function ($model, $key, $index, $widget) { 
                        return "<i class='fa fa-caret-up'></i>  ".$model->out($model); 
                                          
                    },
                    'contentOptions' => function ($model, $key, $index, $column) {
                        return ['style' => 'color:green; font-weight: bold;' ];
                        
                    },
                ],
                [
                    'class'=>'kartik\grid\DataColumn',
                    'header'=> Yii::t('inventory', 'Transfered'),
                    'headerOptions'=>['class'=>'kartik-sheet-style'],
                    'hAlign'=>'center',
                    'vAlign'=>'center',
                    'width'=>'8%',
                    'format' => 'raw',
                    'value' =>function ($model, $key, $index, $widget) { 
                        return "<i class='fa fa-caret-left'></i>  ".$model->trans($model);                    
                    },
                    'contentOptions' => function ($model, $key, $index, $column) {
                        return ['style' => 'color:orange; font-weight: bold;' ];
                        
                    },
                ],
                [
                    'class'=>'kartik\grid\DataColumn',
                    'header'=> Yii::t('inventory', 'Returned'),
                    'headerOptions'=>['class'=>'kartik-sheet-style'],
                    'hAlign'=>'center',
                    'vAlign'=>'center',
                    'width'=>'8%',
                    'format' => 'raw',
                    'value' =>function ($model, $key, $index, $widget) { 
                        return "<i class='fa fa-caret-down'></i>  ".$model->returned($model);                    
                    },
                    'contentOptions' => function ($model, $key, $index, $column) {
                        return ['style' => 'color:red; font-weight: bold;' ];
                    },
                ],
                [
                    'class'=>'kartik\grid\DataColumn',
                    'header'=> Yii::t('inventory', 'Available'),
                    'headerOptions'=>['class'=>'kartik-sheet-style'],
                    'hAlign'=>'center',
                    'vAlign'=>'center',
                    'width'=>'8%',
                    'format' => 'raw',
                    'value' =>function ($model, $key, $index, $widget) { 
                        return $model->returned($model)+$model->in($model);                    
                    },
                    // 'contentOptions' => function ($model, $key, $index, $column) {
                    //     return ['style' => 'color:red; font-weight: bold;' ];
                    // },
                ],
                
                [
                    'class'=>'kartik\grid\DataColumn',
                    'header'=> Yii::t('inventory', 'AVG Cost'),
                    'headerOptions'=>['class'=>'kartik-sheet-style'],
                    'hAlign'=>'center',
                    'vAlign'=>'center',   
                    'width'=>'8%',
                    'format' => 'raw',
                    'value' =>function ($model, $key, $index, $widget) { 
                        $current_rate = Yii::$app->mycomponent->rate();
                        return round($model->avg_cost*$current_rate, 3);                    
                    },
                ],
                [
                    'header'=> Yii::t('inventory', 'Highest Rate'),
                    'format' => 'raw',
                    'width'=>'8%',
                    'value' =>function ($model, $key, $index, $widget) { 
                        $current_rate = Yii::$app->mycomponent->rate();
                        if ($current_rate > $model->highest_rate) {
                            $rate = $current_rate;
                        }else{
                           $rate = $model->highest_rate; 
                        }
                        return $rate;                    
                    },
                    'headerOptions'=>['class'=>'kartik-sheet-style'],
                    'hAlign'=>'center',
                    'vAlign'=>'center',
                ],
                [  
                    'class'=>'kartik\grid\FormulaColumn',
                    'header'=> Yii::t('inventory', 'Stock Value'),
                    'headerOptions'=>['class'=>'kartik-sheet-style'],
                    // 'format'=>['decimal', 2],
                    'mergeHeader'=>true, 
                    'width'=>'10%',
                    'hAlign'=>'center', 
                    'vAlign'=>'center',
                    'value'=>function ($model, $key, $index, $widget) { 
                        $p = compact('model', 'key', 'index');
                        return $widget->col(5, $p) * $widget->col(6, $p) ;
                    },
                    
                    'pageSummary'=>true,
                    'footer'=>true 
                ],
                [  
                    'class'=>'kartik\grid\FormulaColumn',
                    'header'=> Yii::t('inventory', 'Gross Sale'),
                    'headerOptions'=>['class'=>'kartik-sheet-style'],
                    // 'format'=>['decimal', 2],
                    'mergeHeader'=>true, 
                    'width'=>'10%',
                    'hAlign'=>'center', 
                    'vAlign'=>'center',
                    'value'=>function ($model, $key, $index, $widget) { 
                        $p = compact('model', 'key', 'index');
                        $price = $model->product->selling_price;
                        return $widget->col(5, $p) * $widget->col(7, $p) * $price;
                    },
                    
                    'pageSummary'=>true,
                    'footer'=>true 
                ],
                [  
                    'class'=>'kartik\grid\FormulaColumn',
                    'header'=> Yii::t('inventory', 'Margin Profit'),
                    'headerOptions'=>['class'=>'kartik-sheet-style'],
                    // 'format'=>['decimal', 2],
                    'mergeHeader'=>true, 
                    'width'=>'10%',
                    'hAlign'=>'center', 
                    'vAlign'=>'center',
                    'value'=>function ($model, $key, $index, $widget) { 
                        $p = compact('model', 'key', 'index');
                        return $widget->col(9, $p)-$widget->col(8, $p);
                    },
                    
                    'pageSummary'=>true,
                    'footer'=>true 
                ],
            ]

        ?>
        <?php echo  GridView::widget([
            'dataProvider' => $dataProvider,
            // 'filterModel' => $searchModel,
            'columns' => $gridColumns,

            'rowOptions' => function ($model) {
                $min = \app\models\Minimal::find()->where(['stock_id' => $model->id])->one();
                if ($min) {
                    return ['class' => 'danger'];
                }
            },
            'pjax' => true,
            'pjaxSettings'=>[
              'neverTimeout'=>true,
                'options'=>
                  [
                    'id'=>'Stock',
                  ],
            ],
            'bordered' => true,
            'striped' => true,
            'condensed' => true,
            'responsive' => true,
            'responsiveWrap' => true,
            'hover' => true,
            'floatHeader' => true,
           // 'floatHeaderOptions' => ['scrollingTop' => $scrollingTop],
            'showPageSummary' => true,
            // 'panel' => [
            //     'type' => GridView::TYPE_INFO,
            //     'heading' => '<i class="fa  fa-hospital-o"></i><strong>       Stock</strong>',

            // ],
            
        ]); ?>

    </div>


</div>