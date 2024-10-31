<?php
  
  $__strings = array(
    "no_paymentspring_connection" => __( "Could not connect to PaymentSpring. Please contact the site administrator.", "gf_paymentspring" ) ,
    "card_not_charged" => __( "Your card could not be charged.", "gf_paymentspring" ),
    "invalid_amount" => __( "Invalid purchase amount.", "gf_paymentspring" ),
    "card_charged_and_site_error" => __( "Your card has been charged, but there was an unrelated error. Do not resubmit the form. Please contact the site administrator.", "gf_paymentspring" ),
    "invalid_amount_field_configuration" => __( "Amount field configured incorrectly.", "gf_paymentspring" ),
    "credit_card_test_mode" =>__( "Credit Card", "gravityforms" ) . "<span class='gfield_required'>" . __( "[TEST MODE]", "gf_paymentspring" ) . "</span>",
    "no_paymentspring_token" => __( "A PaymentSpring token could not be created.", "gf_paymentspring" )
  );



  function getString($key, $append = null, $appendWithBreak = true){
    global $__strings;

    if(isset($__strings[$key])){
      $string = $__strings[$key];

      if($append && $appendWithBreak){
        return $string . "<br/>" . $append;
      }else if($append){
        return $string . " " . $append;
      }

      return $string;
    }else{
      return null;
    }
  }

