<?php

use yii\helpers\Html;
use yii\helpers\Url;
use kartik\form\ActiveForm;

/* @var $this yii\web\View */
/* @var $model app\models\Client */
/* @var $form yii\widgets\ActiveForm */
?>
<div class="client-updateform">
    <?php $form = ActiveForm::begin([   
            'id' => 'client-update-form',
            'options'=>['method' => 'post'],
            'action' => Url::to(['client/update', 'id' => $model->id]),
            
        ]); 
    ?>
  <div class="row">
    <div class="col-lg-8 eArLangCss">
    <?= $form->field($model, 'client_name' )->textInput(['placeholder' => Yii::t('client', 'Client Name'),  'maxlength' => true])->label(false); ?>
    </div>
    <div class="col-lg-4 eArLangCss">
    <?= $form->field($model, 'phone')->textInput(['placeholder' => Yii::t('client', 'Phone No') ])->label(false); ?>
    </div>
    <div class="col-lg-12 eArLangCss">
    <?= $form->field($model,  'address')->textarea(['placeholder' => Yii::t('client', 'Address'), 'rows' => 3])->label(false); ?>
    </div>
    <div class="col-lg-12 eArLangCss" id="color">
    <?= $form->field($model, 'color_class')->textInput(['placeholder' => Yii::t('client', 'Pick a Color')])->label(false); ?>
    </div>

    <div class="col-lg-6 eArLangCss">
    <ul class="fc-color-picker" id="color-chooser">
      <li><a class="text-aqua" href="#"><i class="fa fa-square"></i></a></li>
      <li><a class="text-blue" href="#"><i class="fa fa-square"></i></a></li>
      <li><a class="text-light-blue" href="#"><i class="fa fa-square"></i></a></li>
      <li><a class="text-teal" href="#"><i class="fa fa-square"></i></a></li>
      <li><a class="text-yellow" href="#"><i class="fa fa-square"></i></a></li>
      <li><a class="text-orange" href="#"><i class="fa fa-square"></i></a></li>
      <li><a class="text-green" href="#"><i class="fa fa-square"></i></a></li>
      <li><a class="text-lime" href="#"><i class="fa fa-square"></i></a></li>
      <li><a class="text-red" href="#"><i class="fa fa-square"></i></a></li>
      <li><a class="text-purple" href="#"><i class="fa fa-square"></i></a></li>
      <li><a class="text-fuchsia" href="#"><i class="fa fa-square"></i></a></li>
      <li><a class="text-muted" href="#"><i class="fa fa-square"></i></a></li>
      <li><a class="text-navy" href="#"><i class="fa fa-square"></i></a></li>
    </ul>
    </div>
    
    <div class="col-lg-6 eArLangCss">
      <div class="form-group">
        <?= Html::submitButton(Yii::t('app', 'Save'), ['class' => 'btn btn-block btn-flat btn-success']) ?>
      </div>
    </div>
  </div>
    <?php ActiveForm::end(); ?>
</div>
    <?php 
$script = <<< JS
$(document).ready(function () {
    $("#color").hide();
});

$("a").click(function() {
   var myClass = this.className;
   var length = myClass.length;
    var s = 'bg' + myClass.substr(4, length);
    $("#client-color_class").val(s);
    $('button').addClass(s);

});
JS;
$this->registerJs($script);
?> 