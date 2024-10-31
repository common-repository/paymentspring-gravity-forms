<?php
  require_once("class-payment-spring-transaction.php");

  class PaymentSpringFormHandler{

    private static $paymentResponse = null;
    private static $customerId = null;
    private static $form = null;
    private static $cc_field = null;

    public $validation_result = null;

    public function linkWithGF() {
      add_filter( "gform_field_content", array( $this, "block_card_field" ), 10, 5 );
      add_filter( "gform_field_content", array( $this, "test_mode_message" ), 10, 5 );
      add_filter( "gform_register_init_scripts", array( $this, "inject_card_tokenizing_js" ), 10, 3 );
      add_filter( "gform_pre_validation", array( $this, "remove_cc_field_requirement" ), 10, 1 );
      add_filter( "gform_validation", array( $this, "validate_form" ), PHP_INT_MAX, 1 );
      add_filter( "gform_validation_message", array( $this, "card_charged_message" ), 10, 2 );
      add_filter( "gform_entry_post_save", array( $this, "process_transaction" ), 10, 2 );
      add_filter( "gform_entry_meta", array( $this, "account_mode_meta" ), 10, 2 );
      add_filter( 'gform_product_info', array($this, 'fix_plan_name'), 10, 3 );
      add_filter( 'gform_save_field_value', array($this, "set_customer_id_field"), 10, 4);
    }

    // This will get executed once a customer submits their order 
    public function validate_form ( $validation_result ) {
      $this->validation_result = $validation_result;

      self::$form = &$this->validation_result["form"];
      if (!$this->isPaymentSpringForm()) {
        return $this->validation_result;
      }

      self::$cc_field = $this->get_credit_card_field();

      if ($this->isInvalidPaymentSpringForm()){
        return $this->validation_result;
      }

      $ps_fields = $this->getReadableCCFields(); 
      $ps_fields["token"] = rgpost( "token_id" );
      if(!$this->hasCorrectFormConfiguration($ps_fields["token"])){
        return $this->validation_result;
      }

      $this->setPaymentSpringResponse($ps_fields);
      return $this->validation_result;
    }

    public function isPaymentSpringForm () {
      if(self::$cc_field){
        return true;
      }else{
        $cc_field = $this->get_credit_card_field();
        return $this->isPaymentSpringField($cc_field);
      }
    }

    public function set_customer_id_field($value, $entry, $field, $form){
      $customerOnly = rgar(get_option('gf_paymentspring_account'), "customer_only");
      if($customerOnly){
        $customer_field = $this->get_field_by_id(self::$form, rgar(self::$cc_field, "field_paymentspring_customer"));
        if($field == $customer_field){
          return self::$customerId;
        }else{
          return $value;
        }
      }else{
        return $value;
      }
    }

    public function isPaymentSpringField ($field) {
      return rgar( $field, "field_paymentspring_card" ) == true;
    } 

    public function get_credit_card_field ($form = null) {
      if(!$form){$form = self::$form;} 
      return $this->get_field_by_key($form, "creditcard", "type");
    }
  
    public function &get_field_by_id( &$form, $id ) {
      return $this->get_field_by_key($form, $id, "id");
    }
  
    public function &get_field_by_key(&$form, $id, $key){
      if($form["fields"]){
        foreach ( $form["fields"] as &$field ) {
          if ($field[$key] == $id ) {
            return $field;
          }
        }
      }
      $null = null;
      return $null;
    }

    // For fields with multiple inputs, i.e. product fields with quantity 
    public function &get_deep_field_by_id(&$form, $id){
      foreach ( $form["fields"] as &$field ) {
        if(strpos( $id, "." ) !== false && isset($field["inputs"])){
          foreach($field["inputs"] as $sub_field){
            if($sub_field['id'] == $id){
              return $sub_field;
            }
          }
        }else{
          return $this->get_field_by_id($form, $id);
        }
      }
      $null = null;
      return $null;
    }


    // Add scripts to get token from PaymentSpring for CC 
    public function inject_card_tokenizing_js ( $form, $field_values, $is_ajax ) {
      $cc_field = $this->get_credit_card_field( $form );
      if ( $this->isPaymentSpringField( $cc_field ) ) {
        $paymentSpringJS = file_get_contents( PAYMENT_SPRING_GF_PATH . "/js/paymentspring.js" );
        $keyInjector = str_replace( 
          array("{\$form_id}", "{\$cc_field_id}", "{\$public_key}"), 
          array($form["id"], $cc_field["id"], \PaymentSpring\PaymentSpring::$publicKey),
          file_get_contents(PAYMENT_SPRING_GF_PATH . "/js/form_filter.js") 
        );

        GFFormDisplay::add_init_script($form["id"], "gf_paymentspring_api", GFFormDisplay::ON_PAGE_RENDER, $paymentSpringJS);
        GFFormDisplay::add_init_script(
          $form["id"], 
          "gf_paymentspring_validator", 
          GFFormDisplay::ON_PAGE_RENDER, 
          $keyInjector
        );
      }
      return $form;
    }
  
    public function account_mode_meta($entry_meta, $form_id){
      $forms = RGFormsModel::get_form_meta_by_id( $form_id );
      if ( $this->isPaymentSpringForm( $forms[0] ) ) {
        // The below key corresponds to meta_key in the wp_rg_lead_meta table
        $entry_meta["gf_paymentspring_transaction_mode"] = array(
          "label" => __( "Transaction Mode", "gf_paymentspring" ),
          "is_numeric" => false,
          "is_default_column" => false
        );
      }
      return $entry_meta;
    }

    public function process_transaction($entry, $form){
      if ( $this->isPaymentSpringForm( $form ) ) {
        $cc_field = $this->get_credit_card_field($form);
        if ( !$cc_field->is_field_hidden ) {
          self::$paymentResponse->processTransaction($entry, $form, $cc_field);
        }
      }
      return $entry;
    }

    /*
     * With using the dropdown options for selecting plans, we need to parse the value so we get all of the correct information 
     */
    public function fix_plan_name( $product_info, $form, $lead ) {
      foreach ( $product_info['products'] as $key => &$product ) {
        $field = GFFormsModel::get_field( $form, $key );
        if ( is_object( $field ) ) {
          if(is_array($field->choices)){ 
            // Set the text field to product name for use later on
            $product['name'] = $field->choices[0]["text"];
          }
        }
      }
      return $product_info;
    }

    public function block_card_field ( $input, $field, $value, $lead_id, $form_id ) {
      if ( $field["type"] == "creditcard" and $this->isPaymentSpringField( $field ) and $lead_id == 0 ) {
        // Strip out name="input_X.X" attributes from credit card field.
        $regex = "/name\s*=\s*[\"']input_{$field['id']}\.\d+.*?[\"']/";
        return preg_replace($regex, "", $input);
      }
      else {
        return $input;
      }
    }

    // Adds "[TEST MODE]" to the CC field
    public function test_mode_message ( $input, $field, $value, $lead_id, $form_id ) {
      if ( $field["type"] == "creditcard" && $this->isPaymentSpringField( $field ) && $lead_id == 0 ) {
        $options = get_option( "gf_paymentspring_account" );
        if ( $options["mode"] == "test" ) {
          return str_replace(__( "Credit Card", "gravityforms" ), getString("credit_card_test_mode"), $input );
        }
      }
      return $input;
    }

    public function remove_cc_field_requirement ( $form ) {
      self::$form = $form;
      $cc_field = $this->get_credit_card_field();
      if ( $this->isPaymentSpringField( $cc_field ) ) {
        $cc_field["isRequired"] = false;
      }
      return $form;
    }

    public function card_charged_message ( $validation_message ) {
      if ( !empty( self::$paymentResponse->response ) ) {
        return PaymentSpringGravityForms::$twigEngine->render(
          "form_handler/validation_error.twig", 
          array("message" => getString("card_charged_and_site_error"))
        );
      }
      return $validation_message;
    }

    /* 
     * Private Functions 
     */

    /* Charge Functions */

    private function chargeHandler($submittedFields){
      if($submittedFields["plan_subscription"]){
        return $this->handleSubscriptionCharge($submittedFields);
      }else{
        return $this->handleSingleCharge($submittedFields);
      }
    }

    private function handleSubscriptionCharge($submittedFields){
      // Get Settings
      $oneTimeChargesEnabled = rgar(get_option('gf_paymentspring_account'), "allow_one_time_charges");
      $planOverridesEnabled = rgar(get_option('gf_paymentspring_account'), "customers_can_override_plan_amount");
      $sendReceipts = rgar(get_option('gf_paymentspring_account'), "send_receipts");
      $receiptTemplate = rgar(get_option('gf_paymentspring_account'), "receipt_template_id");

      // Setup Call
      $subscriptionOptions = array("bill_immediately" => "true");
      $subscriptionId = $this->getSubscriptionId($submittedFields["plan_subscription"]);

      $this->setAndCreateCustomer($submittedFields);

      if($submittedFields["single_charge"] && $oneTimeChargesEnabled){
        // Only charge once if customer selects option and setting is enabled
        $subscriptionOptions["ends_after"] = 1;
      }

      if($submittedFields["subscription_amount_override"] && $planOverridesEnabled){
        # Convert to amount in cents
        $subscriptionOptions["amount"] = floatval($submittedFields["subscription_amount_override"]) * 100;
      }

      if ($sendReceipts) {
        array_merge(
          $subscriptionOptions,
          array(
            'send_receipt' => $sendReceipts,
            'receipt_template_id' => $receiptTemplate
          )
        );
      }

      return \PaymentSpring\Plan::subscribeCustomer($subscriptionId, PaymentSpringFormHandler::$customerId, $subscriptionOptions);
    }

    private function setAndCreateCustomer($submittedFields){
      if (isset($submittedFields['email_address'])) {
        $submittedFields['email'] = $submittedFields['email_address'];
      }

      if(!PaymentSpringFormHandler::$customerId){
        $submittedFields['default_receipts'] = rgar(get_option('gf_paymentspring_account'), "send_receipts");
        $customer = \PaymentSpring\Customer::createCustomer($submittedFields);

        if(isset($customer->id)){
          PaymentSpringFormHandler::$customerId = $customer->id;
        }
      }        
    }

    private function getSubscriptionId($subscription){
      // Will pluck the plan ID in both of these scenarios:
      // $5 (sandbox)(190549)
      // $5 (190549)
      $getSubscriptionId = preg_match_all('/\(([^)]+\d)\)/', $subscription, $subscriptionMatches);
      // array(
      //   0=>array(
      //     0=>(190549)
      //   ),
      //   1=> array(
      //     0=>190549
      //   )
      // )
      return end(end($subscriptionMatches));
    }

    private function handleSingleCharge($submittedFields){
      $amount = $submittedFields["amount"]; 
      $quantity = $submittedFields["quantity"]; 
      $token = $submittedFields["token"];
      $emailAddress = $submittedFields["email_address"];
      $submittedFields["email"] = $submittedFields["email_address"];
      $sendReceipts = rgar(get_option('gf_paymentspring_account'), "send_receipts");

      if($quantity){ 
        $totalAmount = intval($amount) * intval($quantity);
      }else{
        $totalAmount = $amount;
      }

      $submittedFields["description"] = __("Payment made via Gravity Forms");
      $createCustomer = rgar(get_option('gf_paymentspring_account'), "create_customer_on_one_time_purchase");
      $customerOnly = rgar(get_option('gf_paymentspring_account'), "customer_only");
      $receiptTemplate = rgar(get_option('gf_paymentspring_account'), "receipt_template_id");

      if($createCustomer == "true" || ($customerOnly == "true" && !$amount)){
        if(!PaymentSpringFormHandler::$customerId){
          $customer = \PaymentSpring\Customer::createCustomer($submittedFields);
          if(isset($customer->id)){
            PaymentSpringFormHandler::$customerId = $customer->id;
          }
        }else{
          $customerId = PaymentSpringFormHandler::$customerId;
          PaymentSpring::makeRequest("customers/$customerId", array("email" => $emailAddress), true);
        }

        self::$customerId = PaymentSpringFormHandler::$customerId; 

        // If there's no amount and we got this far, that means we're just creating a user.
        if($amount){
          $chargeParams = array(
            "description" => $submittedFields["description"], 
            "email_address" =>  $emailAddress, 
            "send_receipt" => $sendReceipts
          );

          if($receiptTemplate && $sendReceipts){
            $chargeParams = array_merge($chargeParams, array("receipt_template_id" => $receiptTemplate));
          }

          return \PaymentSpring\Charge::chargeCustomer(PaymentSpringFormHandler::$customerId, $totalAmount, $chargeParams);
        }
      }else{
        $chargeParams = array(
          "first_name" => $submittedFields["first_name"],
          "last_name" => $submittedFields["last_name"],
          "address_1" => $submittedFields["address_1"],
          "address_2" => $submittedFields["address_2"],
          "city" => $submittedFields["city"],
          "state" => $submittedFields["state"],
          "zip" => $submittedFields["zip"],
          "phone" => $submittedFields["phone"],
          "fax" => $submittedFields["fax"],
          "website" => $submittedFields["website"],
          "company" => $submittedFields["company"],
          "email_address" => $submittedFields["email_address"], 
          "token" => $submittedFields["token"],
          "email" => $submittedFields["email"],
          "description" => $submittedFields["description"],
          "send_receipt" => $sendReceipts,
        );

        if($receiptTemplate && $sendReceipts){
          $chargeParams = array_merge($chargeParams, array("receipt_template_id" => $receiptTemplate));
        }

        return \PaymentSpring\Charge::chargeCard($chargeParams, $totalAmount);
      }
    }

    /* Other Helper Functions */

    private function getAmountInCents($amount){
      if ( strpos( $amount, "." ) !== false || strpos( $amount, "," ) !== false ) {
        // Deformat the amount string and convert to cents
        $amount = preg_replace( "/[^0-9]/", "", $amount );
      }
      else {
        // Convert total field to cents (alread non-formated string) 
        $amount = intval( $amount * 100 );
      }
      return $amount;
    }

    // Strip out the "field_paymentspring_" from each param
    private function getReadableCCFields(){
      $fields = PaymentSpringGravityForms::paymentSpringFields();
      foreach($fields as $key => $value) {
        $id = rgar(self::$cc_field, "field_paymentspring_{$key}");
        if ($id) {
          $fields[$key] = rgpost( "input_" . str_replace( ".", "_", $id ) );
        }
        else {
          $fields[$key] = null;
        }
      }
      return $fields;
    }

    private function isInvalidPaymentSpringForm(){
      $current_page = rgpost( "gform_source_page_number_" . self::$form["id"] );
      return $this->validation_result["is_valid"] == false || 
        self::$cc_field == false || 
        $current_page != self::$cc_field["pageNumber"] ||
        RGFormsModel::is_field_hidden( self::$form, self::$cc_field, array()); 
    }

    // Throw an error if we don't actually have an amount to process
    private function hasCorrectFormConfiguration($token_id){
      // We can proceed with this form if a user has given us a customer, subscription or an amount to charge.
      // In the case of a customer, we won't charge the user, we only create the customer. 
      if (!$this->amountOrSubscriptionOrCustomerFieldExists()) {
        $this->returnValidationError(self::$cc_field, getString("invalid_amount_field_configuration"));
        return false;
      }
      if ( $token_id == false ) {
        $this->returnValidationError(self::$cc_field, getString("no_paymentspring_token"));
        return false;
      }
      return true;
    }

    private function amountOrSubscriptionOrCustomerFieldExists(){
      $amount_field = $this->get_deep_field_by_id(self::$form, rgar(self::$cc_field, "field_paymentspring_amount"));
      $subscription_field = $this->get_field_by_id(self::$form, rgar(self::$cc_field, "field_paymentspring_plan_subscription"));
      $customer_field = false;
      $create_customer_single_charge = rgar(get_option('gf_paymentspring_account'), "customer_only");
      if($create_customer_single_charge == "true"){
        $customer_field = $this->get_field_by_id(self::$form, rgar(self::$cc_field, "field_paymentspring_customer"));
      }
      return ($amount_field || $subscription_field || $customer_field);
    } 

    // Calls to chargeHandler and returns any success or error messages 
    private function setPaymentSpringResponse($ps_fields){
      $ps_fields["amount"] = $this->getAmountInCents($ps_fields["amount"]);
      $amount = $ps_fields["amount"];
      $subscription = $ps_fields["plan_subscription"];
      $customer = $ps_fields["customer"];
      if ((!$amount && !$subscription && !$customer) || $amount < 0 ){
        $amount_field = $this->get_deep_field_by_id(self::$form, rgar(self::$cc_field, "field_paymentspring_amount"));
        $this->returnValidationError($amount_field, getString("invalid_amount"));
        return null;
      }
      $response = $this->chargeHandler( $ps_fields );
      if (is_wp_error($response)) {
        $this->returnValidationError(self::$cc_field, getString("no_paymentspring_connection", $response->get_error_message()));
        return null;
      }
      if (isset($response->errors)) {
        $this->returnValidationError(self::$cc_field, getString("card_not_charged", $this->format_json_errors( $response->errors )));
        return null;
      }
      self::$paymentResponse = new PaymentSpringTransaction($response);
    }

    private function returnValidationError(&$failedField, $errorMessage){
      $this->validation_result["is_valid"] = false;
      $failedField["failed_validation"] = true;
      $failedField["validation_message"] = $errorMessage;
      return $this->validation_result;
    }

    private function format_json_errors ( $errors ) {
      $str = "";
      foreach ( $errors as $error ) {
        $str .= "Code " . $error->code . " : " . $error->message . "<br />";
      }
      return $str;
    }
  }

