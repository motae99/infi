<?php

use yii\helpers\Html;
use yii\helpers\Url;
use yii\widgets\ActiveForm;

/* @var $this yii\web\View */
/* @var $model app\models\Category */
/* @var $form yii\widgets\ActiveForm */
?>

<div class="category-form">

    <?php $form = ActiveForm::begin([   
            'id' => 'category-create-form',
            'options'=>['method' => 'post'],
            'action' => Url::to(['category/create']),
            
        ]); 
    ?>

    <?= $form->field($model, 'name')->textInput(['placeholder'=>Yii::t('inventory', 'Category Name'), 'maxlength' => true])->label(false) ?>

    <div class="form-group">
        <?= Html::submitButton(Yii::t('inventory', 'Add Category'), ['class' => 'btn btn-block btn-flat btn-success']) ?>
    </div>

    <?php ActiveForm::end(); ?>

</div>
