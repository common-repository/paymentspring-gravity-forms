<?php

  // This class is initialized on every payment for referencing the payment response 
  
  class PaymentSpringTransaction{
    public $response, $transaction;
    
    public function __construct($response){
      $this->response = $response;
      $this->setTransactionInResponse();
    }

    public function processTransaction($entry, $form, $creditCardField){
      $this->addTransactionDetailsToEntry($entry);
      $this->addCreditCardLast4($form, $entry, $creditCardField);

      $options = get_option( "gf_paymentspring_account" );
      gform_update_meta( $entry["id"], "gf_paymentspring_transaction_mode", $options["mode"] );

      return $entry; 
    }

    private function addTransactionDetailsToEntry($entry){
      $entry["payment_status"] = $this->transaction->status;
      $entry["payment_date"] = $this->transaction->created_at;
      $entry["transaction_id"] = $this->transaction->id;
      $entry["payment_amount"] = $this->transaction->amount_settled / 100;
      $entry["payment_method"] = "paymentspring";
      $entry["is_fulfilled"] = ($this->transaction->status == "SETTLED");
      if($this->response->class == "subscription"){
        $entry["transaction_type"] = 2; 
      }else{
        $entry["transaction_type"] = 1;
      }
      GFAPI::update_entry( $entry );
    }

    private function addCreditCardLast4($form, $entry, $creditCardField){
      $entry[$creditCardField["id"] . ".1"] = $this->transaction->card_number;
      $cardNumberId = $creditCardField["id"] . ".1";
      GFFormsModel::update_lead_field_value($form, $entry, $creditCardField, 0, $cardNumberId, $this->transaction->card_number);
    }

    private function setTransactionInResponse(){
      if ( empty( $this->response ) ) {
        return;
      }
      // Sometimes the response is a transaction, other times it will contain the transaction
      // Ergo, we always want to set the PS transaction to be the transaction in this class
      if( !isset($this->response->transaction) && $this->response->class == "transaction"){
        $this->transaction = $this->response;
      }else if(isset($this->response->transaction)){
        $this->transaction = $this->response->transaction; 
      }else{
        return;
      }
    }

  } 
