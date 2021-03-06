<?php

namespace app\controllers;

use Yii;
use app\models\Invoices;
use yii\helpers\ArrayHelper;


use app\models\InvoiceProduct;
use app\models\Product;
use app\models\ReturnedPayment;
use app\models\Stock;
use app\models\Stocking;
use app\models\Model;
use app\models\Payments;
use app\models\Minimal;
use app\models\Client;
use app\models\Outstanding;
use app\models\SystemAccount;
use app\models\Transaction;
use app\models\Entry;
use app\models\Inventory;
use app\models\InvoicesSearch;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\filters\VerbFilter;

/**
 * InvoicesController implements the CRUD actions for Invoices model.
 */
class InvoicesController extends Controller
{
    
    public function behaviors()
    {
        return [
            'verbs' => [
                'class' => VerbFilter::className(),
                'actions' => [
                    'delete' => ['POST'],
                ],
            ],
        ];
    }

    public function actionNew()
    {
        $model = new Invoices();
        $modelsItem = [new InvoiceProduct];

        if($model->load(Yii::$app->request->post()) ){
            $client_id = $_POST['Invoices']['client_id'];
            $client = Client::findOne($client_id);
            $clientRecievable = SystemAccount::find()->where(['id' => $client->account_id])->one();
            $clientPayable = SystemAccount::find()->where(['id' => $client->payable_id])->one();
            $current_rate = Yii::$app->mycomponent->rate();
            $cashAccount = SystemAccount::find()->where(['account_no' => 1100])->one();
            $salesAccount = SystemAccount::find()->where(['system_account_name' => 'sales'])->one();
            $discountAccount = SystemAccount::find()->where(['account_no' => 4200])->one();
            
            $paidNow = $model->pay;
            $expense = array();
             
            $modelsItem = Model::createMultiple(InvoiceProduct::classname());
            Model::loadMultiple($modelsItem, Yii::$app->request->post());
            
            $model->method = 'undefined';
            $model->date = date('Y-m-d');
            $model->status = 'unpaid';
            $model->cost = 0;
        
            $valid = $model->validate();
            //$valid = Model::validateMultiple($modelsItem) && $valid;

            $tAmount = 0;
            $tCost = 0;
            $tDiscount = 0;
            $tSales = 0;
                       
            if ($valid) {
                $transaction = \Yii::$app->db->beginTransaction();
                try {
                    if($flag = $model->save(false)) {                       
                       
                        $i = 0;
                        foreach ($modelsItem as $modelItem){          
                            $stock = Stock::find()
                                    ->where(['product_id' => $modelItem->product_id])
                                    ->andWhere(['inventory_id' => $modelItem->inventory_id])->one();

                            if($modelItem->quantity <= $stock->quantity && $modelItem->quantity > 0){
                                $product = Product::findOne($stock->product_id);
                                $highest_rate = $stock->highest_rate;
                                
                                $modelItem->invoice_id = $model->id;
                                $modelItem->product_id = $product->id;                                
                                if ($current_rate > $highest_rate) {
                                    $modelItem->buying_rate = $stock->avg_cost*$current_rate;
                                    $modelItem->d_rate = $current_rate;
                                }else{
                                    $modelItem->buying_rate = $stock->avg_cost*$highest_rate;
                                    $modelItem->d_rate = $highest_rate; 
                                }

                                $stockings = Stocking::find()
                                    ->where(['product_id' => $modelItem->product_id])
                                    ->andWhere(['inventory_id' => $modelItem->inventory_id])
                                    ->andWhere(['transaction' => 'in'])
                                    ->orWhere(['transaction' => 'returned'])
                                    ->orderBy(['rate' => SORT_DESC])
                                    ->all();
                                $q = $modelItem->quantity;
                                
                                foreach ($stockings as $s) {
                                    if ($s->quantity > $q) {
                                        $s->quantity -= $q;
                                        $s->save(false);
                                        break;
                                    }
                                    elseif ($s->quantity <= $q) {
                                        $q -= $s->quantity;
                                        $s->delete(false);
                                    }
                                }
                                    
                                $stocking_out = new Stocking();
                                $stocking_out->inventory_id = $modelItem->inventory_id;
                                $stocking_out->product_id = $modelItem->product_id;
                                $stocking_out->buying_price = $stock->avg_cost;
                                $stocking_out->selling_price = $modelItem->selling_rate/$modelItem->d_rate;
                                $stocking_out->quantity = $modelItem->quantity;
                                $stocking_out->rate = $modelItem->d_rate;
                                $stocking_out->transaction = "out";
                                $stocking_out->save(false);

                                $checkRate = Stocking::find()
                                    ->where(['product_id' => $modelItem->product_id])
                                    ->andWhere(['inventory_id' => $modelItem->inventory_id])
                                    ->andWhere(['transaction' => 'in'])
                                    ->orWhere(['transaction' => 'returned'])
                                    ->max('rate');

                                if ($checkRate != $stock->highest_rate) {
                                    $stock->highest_rate = $checkRate;
                                }
                                $stock->quantity -= $stocking_out->quantity;
                                $stock->save(false);


                                //// save minimum/////
                                if ($stock->quantity < $product->minimum) {
                                    $remainingQuantity = $product->minimum - $stock->quantity;
                                    $min = Minimal::find()->where(['stock_id' => $stock->id])->one();
                                    if ($min) {
                                        $min->quantity = $remainingQuantity;
                                        $min->save(false);
                                    }else{
                                        $minimum = new Minimal();
                                        $minimum->stock_id = $stock->id;
                                        $minimum->quantity = $remainingQuantity;
                                        $minimum->save(false);
                                    }
                                }
                                //// save minimum/////

                                $modelItem->stocking_id = $stocking_out->id;


                                $expense[$i]['inventory_id'] = $modelItem->inventory_id;
                                $expense[$i]['amount'] = ($stock->avg_cost*$modelItem->quantity);
                                $i++;
                                $tAmount += $modelItem->selling_rate*$modelItem->quantity-$modelItem->discount;
                                $tCost += $modelItem->buying_rate*$modelItem->quantity;
                                $tSales += $modelItem->selling_rate*$modelItem->quantity;
                                $tDiscount += $modelItem->discount;

                                

                                //set flash success
                                if (! ($flag = $modelItem->save(false))) {
                                    $transaction->rollBack();
                                    //set flash success
                                    break;
                                }else{
                                    $stocking_out->reference = $modelItem->id;
                                    $stocking_out->save(false);
                                }
                            }// end if $modelItem->quantity <= $stock->quantity && $modelItem->quantity > 0

                        }// end of foreach

                        $expensesTotal = array();
                        foreach($expense as $ex) {
                            $index = $model->ex_exists($ex['inventory_id'], $expensesTotal);
                            if ($index < 0) {
                                $expensesTotal[] = $ex;
                            }
                            else {
                                $expensesTotal[$index]['amount'] +=  $ex['amount'];
                            }
                        }
                        // print_r($expensesTotal); //display 
                        // die();
                        Yii::$app->getSession()->setFlash('warning', ['type' => 'warning']);
                        $model->amount = $tAmount;
                        $model->cost = $tCost;
                        $model->discount = $tDiscount;
                        $model->save(false);
                    }
                    if ($flag) {
                        $transaction->commit();
                    }
                } //end of try
                 catch (Exception $e) {
                    $transaction->rollBack();
                } //end of catch

                if($model->amount == $paidNow){
                    //invoice method cash, status paid
                    $invoiceMethod = "cash";
                    $invoiceDate = date('Y-m-d');
                    $invoiceStatus = "paid";

                    // new payment data 
                    $paymentAvailable = True;
                    $paymentSystemAccountId = $cashAccount->id;
                    $paymentAmount = $model->amount;
                    $paymentMode = "cash";

                    // use Cash account on transaction 
                    $fullPaymentCash = True;
                    $partialPaymentCash = False;
                    $noCash = False;


                    $cashPaid = $model->amount;

                    // use sales account with Total 
                    $salesAmount = $tSales;
                    $discountAmount = $tDiscount;

                }elseif(($paidNow > 0) && ($paidNow < $model->amount)){
                    //invoice method credit, status partiallyPaid
                    $invoiceMethod = "credit";
                    $invoiceDate = date('Y-m-d');
                    $invoiceStatus = "partially";

                    // new payment with amount of paidNow 
                    $paymentAvailable = True;
                    $paymentSystemAccountId = $cashAccount->id;
                    $paymentAmount = $paidNow;
                    $paymentMode = "cash";

                    // use Cash account with model->pay amount 
                    $fullPaymentCash = False;
                    $partialPaymentCash = True;
                    $noCash = False;
                    $cashPaid = $paidNow;
                    // use client account with ($model->amount - $paidNow) 
                    $recievableAmount = $model->amount - $paidNow;

                    // use sales account with Total 
                    $salesAmount = $tSales;
                    $discountAmount = $tDiscount;

                }else{
                    //invoice method credit, status unPaid
                    $invoiceMethod = "credit";
                    $invoiceDate = date('Y-m-d');
                    $invoiceStatus = "unpaid";

                    //We have no payment
                    $paymentAvailable = false;

                    // use client account with model->amount 
                    $fullPaymentCash = False;
                    $partialPaymentCash = False;
                    $noCash = True;
                    $recievableAmount = $model->amount;

                    // use sales account with Total 
                    $salesAmount = $tSales;
                    $discountAmount = $tDiscount;
                }

                $start = new Transaction();
                $start->description = "Selling Products";
                $start->reference = $model->id;
                $start->reference_type = "Invoices";
                if($start->save(false)){
                    if($fullPaymentCash){
                        //// Depit Cash account + balance////
                        $cash = Yii::$app->mycomponent->increase($cashAccount, $cashPaid, $start->id);

                        //// Credit Sales account + balance////
                        $sales = Yii::$app->mycomponent->increase($salesAccount, $salesAmount, $start->id);

                        if ($discountAmount > 0) {
                            //// Credit Sales account + balance////
                            $dis = Yii::$app->mycomponent->increase($discountAccount, $discountAmount, $start->id);
                        }
                        foreach ($expensesTotal as $ex => $key) {
                            $inventory_id = $key['inventory_id'];
                            $amount = $key['amount'];
                            $inventory = Inventory::findOne($inventory_id);
                            $inventoryAccount = SystemAccount::find()->where(['id' => $inventory->asset_account_id])->one();
                            $inventoryExpenseAccount = SystemAccount::find()->where(['id' => $inventory->expense_account_id])->one();
                            
                            //// Debit Expense Increase Inventory Expense Account + balance ////
                            $expense = Yii::$app->mycomponent->increase($inventoryExpenseAccount, $amount, $start->id);

                            //// Credit Asset Decrease Inventory Asset Account - balance ////
                            $asset = Yii::$app->mycomponent->decrease($inventoryAccount, $amount, $start->id);
                        }
                    }elseif($partialPaymentCash){
                        //// Depit Cash account + balance////
                        $cash = Yii::$app->mycomponent->increase($cashAccount, $cashPaid, $start->id);

                        //// Depit ClientRecievable account + balance////
                        $recievable = Yii::$app->mycomponent->increase($clientRecievable, $recievableAmount, $start->id);

                        //// Credit Sales account + balance////
                        $sales = Yii::$app->mycomponent->increase($salesAccount, $salesAmount, $start->id);

                        if ($discountAmount > 0) {
                            //// Credit Sales account + balance////
                            $dis = Yii::$app->mycomponent->increase($discountAccount, $discountAmount, $start->id);
                        }
                        foreach ($expensesTotal as $ex => $key) {
                            $inventory_id = $key['inventory_id'];
                            $amount = $key['amount'];
                            $inventory = Inventory::findOne($inventory_id);
                            $inventoryAccount = SystemAccount::find()->where(['id' => $inventory->asset_account_id])->one();
                            $inventoryExpenseAccount = SystemAccount::find()->where(['id' => $inventory->expense_account_id])->one();
                            
                            //// Debit Expense Increase Inventory Expense Account + balance ////
                            $expense = Yii::$app->mycomponent->increase($inventoryExpenseAccount, $amount, $start->id);

                            //// Credit Asset Decrease Inventory Asset Account - balance ////
                            $asset = Yii::$app->mycomponent->decrease($inventoryAccount, $amount, $start->id);
                        }  
                    }elseif($noCash){
                        //// Depit ClientRecievable account + balance////
                        $recievable = Yii::$app->mycomponent->increase($clientRecievable, $recievableAmount, $start->id);

                        //// Credit Sales account + balance////
                        $sales = Yii::$app->mycomponent->increase($salesAccount, $salesAmount, $start->id);

                        if ($discountAmount > 0) {
                            //// Credit Sales account + balance////
                            $dis = Yii::$app->mycomponent->increase($discountAccount, $discountAmount, $start->id);
                        }
                        foreach ($expensesTotal as $ex => $key) {
                            $inventory_id = $key['inventory_id'];
                            $amount = $key['amount'];
                            $inventory = Inventory::findOne($inventory_id);
                            $inventoryAccount = SystemAccount::find()->where(['id' => $inventory->asset_account_id])->one();
                            $inventoryExpenseAccount = SystemAccount::find()->where(['id' => $inventory->expense_account_id])->one();
                            
                            //// Debit Expense Increase Inventory Expense Account + balance ////
                            $expense = Yii::$app->mycomponent->increase($inventoryExpenseAccount, $amount, $start->id);

                            //// Credit Asset Decrease Inventory Asset Account - balance ////
                            $asset = Yii::$app->mycomponent->decrease($inventoryAccount, $amount, $start->id);
                        }
                    }
                    $model->method = $invoiceMethod;
                    $model->date = $invoiceDate;
                    $model->status = $invoiceStatus;
                    $model->transaction_id = $start->id;
                    $model->save(false);

                    if($paymentAvailable){
                        $payment = new Payments();
                        $payment->invoice_id = $model->id;
                        $payment->system_account_id = $paymentSystemAccountId;
                        $payment->transaction_id = $start->id;
                        $payment->amount = $paymentAmount ;
                        $payment->mode = $paymentMode ;
                        $payment->save(false);
                    }

                } ///end of start saved

                Yii::$app->getSession()->setFlash('success', ['type' => 'success']);
                return $this->redirect(['view', 'id' => $model->id]);
            } //end of if(valid)

            Yii::$app->getSession()->setFlash('error', ['type' => 'error']);
            return $this->redirect(['index']);


        }// end of (model->load()) 
            return $this->render('sell', [
                'model' => $model,
                'modelsItem' => (empty($modelsItem)) ? [new InvoiceProduct] : $modelsItem
            ]);                      
    }

    public function actionReconcileDelete($id)
    {
        $outstanding = Outstanding::findOne($id);
        $model = $this->findModel($outstanding->invoice_id);
        $outstanding->delete();
        return $this->redirect(['view', 'id' => $model->id]);

    }
    
    public function actionReconcile($account_id, $invoice_id, $outstanding_id)
    {
        $model = Invoices::findOne($invoice_id);
        $cashAccount = SystemAccount::find()->where(['id' => $account_id])->one();
        $client = Client::findOne($model->client_id);
        $clientAccount = SystemAccount::find()->where(['id' => $client->account_id])->one();
        
        $payment = new Payments();

        $outstanding = Outstanding::findOne($outstanding_id);

        if($outstanding->type == 'cheque'){
            $payment->mode = "cheque";
            $payment->bank_name = $outstanding->bank;
            $payment->cheque_no = $outstanding->cheque_no;
            $payment->cheque_date = $outstanding->cheque_date;
        }else{
            $payment->mode = "cash";
        }


        // Saving the payment //
            $payment->amount = $outstanding->amount;
            $payment->invoice_id = $model->id;
            $payment->system_account_id = $cashAccount->id ;
            $payment->save(false);
        // Saving the payment //

        // Fixing outstanding Status//
            $outstanding->payment_id = $payment->id;
            $outstanding->status = 'clear';
            $outstanding->save(false);
        // Fixing outstanding Status//

        // Fixing Invoice Status //
            $total_cash = $model->payments;
            $total_paid = 0;
            if($total_cash){
                foreach ($total_cash as $p) {
                    $total_paid += $p->amount ;
                }
            }
            $remaining = $model->amount - $total_paid ;

            if($remaining > 0){
                $model->status = "partially";
                $model->save(false);

            }else{
                $model->status = "paid";
                $model->save(false);
            }
        // Fixing Invoice Status //

        // Keeping journal entry by registering the cash in //
            $start = new Transaction();
            $start->description = "Reconciling payment for invoice ".$model->id;
            $start->reference = $model->id;
            $start->reference_type = "Invoices";
            if($start->save(false)){
            
            // Depiting Cash//
                $cash = new Entry();
                $account = $cashAccount;
                $cash->transaction_id = $start->id; 
                $cash->account_id = $account->id; 
                $cash->is_depit = 'yes'; 
                $cash->amount = $payment->amount; 
                $cash->description = "Cash Increased because of selling products in this invoice"; 
                $cash->date = date('Y-m-d'); 
                $cash->balance = $account->balance + $payment->amount; 
                if($cash->save(false)){
                    $account->balance += $payment->amount;
                    $account->save(false); 
                }
            // Depiting Cash//

            // Crediting Client Account//
                $recievable = new Entry();
                $account = $clientAccount;
                $recievable->transaction_id = $start->id; 
                $recievable->account_id = $account->id; 
                $recievable->is_depit = 'no'; 
                $recievable->amount = $payment->amount; 
                $recievable->description = "recievable Amount decreased for paying this invoice"; 
                $recievable->date = date('Y-m-d'); 
                $recievable->balance = $account->balance - $payment->amount; 
                if($recievable->save(false)){
                    $account->balance -= $payment->amount;
                    $account->save(false); 
                }
            // Crediting Client Account//

            // Linking Payment with this transaction //
                $payment->transaction_id = $start->id;
                $payment->save(false);
            // Linking Payment with this transaction //
            }
        // Keeping journal entry by registering the cash in //


        return $this->redirect(['view', 'id' => $model->id]);

    }

    public function actionDetails($product_id, $inventory_id)
    {
        $product = Product::find()->where(['id' => $product_id])->one();
        $stock = Stock::find()
                    ->where(['product_id' => $product->id])
                    ->andWhere(['inventory_id' => $inventory_id])->one();

        $current_rate = Yii::$app->mycomponent->rate();
        $highest_rate = $stock->highest_rate;
        if ($current_rate > $highest_rate) {
           $price = $product->selling_price*$current_rate; 
        }else{
          $price = $product->selling_price*$highest_rate; 
        }
        // if($product){
            return \yii\helpers\Json::encode([
                'selling_price'=>$price,
                'quantity'=>$stock->quantity,
            ]);   
        // }

    }

    public function actionCash($id)
    {   
        $model = $this->findModel($id);
        $payment = new Payments();

        if($payment->load(Yii::$app->request->post())){
            
            $cashAccount = SystemAccount::find()->where(['system_account_name' => 'cash'])->one();
            $client = Client::findOne($model->client_id);
            $clientAccount = SystemAccount::find()->where(['id' => $client->account_id])->one();
        // Saveing the payment //
            $payment->invoice_id = $model->id;
            $payment->system_account_id = $cashAccount->id ;
            $payment->mode = "cash";
            $payment->save(false);
        // Saveing the payment //

        // Fixing Invoice Status //
            $total_cash = $model->payments;
            $total_paid = 0;
            if($total_cash){
                foreach ($total_cash as $p) {
                    $total_paid += $p->amount ;
                }
            }
            $remaining = $model->amount - $total_paid ;

            if($remaining > 0){
                $model->status = "partially";
                $model->save(false);

            }else{
                $model->status = "paid";
                $model->save(false);
            }
        // Fixing Invoice Status //

        // Keeping journal entry by registering the cash in //
            $start = new Transaction();
            $start->description = "Paying for invoice ".$model->id;
            $start->reference = $model->id;
            $start->reference_type = "Invoices";
            if($start->save(false)){
            
            // Depiting Cash//
                $cash = new Entry();
                $account = $cashAccount;
                $cash->transaction_id = $start->id; 
                $cash->account_id = $account->id; 
                $cash->is_depit = 'yes'; 
                $cash->amount = $payment->amount; 
                $cash->description = "Cash Increased because of paying for this invoice"; 
                $cash->date = date('Y-m-d'); 
                $cash->balance = $account->balance + $payment->amount; 
                if($cash->save(false)){
                    $account->balance += $payment->amount;
                    $account->save(false); 
                }
            // Depiting Cash//

            // Crediting Client Account//
                $recievable = new Entry();
                $account = $clientAccount;
                $recievable->transaction_id = $start->id; 
                $recievable->account_id = $account->id; 
                $recievable->is_depit = 'no'; 
                $recievable->amount = $payment->amount; 
                $recievable->description = "recievable amount decreased because of paying this invoice"; 
                $recievable->date = date('Y-m-d'); 
                $recievable->balance = $account->balance - $payment->amount; 
                if($recievable->save(false)){
                    $account->balance -= $payment->amount;
                    $account->save(false); 
                }
            // Crediting Client Account//

            // Linking Payment with this transaction //
                $payment->transaction_id = $start->id;
                $payment->save(false);
            // Linking Payment with this transaction //
            }
        // Keeping journal entry by registering the cash in //


            return $this->redirect(['view', 'id' => $model->id]);

        }
        return $this->renderAjax('cash', [
            'model' => $model,
            'payment' => $payment,
        ]);

    }

    public function actionCheque($id)
    {   
        $model = $this->findModel($id);
        $outstanding = new Outstanding();

        if($outstanding->load(Yii::$app->request->post())){

            $outstanding->invoice_id = $model->id;
            $outstanding->client_id = $model->client_id ;
            $outstanding->type = "cheque";
            $outstanding->status = "outstanding";
            $outstanding->save(false);
            //set flash success

            return $this->redirect(['view', 'id' => $model->id]);

        }
        return $this->renderAjax('cheque', [
            'model' => $model,
            'outstanding' => $outstanding,
        ]);

    }

    public function actionPromise($id)
    {   
        $model = $this->findModel($id);
        $outstanding = new Outstanding();

        if($outstanding->load(Yii::$app->request->post())){

            $outstanding->invoice_id = $model->id;
            $outstanding->client_id = $model->client_id ;
            $outstanding->type = "promise";
            $outstanding->status = "outstanding";
            $outstanding->save(false);
            //set flash success

            return $this->redirect(['view', 'id' => $model->id]);

        }

        return $this->renderAjax('promise', [
            'model' => $model,
            'outstanding' => $outstanding,
        ]);

    }

    public function actionIndex()
    {
        $searchModel = new InvoicesSearch();
        $dataProvider = $searchModel->search(Yii::$app->request->queryParams);

        return $this->render('index', [
            'searchModel' => $searchModel,
            'dataProvider' => $dataProvider,
        ]);
    }

    public function actionView($id)
    {   
        $model = $this->findModel($id);
        // $products = $this->findModel($id);
        return $this->render('view', [
            'model' => $model,
        ]);
    }

    
    public function actionCreate($id)
    {
        $model = new Invoices();
        $modelsItem = [new InvoiceProduct];
        $inventory = Inventory::findOne($id);

        if($model->load(Yii::$app->request->post()) ){
            $inventoryAccount = SystemAccount::find()->where(['id' => $inventory->asset_account_id])->one();
            $inventoryExpenseAccount = SystemAccount::find()->where(['id' => $inventory->expense_account_id])->one();
            $client_id = $_POST['Invoices']['client_id'];
            $client = Client::findOne($client_id);
            $clientAccount = SystemAccount::find()->where(['id' => $client->account_id])->one();
            $cashAccount = SystemAccount::find()->where(['account_no' => 1100])->one();
            $paidNow = $model->pay;
            
            $expense = 0;
             

            $modelsItem = Model::createMultiple(InvoiceProduct::classname());
            Model::loadMultiple($modelsItem, Yii::$app->request->post());
                       
            $model->method = 'undefined';
            $model->date = date('Y-m-d');
            $model->status = 'unpaid';
            $model->inventory_id = $inventory->id;
            $model->cost = 0;
        
            $valid = $model->validate();
            //$valid = Model::validateMultiple($modelsItem) && $valid;

            $tAmount = 0;
            $tCost = 0;
            if ($valid) {
                $transaction = \Yii::$app->db->beginTransaction();
                try {
                    if($flag = $model->save(false)) {                       
                       
                        foreach ($modelsItem as $modelItem){       

                            $stock = Stock::find()
                                    ->where(['product_id' => $modelItem->product_id])
                                    ->andWhere(['inventory_id' => $inventory->id])->one();

                            if($modelItem->quantity <= $stock->quantity && $modelItem->quantity > 0){
                                $product = Product::findOne($stock->product_id);
                                $current_rate = Yii::$app->mycomponent->rate();
                                $highest_rate = $stock->highest_rate;
                                
                                $modelItem->invoice_id = $model->id;
                                $modelItem->product_id = $product->id;                                
                                if ($current_rate > $highest_rate) {
                                    $modelItem->selling_rate = $product->selling_price*$current_rate;
                                    $modelItem->buying_rate = $stock->avg_cost*$current_rate;
                                    $modelItem->d_rate = $current_rate;
                                }else{
                                    $modelItem->selling_rate = $product->selling_price*$highest_rate;
                                    $modelItem->buying_rate = $stock->avg_cost*$highest_rate;
                                    $modelItem->d_rate = $highest_rate; 
                                }

                                $stockings = Stocking::find()
                                    ->where(['product_id' => $modelItem->product_id])
                                    ->andWhere(['inventory_id' => $inventory->id])
                                    ->andWhere(['transaction' => 'in'])
                                    ->orWhere(['transaction' => 'returned'])
                                    ->orderBy(['rate' => SORT_DESC])
                                    ->all();
                                $q = $modelItem->quantity;
                                
                                foreach ($stockings as $s) {
                                    if ($s->quantity > $q) {
                                        $s->quantity -= $q;
                                        $s->save(false);
                                        break;
                                    }
                                    elseif ($s->quantity <= $q) {
                                        $q -= $s->quantity;
                                        $s->delete(false);
                                    }
                                }
                                    
                                $stocking_out = new Stocking();
                                $stocking_out->inventory_id = $stock->inventory_id;
                                $stocking_out->product_id = $stock->product_id;
                                $stocking_out->buying_price = $stock->avg_cost;
                                $stocking_out->selling_price = $product->selling_price;
                                $stocking_out->quantity = $modelItem->quantity;
                                $stocking_out->rate = $modelItem->d_rate;
                                $stocking_out->transaction = "out";
                                $stocking_out->save(false);

                                $checkRate = Stocking::find()
                                    ->where(['product_id' => $product->id])
                                    ->andWhere(['inventory_id' => $inventory->id])
                                    ->andWhere(['transaction' => 'in'])
                                    ->orWhere(['transaction' => 'returned'])
                                    ->max('rate');

                                if ($checkRate != $stock->highest_rate) {
                                    $stock->highest_rate = $checkRate;
                                }
                                $stock->quantity -= $stocking_out->quantity;
                                $stock->save(false);


                                //// save minimum/////
                                if ($stock->quantity < $product->minimum) {
                                    $remainingQuantity = $product->minimum - $stock->quantity;
                                    $min = Minimal::find()->where(['stock_id' => $stock->id])->one();
                                    if ($min) {
                                        $min->quantity = $remainingQuantity;
                                        $min->save(false);
                                    }else{
                                        $minimum = new Minimal();
                                        $minimum->stock_id = $stock->id;
                                        $minimum->quantity = $remainingQuantity;
                                        $minimum->save(false);
                                    }
                                }
                                //// save minimum/////

                                $modelItem->stocking_id = $stocking_out->id;

                                $expense += ($stock->avg_cost*$modelItem->quantity);
                                $tAmount += $modelItem->selling_rate*$modelItem->quantity;
                                $tCost += $modelItem->buying_rate*$modelItem->quantity;

                                //set flash success
                                if (! ($flag = $modelItem->save(false))) {
                                    $transaction->rollBack();
                                    //set flash success
                                    break;
                                }else{
                                    $stocking_out->reference = $modelItem->id;
                                    $stocking_out->save(false);
                                }
                            }// end if $modelItem->quantity <= $stock->quantity && $modelItem->quantity > 0

                        }// end of foreach
                        Yii::$app->getSession()->setFlash('warning', ['type' => 'warning']);
                        $model->amount = $tAmount;
                        $model->cost = $tCost;
                        $model->save(false);
                    }
                    if ($flag) {
                        $transaction->commit();
                    }
                } //end of try
                 catch (Exception $e) {
                    $transaction->rollBack();
                } //end of catch

                if($model->amount == $paidNow){
                    //invoice method cash, status paid
                    $invoiceMethod = "cash";
                    $invoiceDate = date('Y-m-d');
                    $invoiceStatus = "paid";

                    // new payment data 
                    $paymentAvailable = True;
                    $paymentSystemAccountId = $cashAccount->id;
                    $paymentAmount = $model->amount;
                    $paymentMode = "cash";

                    // use Cash account on transaction 
                    $fullPaymentCash = True;
                    $partialPaymentCash = False;
                    $noCash = False;


                    $cashPaid = $model->amount;

                    // use sales account with Total 
                    $salesAmount = $model->amount;

                }elseif(($paidNow > 0) && ($paidNow < $model->amount)){
                    //invoice method credit, status partiallyPaid
                    $invoiceMethod = "credit";
                    $invoiceDate = date('Y-m-d');
                    $invoiceStatus = "partially";

                    // new payment with amount of paidNow 
                    $paymentAvailable = True;
                    $paymentSystemAccountId = $cashAccount->id;
                    $paymentAmount = $paidNow;
                    $paymentMode = "cash";

                    // use Cash account with model->pay amount 
                    $fullPaymentCash = False;
                    $partialPaymentCash = True;
                    $noCash = False;
                    $cashPaid = $paidNow;
                    // use client account with ($model->amount - $paidNow) 
                    $recievableAmount = $model->amount - $paidNow;

                    // use sales account with Total 
                    $salesAmount = $model->amount;

                }else{
                    //invoice method credit, status unPaid
                    $invoiceMethod = "credit";
                    $invoiceDate = date('Y-m-d');
                    $invoiceStatus = "unpaid";

                    //We have no payment
                    $paymentAvailable = false;

                    // use client account with model->amount 
                    $fullPaymentCash = False;
                    $partialPaymentCash = False;
                    $noCash = True;
                    $recievableAmount = $model->amount;

                    // use sales account with Total 
                    $salesAmount = $model->amount;
                }

                $start = new Transaction();
                $start->description = "Selling Products";
                $start->reference = $model->id;
                $start->reference_type = "Invoices";
                if($start->save(false)){
                    if($fullPaymentCash){
                        //// Depit Cash account + balance////
                            $cash = new Entry();
                            $account = $cashAccount;
                            $cash->transaction_id = $start->id; 
                            $cash->account_id = $account->id; 
                            $cash->is_depit = 'yes'; 
                            $cash->amount = $cashPaid; 
                            $cash->description = "Cash Increased because of selling products in this invoice"; 
                            $cash->date = date('Y-m-d'); 
                            $cash->balance = $account->balance + $cashPaid; 
                            if($cash->save(false)){
                                $account->balance += $cashPaid;
                                $account->save(false); 
                            }
                        //// Depit Cash account + balance////

                        //// Credit Sales account + balance////
                            $sales = new Entry();
                            $account = SystemAccount::find()->where(['system_account_name' => 'sales'])->one();
                            $sales->transaction_id = $start->id; 
                            $sales->account_id = $account->id; 
                            $sales->is_depit = 'no'; 
                            $sales->amount = $salesAmount; 
                            $sales->description = "Revenue is been counted for this sale"; 
                            $sales->date = date('Y-m-d'); 
                            $sales->balance = $account->balance + $salesAmount; 
                            if($sales->save(false)){
                                $account->balance += $salesAmount;
                                $account->save(false); 
                            }
                        //// Credit Sales account + balance ////

                        //// Depit Sale_expenses account + balance////
                            $sales_expenses = new Entry();
                            $account = $inventoryExpenseAccount;
                            $sales_expenses->transaction_id = $start->id; 
                            $sales_expenses->account_id = $account->id; 
                            $sales_expenses->is_depit = 'yes'; 
                            $sales_expenses->amount = $expense; 
                            $sales_expenses->description = "Cost is been counted for this sale"; 
                            $sales_expenses->date = date('Y-m-d'); 
                            $sales_expenses->balance = $account->balance + $expense; 
                            if($sales_expenses->save(false)){
                                $account->balance += $expense;
                                $account->save(false); 
                            }
                        //// Depit Sale_expenses account + balance////

                        //// Credit inventory account - balance////
                            $inventory_expenses = new Entry();
                            $account = $inventoryAccount ;
                            $inventory_expenses->transaction_id = $start->id; 
                            $inventory_expenses->account_id = $account->id; 
                            $inventory_expenses->is_depit = 'no'; 
                            $inventory_expenses->amount = $expense; 
                            $inventory_expenses->description = "Cost of products for this sale"; 
                            $inventory_expenses->date = date('Y-m-d'); 
                            $inventory_expenses->balance = $account->balance - $expense; 
                            if($inventory_expenses->save(false)){
                                $account->balance -= $expense;
                                $account->save(false); 
                            }
                        //// Credit inventory account - balance////
                    }elseif($partialPaymentCash){
                        //// Depit Cash account + balance////
                            $cash = new Entry();
                            $account = $cashAccount;
                            $cash->transaction_id = $start->id; 
                            $cash->account_id = $account->id; 
                            $cash->is_depit = 'yes'; 
                            $cash->amount = $cashPaid; 
                            $cash->description = "Cash Increased because of selling products in this invoice"; 
                            $cash->date = date('Y-m-d'); 
                            $cash->balance = $account->balance + $cashPaid; 
                            if($cash->save(false)){
                                $account->balance += $cashPaid;
                                $account->save(false); 
                            }
                        //// Depit Cash account + balance////

                        //// Depit ClientRecievable account + balance////
                            $recievable = new Entry();
                            $account = $clientAccount;
                            $recievable->transaction_id = $start->id; 
                            $recievable->account_id = $account->id; 
                            $recievable->is_depit = 'yes'; 
                            $recievable->amount = $recievableAmount; 
                            $recievable->description = "recievable Cash Increased because of selling products in this invoice"; 
                            $recievable->date = date('Y-m-d'); 
                            $recievable->balance = $account->balance + $recievableAmount; 
                            if($recievable->save(false)){
                                $account->balance += $recievableAmount;
                                $account->save(false); 
                            }
                        //// Depit ClientRecievable account + balance////

                        //// Credit Sales account + balance////
                            $sales = new Entry();
                            $account = SystemAccount::find()->where(['system_account_name' => 'sales'])->one();
                            $sales->transaction_id = $start->id; 
                            $sales->account_id = $account->id; 
                            $sales->is_depit = 'no'; 
                            $sales->amount = $salesAmount; 
                            $sales->description = "Revenue is been counted for this sale"; 
                            $sales->date = date('Y-m-d'); 
                            $sales->balance = $account->balance + $salesAmount; 
                            if($sales->save(false)){
                                $account->balance += $salesAmount;
                                $account->save(false); 
                            }
                        //// Credit Sales account + balance ////

                        //// Depit Sale_expenses account + balance////
                            $sales_expenses = new Entry();
                            $account = $inventoryExpenseAccount;
                            $sales_expenses->transaction_id = $start->id; 
                            $sales_expenses->account_id = $account->id; 
                            $sales_expenses->is_depit = 'yes'; 
                            $sales_expenses->amount = $expense; 
                            $sales_expenses->description = "Cost is been counted for this sale"; 
                            $sales_expenses->date = date('Y-m-d'); 
                            $sales_expenses->balance = $account->balance + $expense; 
                            if($sales_expenses->save(false)){
                                $account->balance += $expense;
                                $account->save(false); 
                            }
                        //// Depit Sale_expenses account + balance////

                        //// Credit inventory account - balance////
                            $inventory_expenses = new Entry();
                            $account = $inventoryAccount ;
                            $inventory_expenses->transaction_id = $start->id; 
                            $inventory_expenses->account_id = $account->id; 
                            $inventory_expenses->is_depit = 'no'; 
                            $inventory_expenses->amount = $expense; 
                            $inventory_expenses->description = "Cost of products for this sale"; 
                            $inventory_expenses->date = date('Y-m-d'); 
                            $inventory_expenses->balance = $account->balance - $expense; 
                            if($inventory_expenses->save(false)){
                                $account->balance -= $expense;
                                $account->save(false); 
                            }
                        //// Credit inventory account - balance////  
                    }elseif($noCash){
                        //// Depit ClientRecievable account + balance////
                            $recievable = new Entry();
                            $account = $clientAccount;
                            $recievable->transaction_id = $start->id; 
                            $recievable->account_id = $account->id; 
                            $recievable->is_depit = 'yes'; 
                            $recievable->amount = $recievableAmount; 
                            $recievable->description = "recievable Cash Increased because of selling products in this invoice"; 
                            $recievable->date = date('Y-m-d'); 
                            $recievable->balance = $account->balance + $recievableAmount; 
                            if($recievable->save()){
                                $account->balance += $recievableAmount;
                                $account->save(); 
                            }
                        //// Depit ClientRecievable account + balance////

                        //// Credit Sales account + balance////
                            $sales = new Entry();
                            $account = SystemAccount::find()->where(['system_account_name' => 'sales'])->one();
                            $sales->transaction_id = $start->id; 
                            $sales->account_id = $account->id; 
                            $sales->is_depit = 'no'; 
                            $sales->amount = $salesAmount; 
                            $sales->description = "Revenue is been counted for this sale"; 
                            $sales->date = date('Y-m-d'); 
                            $sales->balance = $account->balance + $salesAmount; 
                            if($sales->save()){
                                $account->balance += $salesAmount;
                                $account->save(); 
                            }
                        //// Credit Sales account + balance ////

                        //// Depit Sale_expenses account + balance////
                            $sales_expenses = new Entry();
                            $account = $inventoryExpenseAccount;
                            $sales_expenses->transaction_id = $start->id; 
                            $sales_expenses->account_id = $account->id; 
                            $sales_expenses->is_depit = 'yes'; 
                            $sales_expenses->amount = $expense; 
                            $sales_expenses->description = "Cost is been counted for this sale"; 
                            $sales_expenses->date = date('Y-m-d'); 
                            $sales_expenses->balance = $account->balance + $expense; 
                            if($sales_expenses->save()){
                                $account->balance += $expense;
                                $account->save(); 
                            }
                        //// Depit Sale_expenses account + balance////

                        //// Credit inventory account - balance////
                            $inventory_expenses = new Entry();
                            $account = $inventoryAccount ;
                            $inventory_expenses->transaction_id = $start->id; 
                            $inventory_expenses->account_id = $account->id; 
                            $inventory_expenses->is_depit = 'no'; 
                            $inventory_expenses->amount = $expense; 
                            $inventory_expenses->description = "Cost of products for this sale"; 
                            $inventory_expenses->date = date('Y-m-d'); 
                            $inventory_expenses->balance = $account->balance - $expense; 
                            if($inventory_expenses->save()){
                                $account->balance -= $expense;
                                $account->save(); 
                            }
                        //// Credit inventory account - balance////
                    }
                    $model->method = $invoiceMethod;
                    $model->date = $invoiceDate;
                    $model->status = $invoiceStatus;
                    $model->transaction_id = $start->id;
                    $model->save(false);

                    if($paymentAvailable){
                        $payment = new Payments();
                        $payment->invoice_id = $model->id;
                        $payment->system_account_id = $paymentSystemAccountId;
                        $payment->transaction_id = $start->id;
                        $payment->amount = $paymentAmount ;
                        $payment->mode = $paymentMode ;
                        $payment->save(false);
                    }

                } ///end of start saved

                Yii::$app->getSession()->setFlash('success', ['type' => 'success']);
                return $this->redirect(['view', 'id' => $model->id]);
            } //end of if(valid)

            Yii::$app->getSession()->setFlash('error', ['type' => 'error']);
            return $this->redirect(['index']);


        }// end of (model->load()) 
            return $this->renderAjax('_form', [
                'model' => $model,
                'inventory' => $inventory,
                'modelsItem' => (empty($modelsItem)) ? [new InvoiceProduct] : $modelsItem
            ]);                      
    }

    
    public function actionUpdate($id)
    {
        $model = $this->findModel($id);
        $modelsItem = $model->invoiceProducts;

        $paid = False;
        $clientRedirct = False;
        $original_amount = $model->amount;
        $payment_transaction = $model->transaction_id;
        // $inventory = Inventory::find()->where(['id' => $model->inventory_id])->one();
        
        if($model->load(Yii::$app->request->post())) {

            $oldIDs = ArrayHelper::map($modelsItem, 'id', 'id');
            $modelsItem = Model::createMultiple(InvoiceProduct::classname(), $modelsItem);
            Model::loadMultiple($modelsItem, Yii::$app->request->post());
            $deletedIDs = array_diff($oldIDs, array_filter(ArrayHelper::map($modelsItem, 'id', 'id')));
            // ajax validation
            if (Yii::$app->request->isAjax) {
                Yii::$app->response->format = Response::FORMAT_JSON;
                return ArrayHelper::merge(
                    ActiveForm::validateMultiple($modelsItem),
                    ActiveForm::validate($model)
                );
            }
            // validate all models
            $valid = $model->validate();
            // $valid = Model::validateMultiple($modelsItem) && $valid;
            if ($valid) {
                $transaction = \Yii::$app->db->beginTransaction();
                try {
                    if($flag = $model->save(false)){
                            $sells_return = 0;
                            $cost_return = 0;
                            $model_amount_return = 0;
                            $model_cost_return = 0;
                        if (! empty($deletedIDs)) {
                            //// need to calculate those/////
                            foreach ($deletedIDs as $ID ) {
                                $deleted = InvoiceProduct::findOne($ID);
                                $stocking_out = Stocking::findOne($deleted->stocking_id);
                                
                                $sells_return += $deleted->selling_rate * $deleted->quantity;
                                $cost_return += $stocking_out->buying_price * $stocking_out->quantity;
                                
                                $model_amount_return += $sells_return;
                                $model_cost_return += $deleted->buying_rate * $deleted->quantity;

                                /// stocking it in again/////
                                $stocking_out->transaction= "returned";
                                $stocking_out->save(false);

                                //// put back to stock /////
                                    $stock = Stock::find()
                                        ->where(['product_id' => $deleted->product_id])
                                        ->andWhere(['inventory_id' => $model->inventory_id])
                                        ->one();

                                    $checkRate = Stocking::find()
                                            ->where(['product_id' => $deleted->product_id])
                                            ->andWhere(['inventory_id' => $inventory->id])
                                            ->andWhere(['transaction' => 'in'])
                                            ->orWhere(['transaction' => 'returned'])
                                            ->max('rate');

                                    if ($checkRate != $stock->highest_rate) {
                                        $stock->highest_rate = $checkRate;
                                    }
                                    $stock->quantity += $stocking_out->quantity;
                                    $stock->save(false); 
                                //// put back to stock /////

                                 // Update minimal ////
                                    $min = Minimal::find()->where(['stock_id' => $stock->id])->one();
                                    if ($min) {
                                        if ($stock->quantity < $deleted->product->minimum) {
                                            $remainingQuantity = $modelItem->product->minimum - $stock->quantity;
                                            $min->quantity = $remainingQuantity;
                                            $min->save(false);
                                        }else{
                                            $min->delete(false);
                                        }
                                    }
                                $deleted->returned = 1;
                                $deleted->save(false);
                                
                            } 
                            // InvoiceProduct::deleteAll(['id' => $deletedIDs]);   
                        }
                        foreach ($modelsItem as $modelItem) {
                                $stock = Stock::find()
                                        ->where(['product_id' => $modelItem->product_id])
                                        ->andWhere(['inventory_id' => $model->inventory_id])
                                        ->one();

                                $stocking_out = Stocking::findOne($modelItem->stocking_id);
                                $q = $stocking_out->quantity - $modelItem->quantity;

                                if ($q > 0) {
                                    $sells_return += $modelItem->selling_rate * $q;
                                    $cost_return += $stocking_out->buying_price * $q;
                                    $model_amount_return += $sells_return;
                                    $model_cost_return += $modelItem->buying_rate * $q;
                                    
                                    $stocking_out->quantity = $modelItem->quantity;
                                    $stocking_out->save(false);

                                    $stocking_in = new Stocking();
                                    $stocking_in->inventory_id = $stocking_out->inventory_id;
                                    $stocking_in->product_id = $stocking_out->product_id;
                                    $stocking_in->buying_price = $stocking_out->buying_price;
                                    $stocking_in->selling_price = $stocking_out->selling_price;
                                    $stocking_in->quantity = $q;
                                    $stocking_in->transaction = "returned";
                                    $stocking_in->rate = $stocking_out->rate;
                                    $stocking_in->reference = $modelItem->id;
                                    $stocking_in->save(false);

                                    $return_pro = new InvoiceProduct();
                                    $return_pro->invoice_id = $model->id ;
                                    $return_pro->product_id = $modelItem->product_id;
                                    $return_pro->quantity = $q ;
                                    $return_pro->buying_rate = $modelItem->buying_rate;
                                    $return_pro->selling_rate = $modelItem->selling_rate;
                                    $return_pro->d_rate = $modelItem->d_rate;
                                    $return_pro->stocking_id = $stocking_in->id;
                                    $return_pro->returned = 1;
                                    $return_pro->save(false);

                                    $stocking_in->reference = $return_pro->id;
                                    $stocking_in->save(false);

                                    
                                    $checkRate = Stocking::find()
                                        ->where(['product_id' => $modelItem->product_id])
                                        ->andWhere(['inventory_id' => $inventory->id])
                                        ->andWhere(['transaction' => 'in'])
                                        ->orWhere(['transaction' => 'returned'])
                                        ->max('rate');

                                    if ($checkRate != $stock->highest_rate) {
                                        $stock->highest_rate = $checkRate;
                                    }

                                    $stock->quantity += $q;
                                    $stock->save(false);

                                    $min = Minimal::find()->where(['stock_id' => $stock->id])->one();
                                    if ($min) {
                                        if ($stock->quantity < $modelItem->product->minimum) {
                                                $remainingQuantity = $modelItem->product->minimum - $stock->quantity;
                                                $min->quantity = $remainingQuantity;
                                                $min->save(false);
                                        }else{
                                            $min->delete(false);
                                        }
                                    }

                                    
                                }

                                
                            if (! ($flag = $modelItem->save(false))) {
                                $transaction->rollBack();
                                break;
                            }
                        }
                    }//// endo of if ($flag = $model->save(false))
                    
                    if ($flag) {
                        $transaction->commit();
                    }
                } catch (Exception $e) {
                    $transaction->rollBack();
                }///end of try

                $model->amount = $model_amount_return ;
                $model->cost = $model_cost_return;
                $model->save();

                if($sells_return > 0 && $cost_return > 0){

                    $outstanding = $model->outstanding;
                    if ($outstanding) {
                        foreach ($outstanding as $o) {
                            $o->delete(false);
                        }
                    }
                    

                    $total_paid_amount = $model->totalPaid;
                    if($total_paid_amount > $model->amount){
                        $returned_amount = $total_paid_amount-$model->amount;
                        $payments = $model->payments;
                        foreach ($payments as $pay) {
                            $pay->delete(false);
                        }
                        $payment = new Payments();
                        $payment->invoice_id = $model->id;
                        $payment->system_account_id = 1;
                        $payment->amount = $model->amount ;
                        $payment->mode = 'cash' ;
                        $payment->save(false);
                        $paid = True;

                        $model->status = 'paid';
                        $model->save(false);

                        $returnedPayment = new ReturnedPayment();
                        $returnedPayment->invoice_id = $model->id;
                        $returnedPayment->amount = $returned_amount;
                        $returnedPayment->save(false);
                        $clientRedirct = True;
                        

                    }
                    
                    //// Accounts and entry transcation  ////
                        $sells_return_account =  SystemAccount::find()->where(['account_no' => '4100'])->one();
                        $inventory_asset =  SystemAccount::find()->where(['id' => $inventory->asset_account_id])->one();
                        $inventory_expenses =  SystemAccount::find()->where(['id' => $inventory->expense_account_id])->one();
                        $client = Client::findOne($model->client_id);
                        $clientRecievable = SystemAccount::find()->where(['id' => $client->account_id])->one();
                        
                        
                        if ($clientRecievable->balance < $sells_return && $clientRecievable->balance > 0 ) {
                            $payable = True;
                            $payableAll = False;
                            $recievableAll = False;
                            $payable_amount = $sells_return-$clientRecievable->balance;
                            $recievable_amount = $sells_return-$payable_amount;
                            $clientPayable = SystemAccount::find()->where(['id' => $client->payable_id])->one();

                            if (!($clientPayable)) {
                                $payable_account = new SystemAccount();
                                $max = SystemAccount::find()->where(['group'=> 'client payable'])->max('account_no');
                                if($max){
                                    $payable_account->account_no = $max+1;
                                }else{
                                    $payable_account->account_no = 2600;
                                }
                                $payable_account->system_account_name = $client->client_name." payable";
                                $payable_account->account_type_id = 2;
                                $payable_account->description = "Payable amount for client :".$client->client_name;
                                $payable_account->opening_balance = 0;
                                $payable_account->balance = 0;
                                $payable_account->group = "client payable";
                                $payable_account->to_increase = "credit";
                                $payable_account->color_class = $client->color_class;
                                $payable_account->save(false);
                                $client->payable_id = $payable_account->id;
                                $client->save(false);
                                $clientPayable = $payable_account ;
                            }    
                        }elseif ($clientRecievable->balance <= 0) {
                            $payable = False;
                            $payableAll = True;
                            $recievableAll = False;
                            $payable_amount = $sells_return;
                            $clientPayable = SystemAccount::find()->where(['id' => $client->payable_id])->one();
                            if (!($clientPayable)) {
                                $payable_account = new SystemAccount();
                                $max = SystemAccount::find()->where(['group'=> 'client payable'])->max('account_no');
                                if($max){
                                    $payable_account->account_no = $max+1;
                                }else{
                                    $payable_account->account_no = 2600;
                                }
                                $payable_account->system_account_name = $client->client_name." payable";
                                $payable_account->account_type_id = 2;
                                $payable_account->description = "Payable amount for client :".$client->client_name;
                                $payable_account->opening_balance = 0;
                                $payable_account->balance = 0;
                                $payable_account->group = "client payable";
                                $payable_account->to_increase = "credit";
                                $payable_account->color_class = $client->color_class;
                                $payable_account->save(false);
                                $client->payable_id = $payable_account->id;
                                $client->save(false);
                                $clientPayable = $payable_account ;
                            }
                        }else {
                            $recievable_amount = $sells_return;
                            $recievableAll = True;
                            $payableAll = False;
                            $payable = False;
                        }

                        $start = new Transaction();
                        $start->description = "Returning Products";
                        $start->reference = $model->id;
                        $start->reference_type = "Invoices";
                        if($start->save(false)){

                            //// Depit inventory account + balance////
                                $i_asset = new Entry();
                                $account = $inventory_asset ;
                                $i_asset->transaction_id = $start->id; 
                                $i_asset->account_id = $account->id; 
                                $i_asset->is_depit = 'yes'; 
                                $i_asset->amount = $cost_return; 
                                $i_asset->description = "Returning Products after selling"; 
                                $i_asset->date = date('Y-m-d'); 
                                $i_asset->balance = $account->balance + $cost_return; 
                                if($i_asset->save(false)){
                                    $account->balance += $cost_return;
                                    $account->save(false); 
                                }
                            //// Depit inventory account + balance//// 

                            //// Credit inventory expenses account - balance////
                                $i_expenses = new Entry();
                                $account = $inventory_expenses;
                                $i_expenses->transaction_id = $start->id; 
                                $i_expenses->account_id = $account->id; 
                                $i_expenses->is_depit = 'no'; 
                                $i_expenses->amount = $cost_return; 
                                $i_expenses->description = "Returning Products after selling"; 
                                $i_expenses->date = date('Y-m-d'); 
                                $i_expenses->balance = $account->balance - $cost_return; 
                                if($i_expenses->save(false)){
                                    $account->balance -= $cost_return;
                                    $account->save(false); 
                                }
                            //// Credit inventory expenses account - balance////

                            //// Depit Sales return + balance////
                                $sales_return_acc = new Entry();
                                $account = $sells_return_account;
                                $sales_return_acc->transaction_id = $start->id; 
                                $sales_return_acc->account_id = $account->id; 
                                $sales_return_acc->is_depit = 'yes'; 
                                $sales_return_acc->amount = $sells_return;  
                                $sales_return_acc->description = "Revenue has been reduced for this sale"; 
                                $sales_return_acc->date = date('Y-m-d'); 
                                $sales_return_acc->balance = $account->balance + $sells_return; 
                                if($sales_return_acc->save(false)){
                                    $account->balance += $sells_return;
                                    $account->save(false); 
                                }
                            //// Depit Sales return + balance ////

                            if ($payable) {
                            //// Credit ClientRecievable account - balance////
                                $recievable = new Entry();
                                $account = $clientRecievable;
                                $recievable->transaction_id = $start->id; 
                                $recievable->account_id = $account->id; 
                                $recievable->is_depit = 'no'; 
                                $recievable->amount = $recievable_amount; 
                                $recievable->description = "Substracting sold items"; 
                                $recievable->date = date('Y-m-d'); 
                                $recievable->balance = $account->balance - $recievable_amount; 
                                if($recievable->save(false)){
                                    $account->balance -= $recievable_amount;
                                    $account->save(false); 
                                }
                            //// Credit ClientRecievable account - balance////

                            //// Credit ClientPayable account + balance////
                                $ClientPayableAcc = new Entry();
                                $account = $clientPayable;
                                $ClientPayableAcc->transaction_id = $start->id; 
                                $ClientPayableAcc->account_id = $account->id; 
                                $ClientPayableAcc->is_depit = 'no'; 
                                $ClientPayableAcc->amount = $payable_amount; 
                                $ClientPayableAcc->description = "Client pay back for returning products been sold this invoice"; 
                                $ClientPayableAcc->date = date('Y-m-d'); 
                                $ClientPayableAcc->balance = $account->balance + $payable_amount; 
                                if($ClientPayableAcc->save(false)){
                                    $account->balance += $payable_amount;
                                    $account->save(false); 
                                }
                            //// Credit ClientPayable account + balance////
                            }elseif ($payableAll) {
                            //// Credit ClientPayable account + balance////
                                $ClientPayableAcc = new Entry();
                                $account = $clientPayable;
                                $ClientPayableAcc->transaction_id = $start->id; 
                                $ClientPayableAcc->account_id = $account->id; 
                                $ClientPayableAcc->is_depit = 'no'; 
                                $ClientPayableAcc->amount = $payable_amount; 
                                $ClientPayableAcc->description = "Client pay back for returning products been sold this invoice"; 
                                $ClientPayableAcc->date = date('Y-m-d'); 
                                $ClientPayableAcc->balance = $account->balance + $payable_amount; 
                                if($ClientPayableAcc->save(false)){
                                    $account->balance += $payable_amount;
                                    $account->save(false); 
                                }
                            //// Credit ClientPayable account + balance////
                            }elseif ($recievableAll){
                            //// Credit ClientRecievable account - balance////
                                $recievable = new Entry();
                                $account = $clientRecievable;
                                $recievable->transaction_id = $start->id; 
                                $recievable->account_id = $account->id; 
                                $recievable->is_depit = 'no'; 
                                $recievable->amount = $recievable_amount; 
                                $recievable->description = "Substracting sold items"; 
                                $recievable->date = date('Y-m-d'); 
                                $recievable->balance = $account->balance - $recievable_amount; 
                                if($recievable->save(false)){
                                    $account->balance -= $recievable_amount;
                                    $account->save(false); 
                                }
                            //// Credit ClientRecievable account - balance////
                            }

                            if ($paid) {
                                $payment->transaction_id = $start->id;
                                $payment->save(false);
                            }

                            $model->transaction_id = $start->id;
                            $model->save(false);
                        }
                    //// Accounts and entry transcation  ////
                    
                }
            }////end of if ($valid)

            if ($clientRedirct) {
                //// we want to show you how to pay back
                Yii::$app->getSession()->setFlash('success', ['type' => 'success']);
                Yii::$app->getSession()->setFlash('warning', ['type' => 'warning']);
                return $this->redirect(['client/view', 'id' => $model->client_id]);
            }

            Yii::$app->getSession()->setFlash('success', ['type' => 'success']);
            return $this->redirect(['view', 'id' => $model->id]);
            
        }////if($model->load(Yii::$app->request->post()))

        return $this->renderAjax('return', [
            'model' => $model,
            // 'inventory' => $inventory,
            'modelsItem' => (empty($modelsItem)) ? [new InvoiceProduct] : $modelsItem
        ]);
    }

    /**
     * Deletes an existing Invoices model.
     * If deletion is successful, the browser will be redirected to the 'index' page.
     * @param integer $id
     * @return mixed
     */
    public function actionDelete($id)
    {
        $this->findModel($id)->delete();

        return $this->redirect(['index']);
    }

    /**
     * Finds the Invoices model based on its primary key value.
     * If the model is not found, a 404 HTTP exception will be thrown.
     * @param integer $id
     * @return Invoices the loaded model
     * @throws NotFoundHttpException if the model cannot be found
     */
    protected function findModel($id)
    {
        if (($model = Invoices::findOne($id)) !== null) {
            return $model;
        }

        throw new NotFoundHttpException(Yii::t('app', 'The requested page does not exist.'));
    }
}
